<?php
/* Copyright (C) 2026 Fred Omega Junior
 * SPDX-License-Identifier: GPL-3.0-or-later
 */
require_once __DIR__.'/takeposguardlock.class.php';
require_once __DIR__.'/takeposguardpaymentpolicy.class.php';
require_once __DIR__.'/takeposguardrecovery.class.php';

/** Guard the native action without opening a transaction or creating payments. */
class ActionsTakeposguard
{
	/** @var DoliDB */
	public $db;
	public $error = '';
	public $errors = array();
	public $results = array();
	public $resprints = '';
	/** @var TakeposguardLock|null Kept alive throughout native processing */
	protected $paymentLock;
	/** @var TakeposguardPaymentPolicy|null */
	protected $paymentPolicy;
	private $guardedInvoiceId = 0;
	private $operationToken = '';
	private $paymentStorage;
	private $finalized = false;

	/** @param DoliDB $db Database handler */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/** @return int 0 continues native action; 1 replaces a rejected action */
	public function doActions($parameters, &$object, &$action, $hookmanager)
	{
		global $conf, $user;
		$contexts = explode(':', isset($parameters['context']) ? $parameters['context'] : '');
		if (!in_array('takeposinvoice', $contexts, true) || $action !== 'valid'
			|| !getDolGlobalInt('TAKEPOSGUARD_ENABLE')) {
			return 0;
		}
		$this->error = '';
		if (!is_object($user) || empty($user->id) || !empty($user->socid)
			|| !$user->hasRight('takepos', 'run') || !$user->hasRight('facture', 'creer')) {
			return $this->block('TakeposguardPaymentDenied', $object);
		}
		// Audit/maintenance rights are deliberately not required to run the guard.
		if (!is_object($object) || empty($object->id) || (int) $object->entity !== (int) $conf->entity
			|| $object->module_source !== 'takepos') {
			return $this->block('TakeposguardPaymentInvoiceUnavailable', $object);
		}
		$token = TakeposguardStorage::normalizeToken(GETPOST('takeposguard_token', 'none'));
		if ($token === false) {
			return $this->block('TakeposguardPaymentTokenRequired', $object);
		}
		// A second hook invocation in this request must not authorize another action.
		if ($this->paymentLock !== null) {
			return $this->block('TakeposguardPaymentBusy', $object);
		}
		$lock = null;
		$policy = null;
		$persisting = false;
		try {
			$storage = $this->newStorage();
			$lock = $this->newLock();
			$result = $lock->acquire((int) $object->id, $token, getDolGlobalInt('TAKEPOSGUARD_LOCK_TIMEOUT', 120));
			if ($result === TakeposguardLock::RECOVERY_REQUIRED) {
				$recovery = new TakeposguardRecovery($this->db, $storage);
				if (!$recovery->recover($lock, (int) $object->id)) {
					$lock->abandon();
					return $this->block('TakeposguardPaymentRecoveryRequired', $object);
				}
				$result = TakeposguardLock::ACQUIRED;
			}
			if ($result !== TakeposguardLock::ACQUIRED) {
				return $this->block($result === TakeposguardLock::BUSY
					? 'TakeposguardPaymentBusy' : 'TakeposguardPaymentTechnicalFailure', $object);
			}
			$previous = $storage->fetchTokenOwner($token);
			if ($previous === false || $previous !== null) {
				$lock->release();
				if ($previous && (int) $previous->fk_invoice === (int) $object->id) {
					$this->emitOutcome($previous->status, $token);
					$object->fetch((int) $object->id);
				}
				return $this->block($previous === false ? 'TakeposguardPaymentTechnicalFailure'
					: 'TakeposguardPaymentTokenUsed', $object);
			}
			// Refresh the native object only after exclusion; never trust its old status.
			if ($object->fetch((int) $object->id) <= 0 || (int) $object->entity !== (int) $conf->entity
				|| $object->module_source !== 'takepos') {
				$lock->release();
				return $this->block('TakeposguardPaymentInvoiceUnavailable', $object);
			}
			$policy = new TakeposguardPaymentPolicy();
			$policyError = $policy->checkInvoice($object);
			if ($policyError !== '') {
				$lock->release();
				return $this->block($policyError, $object);
			}
			$terminal = isset($_SESSION['takeposterminal']) ? (string) $_SESSION['takeposterminal'] : '';
			$suppressStock = (int) $object->status === TakeposguardPaymentPolicy::VALIDATED
				&& isModEnabled('stock') && isModEnabled('productbatch')
				&& getDolGlobalString('CASHDESK_NO_DECREASE_STOCK'.$terminal) != '1';
			if ($suppressStock) {
				$validation = $storage->fetchSuccessfulValidation((int) $object->id);
				if (!$validation) {
					$lock->release();
					return $this->block($validation === false ? 'TakeposguardPaymentTechnicalFailure'
						: 'TakeposguardPaymentStockUnproven', $object);
				}
			}
			$metadata = array(
				'terminal' => $terminal,
				'payment_code' => GETPOST('pay', 'aZ09'),
				// Claims for audit only. Native TakePOS remains responsible for payment validation.
				'requested_amount' => GETPOSTFLOAT('amount'),
			);
			// If insertion throws, retain metadata: the durable INSERT may have succeeded.
			$persisting = true;
			if ($storage->createProcessing((int) $object->id, $token, (int) $user->id, $metadata) === false) {
				$lock->release();
				return $this->block($storage->error === 'TakeposguardAttemptLimitReached'
					? 'TakeposguardAttemptLimitReached' : 'TakeposguardPaymentTechnicalFailure', $object);
			}
			if ($suppressStock && !$policy->suppressStock($terminal)) {
				$lock->abandon();
				return $this->block('TakeposguardPaymentTechnicalFailure', $object);
			}
			$this->paymentLock = $lock;
			$this->paymentPolicy = $policy;
			$this->guardedInvoiceId = (int) $object->id;
			$this->operationToken = $token;
			$this->paymentStorage = $storage;
			// Keep native fragment output buffered so post-commit headers can still be sent.
			if (PHP_SAPI !== 'cli' && !headers_sent()) {
				ob_start();
			}
			register_shutdown_function(array($this, 'shutdownPayment'));
			$this->results['takeposguard'] = array('invoice_id' => (int) $object->id, 'operation_token' => $token);
			$this->diagnostic('TakeposguardPaymentAccepted');
			return 0;
		} catch (Throwable $exception) {
			if ($policy !== null) {
				$policy->restore();
			}
			if ($lock !== null) {
				try {
					if ($persisting) {
						$lock->abandon();
					} else {
						$lock->release();
					}
				} catch (Throwable $cleanupError) {
					// Fail closed; session closure releases advisory ownership, not history.
				}
			}
			return $this->block('TakeposguardPaymentTechnicalFailure', $object);
		}
	}

