<?php
/* Copyright (C) 2026 Fred Omega Junior
 * SPDX-License-Identifier: GPL-3.0-or-later
 */
require_once __DIR__.'/takeposguardlock.class.php';

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
		$persisting = false;
		try {
			$lock = $this->newLock();
			$result = $lock->acquire((int) $object->id, $token, getDolGlobalInt('TAKEPOSGUARD_LOCK_TIMEOUT', 120));
			if ($result === TakeposguardLock::RECOVERY_REQUIRED) {
				// Expiration alone never proves an interrupted payment can be retried.
				$lock->abandon();
				return $this->block('TakeposguardPaymentRecoveryRequired', $object);
			}
			if ($result !== TakeposguardLock::ACQUIRED) {
				return $this->block($result === TakeposguardLock::BUSY
					? 'TakeposguardPaymentBusy' : 'TakeposguardPaymentTechnicalFailure', $object);
			}
			$storage = $this->newStorage();
			$previous = $storage->fetchTokenOwner($token);
			if ($previous === false || $previous !== null) {
				$lock->release();
				return $this->block($previous === false ? 'TakeposguardPaymentTechnicalFailure'
					: 'TakeposguardPaymentTokenUsed', $object);
			}
			// Refresh the native object only after exclusion; never trust its old status.
			if ($object->fetch((int) $object->id) <= 0 || (int) $object->entity !== (int) $conf->entity
				|| $object->module_source !== 'takepos') {
				$lock->release();
				return $this->block('TakeposguardPaymentInvoiceUnavailable', $object);
			}
			$metadata = array(
				'terminal' => isset($_SESSION['takeposterminal']) ? (string) $_SESSION['takeposterminal'] : '',
				'payment_code' => GETPOST('pay', 'aZ09'),
				// Claims for audit only. Native TakePOS remains responsible for payment validation.
				'requested_amount' => GETPOSTFLOAT('amount'),
			);
			// If insertion throws, retain metadata: the durable INSERT may have succeeded.
			$persisting = true;
			if ($storage->createProcessing((int) $object->id, $token, (int) $user->id, $metadata) === false) {
				$lock->release();
				return $this->block('TakeposguardPaymentTechnicalFailure', $object);
			}
			$this->paymentLock = $lock;
			$this->results['takeposguard'] = array('invoice_id' => (int) $object->id, 'operation_token' => $token);
			$this->diagnostic('TakeposguardPaymentAccepted');
			// Completion/reconciliation is a subsequent stage. Do not infer success here.
			return 0;
		} catch (Throwable $exception) {
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
