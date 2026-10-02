<?php
/* Copyright (C) 2026 Fred Omega Junior
 * SPDX-License-Identifier: GPL-3.0-or-later
 */
require_once __DIR__.'/takeposguardlock.class.php';
require_once __DIR__.'/takeposguardfinalizer.class.php';

/** Deterministic recovery only; ambiguous effects require administrator review. */
class TakeposguardRecovery
{
	private $db;
	private $storage;
	public function __construct($db, $storage)
	{
		$this->db = $db;
		$this->storage = $storage;
	}

	/** @return bool Replace an expired owner only after committed-effect reconciliation */
	public function recover($lock, $invoiceId)
	{
		$previous = $lock->previousToken;
		if (!$lock->holdsInvoice($invoiceId, $previous) || (int) $this->db->transaction_opened !== 0) {
			return false;
		}
		$attempt = $this->storage->fetchAttempt($invoiceId, $previous);
		if ($attempt === false) {
			return false;
		}
		// No attempt means native processing was never authorized after acquisition.
		if ($attempt !== null) {
			$finalizer = new TakeposguardFinalizer($this->db, $this->storage);
			$status = $finalizer->reconcile($invoiceId, $previous, $lock);
			if (!in_array($status, array('SUCCESS', 'FAILED'), true)) {
				return false;
			}
		}
		return $lock->confirmRecovery($previous);
	}
}
