<?php
/* Copyright (C) 2026 Fred Omega Junior
 * SPDX-License-Identifier: GPL-3.0-or-later
 */
require_once __DIR__.'/takeposguardstorage.class.php';

/** Reconcile committed native effects under exclusion; never execute a payment. */
class TakeposguardFinalizer
{
	private $db;
	private $storage;
	public $error = '';

	public function __construct($db, $storage)
	{
		$this->db = $db;
		$this->storage = $storage;
	}

	/** @return string|false Confirmed status; BLOCKED is not permission to release/retry */
	public function reconcile($invoiceId, $token, $lock)
	{
		$this->error = '';
		if (empty($this->db->connected) || (int) $this->db->transaction_opened !== 0 || !$lock->holdsInvoice($invoiceId, $token)) {
			$this->error = 'TakeposguardFinalizationNotAllowed';
			return false;
		}
		$attempt = $this->storage->fetchAttempt($invoiceId, $token);
		if (!$attempt) {
			$this->error = 'TakeposguardAttemptUnavailable';
			return false;
		}
		if (in_array($attempt->status, array('SUCCESS', 'FAILED', 'BLOCKED'), true)) {
			return $attempt->status; // Never rewrite a terminal result.
		}
		if ($attempt->status !== 'PROCESSING') {
			$this->error = 'TakeposguardInvalidOutcome';
			return false;
		}
		$after = $this->storage->getInvoiceSnapshot($invoiceId);
		if ($after === false) {
			$this->error = 'TakeposguardSnapshotFailed';
			return false;
		}
		$before = (float) $attempt->remain_before;
		$remain = (float) $after['remain'];
		if (!is_finite($before) || !is_finite($remain)) {
			$this->error = 'TakeposguardInvalidSnapshot';
			return false;
		}
		$delta = (int) $after['payment_count'] - (int) $attempt->payment_count_before;
		$lastBefore = (int) $attempt->last_payment_before;
		$lastAfter = (int) $after['last_payment'];
		$payment = null;
		if ($delta === 1 && $lastAfter > $lastBefore) {
			$payment = $this->storage->fetchNewPayment($invoiceId, $lastBefore);
			if ($payment === false) {
				$this->error = 'TakeposguardPaymentReadFailed';
				return false;
			}
		}
		$outcome = self::decide((int) $attempt->invoice_status_before, $before, (int) $after['status'], $remain,
			$delta, $lastBefore === $lastAfter, $payment && (int) $payment->rowid === $lastAfter ? (float) $payment->amount : null);
		$data = array('error_code' => $outcome === 'FAILED' ? 'NativeNoCommittedEffect' : ($outcome === 'BLOCKED' ? 'ReconciliationRequired' : ''));
		if ($outcome === 'SUCCESS' && $payment) {
			$data['fk_payment'] = (int) $payment->rowid;
		}
		if (!$this->storage->completeAttempt($invoiceId, $token, $outcome, $data)) {
			$this->error = 'TakeposguardAttemptUpdateFailed';
			return false;
		}
		return $outcome;
	}

	/** @return string Decision from DB evidence only, not claimed mode/amount or HTTP */
	public static function decide($statusBefore, $remainBefore, $statusAfter, $remainAfter, $paymentDelta, $sameLastPayment, $newAmount)
	{
		$unchanged = abs($remainBefore - $remainAfter) < 0.000000005;
		$noPayment = $paymentDelta === 0 && $sameLastPayment;
		$onePayment = $paymentDelta === 1 && !$sameLastPayment && $newAmount !== null && is_finite($newAmount)
			&& (($remainBefore > 0 && $newAmount > 0) || ($remainBefore < 0 && $newAmount < 0));
		if (!$noPayment && !$onePayment) {
			return 'BLOCKED'; // Missing/deleted or multiple payments cannot be attributed safely.
		}
		if ($statusBefore === 0 && in_array($statusAfter, array(1, 2), true)) {
			return 'SUCCESS';
		}
		$decreased = abs($remainAfter) < abs($remainBefore) - 0.000000005
			&& ($remainAfter === 0.0 || $remainBefore * $remainAfter > 0);
		if ($statusBefore === 1 && in_array($statusAfter, array(1, 2), true) && ($decreased || $onePayment)) {
			return 'SUCCESS';
		}
		if (in_array($statusBefore, array(0, 1), true) && $statusAfter === $statusBefore && $unchanged && $noPayment) {
			return 'FAILED';
		}
		return 'BLOCKED';
	}
}