	/** @return int Rendering hook after native commit/rollback */
	public function completeTakePosInvoiceHeader($parameters, &$object, &$action, $hookmanager)
	{
		$contexts = explode(':', isset($parameters['context']) ? $parameters['context'] : '');
		if ($this->paymentPolicy !== null && in_array('takeposinvoice', $contexts, true)
			&& is_object($object) && (int) $object->id === $this->guardedInvoiceId) {
			$this->paymentPolicy->restore();
			$this->finalizePayment();
			// Do not leave the rendering object with a pre-transaction status/balance.
			if ($this->finalized) {
				$object->fetch($this->guardedInvoiceId);
			}
		}
		return 0;
	}

	/** @return string|false Persist the outcome before releasing the owned lock */
	public function finalizePayment()
	{
		if ($this->finalized || !$this->paymentLock || empty($this->db->connected)
			|| (int) $this->db->transaction_opened !== 0) {
			return false;
		}
		try {
			$finalizer = new TakeposguardFinalizer($this->db, $this->paymentStorage);
			$status = $finalizer->reconcile($this->guardedInvoiceId, $this->operationToken, $this->paymentLock);
			if (in_array($status, array('SUCCESS', 'FAILED'), true) && $this->paymentLock->release()) {
				$this->finalized = true;
				$this->emitOutcome($status, $this->operationToken);
				$this->diagnostic('TakeposguardFinalized'.$status);
				return $status;
			}
			// Preserve ownership metadata for a later request; never delete ambiguous history.
			$this->paymentLock->abandon();
			$this->finalized = true;
			$this->diagnostic('TakeposguardPaymentRecoveryRequired');
		} catch (Throwable $exception) {
			try {
				$this->paymentLock->abandon();
			} catch (Throwable $cleanupError) {
				// Preserve the durable owner if the connection/transaction cannot be used.
			}
			$this->diagnostic('TakeposguardPaymentTechnicalFailure');
		}
		return false;
	}

	/** @return void Native dol_shutdown may already have closed/rolled back this session */
	public function shutdownPayment()
	{
		if ($this->paymentPolicy) {
			$this->paymentPolicy->restore();
		}
		// With an open transaction or closed connection, expiry and later recovery apply.
		$this->finalizePayment();
	}

	/** @return void Correlate immutable DB outcomes, never infer from HTTP completion */
	private function emitOutcome($status, $token)
	{
		if (in_array($status, array('SUCCESS', 'FAILED'), true) && !headers_sent()) {
			header('X-Takeposguard-Status: '.$status);
			header('X-Takeposguard-Token: '.$token);
		}
	}

	/** @return TakeposguardLock Factory also permits isolated orchestration tests */
	protected function newLock()
	{
		return new TakeposguardLock($this->db);
	}

	/** @return TakeposguardStorage */
	protected function newStorage()
	{
		return new TakeposguardStorage($this->db);
	}

	/** @return int Replace native action, leaving subsequent hooks/views available */
	private function block($code, $object)
	{
		global $langs;
		$this->error = $code;
		$langs->load('takeposguard@takeposguard');
		$message = $langs->trans($code);
		setEventMessages($message, null, 'errors');
		// TakePOS uses an AJAX fragment without the usual page message renderer.
		// invoice.php does not print HookManager::resPrint for doActions().
		print '<div class="error takeposguard-error" role="alert">'.dol_escape_htmltag($message).'</div>';
		$this->diagnostic($code);
		return 1;
	}

	/** @return void No tokens, amounts, user data, SQL or exception text in logs */
	private function diagnostic($code)
	{
		if (getDolGlobalInt('TAKEPOSGUARD_DEBUG_LOG')) {
			dol_syslog('Takeposguard '.$code, LOG_DEBUG);
		}
	}
}
