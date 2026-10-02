<?php
/* Copyright (C) 2026 Fred Omega Junior
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Entity-scoped persistence. This is not a payment endpoint or a lock manager.
 * Callers must enforce rights and hold the invoice lock before recording or
 * completing attempts. No transaction is opened/committed by this class.
 */
class TakeposguardStorage
{
	const PROCESSING = 'PROCESSING';
	const SUCCESS = 'SUCCESS';
	const FAILED = 'FAILED';
	const BLOCKED = 'BLOCKED';

	/** @var DoliDB */
	protected $db;
	/** @var int Current entity, fixed for the lifetime of this repository */
	protected $entity;
	/** @var string Stable technical code; no SQL or sensitive values */
	public $error = '';

	/** @param DoliDB $db Database handler */
	public function __construct($db)
	{
		global $conf;
		if (empty($conf->entity) || (int) $conf->entity < 1) {
			throw new InvalidArgumentException('TakeposguardEntityRequired');
		}
		$this->db = $db;
		$this->entity = (int) $conf->entity;
	}

	/** @return string|false Canonical random UUID v4, or false */
	public static function normalizeToken($token)
	{
		if (!is_string($token) || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD', $token)) {
			return false;
		}
		return strtolower($token);
	}

	/** @return object|null|false Attempt, not found, or error */
	public function fetchAttempt($invoiceId, $token)
	{
		$this->error = '';
		$token = self::normalizeToken($token);
		if ((int) $invoiceId < 1 || $token === false) {
			return $this->fail('TakeposguardInvalidIdentity');
		}
		return $this->fetchOne('SELECT * FROM '.MAIN_DB_PREFIX.'takeposguard_payment_attempt'
			.$this->scope($invoiceId, $token));
	}

	/** @return object|null|false Entity-scoped token owner, including another invoice */
	public function fetchTokenOwner($token)
	{
		$this->error = '';
		$token = self::normalizeToken($token);
		if ($token === false) {
			return $this->fail('TakeposguardInvalidIdentity');
		}
		return $this->fetchOne('SELECT fk_invoice, status FROM '.MAIN_DB_PREFIX.'takeposguard_payment_attempt'
			.' WHERE entity = '.$this->entity.' AND operation_token = '.$this->quote($token));
	}

	/** @return object|null|false Committed initial validation evidence, never PROCESSING */
	public function fetchSuccessfulValidation($invoiceId)
	{
		$this->error = '';
		if ((int) $invoiceId < 1) {
			return $this->fail('TakeposguardInvalidIdentity');
		}
		return $this->fetchOne('SELECT rowid FROM '.MAIN_DB_PREFIX.'takeposguard_payment_attempt'
			.$this->scope($invoiceId)." AND status = 'SUCCESS' AND invoice_status_before = 0"
			.' AND invoice_status_after IN (1, 2) AND date_completed IS NOT NULL ORDER BY rowid DESC');
	}

	/** @return object|null|false Lock metadata; does not acquire or recover a lock */
	public function fetchInvoiceLock($invoiceId)
	{
		$this->error = '';
		if ((int) $invoiceId < 1) {
			return $this->fail('TakeposguardInvalidIdentity');
		}
		return $this->fetchOne('SELECT * FROM '.MAIN_DB_PREFIX.'takeposguard_invoice_lock'.$this->scope($invoiceId));
	}

	/** @return array|false Native snapshot, read only after native transaction ends */
	public function getInvoiceSnapshot($invoiceId)
	{
		$this->error = '';
		return (int) $invoiceId > 0 ? $this->invoiceSnapshot($invoiceId) : $this->fail('TakeposguardInvalidIdentity');
	}

	/** @return object|null|false New linked payment, never an unrelated entity/invoice */
	public function fetchNewPayment($invoiceId, $lastPaymentBefore)
	{
		$this->error = '';
		if ((int) $invoiceId < 1 || (int) $lastPaymentBefore < 0) {
			return $this->fail('TakeposguardInvalidIdentity');
		}
		return $this->fetchOne('SELECT p.rowid, pf.amount FROM '.MAIN_DB_PREFIX.'paiement p'
			.' INNER JOIN '.MAIN_DB_PREFIX.'paiement_facture pf ON pf.fk_paiement = p.rowid'
			.' WHERE p.entity = '.$this->entity.' AND pf.fk_facture = '.((int) $invoiceId)
			.' AND p.rowid > '.((int) $lastPaymentBefore).' ORDER BY p.rowid DESC');
	}

	/**
	 * Record a processing attempt atomically; uniqueness is enforced by the DB.
	 * Metadata contains claimed terminal/payment_code/requested_amount/IP/UA.
	 * Amount/mode are never used to create a payment or to decide success here.
	 * @return int|false Inserted rowid, or error (including a duplicate token)
	 */
	public function createProcessing($invoiceId, $token, $userId, array $metadata = array())
	{
		$this->error = '';
		$token = self::normalizeToken($token);
		if ((int) $invoiceId < 1 || (int) $userId < 1 || $token === false) {
			return $this->fail('TakeposguardInvalidIdentity');
		}
		$terminal = isset($metadata['terminal']) ? $metadata['terminal'] : '';
		$code = isset($metadata['payment_code']) ? $metadata['payment_code'] : '';
		if (!is_string($terminal) || !preg_match('/^[a-zA-Z0-9_-]{0,32}$/D', $terminal)
			|| !is_string($code) || !preg_match('/^[a-zA-Z0-9]{0,16}$/D', $code)) {
			return $this->fail('TakeposguardInvalidMetadata');
		}
		$amount = isset($metadata['requested_amount']) ? $this->money($metadata['requested_amount']) : 'NULL';
		if ($amount === false) {
			return $this->fail('TakeposguardInvalidAmount');
		}
		$ip = isset($metadata['ip_address']) ? $metadata['ip_address'] : '';
		$agent = isset($metadata['user_agent']) ? $metadata['user_agent'] : '';
		if (!is_string($ip) || ($ip !== '' && !filter_var($ip, FILTER_VALIDATE_IP))
			|| !is_string($agent) || !preg_match('//u', $agent)) {
			return $this->fail('TakeposguardInvalidMetadata');
		}
		$snapshot = $this->invoiceSnapshot($invoiceId);
		if ($snapshot === false) {
			return false;
		}
		$remain = $this->money($snapshot['remain']);
		if ($remain === false) {
			return $this->fail('TakeposguardInvalidSnapshot');
		}
		$now = $this->quote($this->db->idate(dol_now()));
		$sql = 'INSERT INTO '.MAIN_DB_PREFIX.'takeposguard_payment_attempt'
			.' (entity, fk_invoice, operation_token, fk_user, terminal, payment_code, requested_amount,'
			.' remain_before, invoice_status_before, payment_count_before, last_payment_before, status, datec, tms, ip_address, user_agent) VALUES ('
			.$this->entity.', '.((int) $invoiceId).', '.$this->quote($token).', '.((int) $userId).', '
			.$this->quote($terminal).', '.$this->quote($code).', '.$amount.', '.$remain.', '.((int) $snapshot['status']).', '
			.((int) $snapshot['payment_count']).', '.((int) $snapshot['last_payment']).', '
			.$this->quote(self::PROCESSING).', '.$now.', '.$now.', '.$this->quote($ip).', '.$this->quote($this->boundedText($agent));
		$sql .= ')';
		// Savepoint lets PostgreSQL survive an expected uniqueness conflict in a caller transaction.
		if (!$this->db->query($sql, 1)) {
			return $this->fail('TakeposguardAttemptInsertFailed');
		}
		$id = (int) $this->db->last_insert_id(MAIN_DB_PREFIX.'takeposguard_payment_attempt');
		return $id > 0 ? $id : $this->fail('TakeposguardAttemptInsertFailed');
	}

	/**
	 * Store a caller-confirmed outcome once. Does not infer native commit success.
	 * The caller must have reconciled the outcome under the invoice lock.
	 * Outcome accepts fk_payment, error_code and a nonsensitive error_message.
	 * Actual amount is read from paiement_facture, never from the caller.
	 * @return bool False on invalid input, SQL failure or a non-PROCESSING attempt
	 */
	public function completeAttempt($invoiceId, $token, $status, array $outcome)
	{
		$this->error = '';
		$token = self::normalizeToken($token);
		if ((int) $invoiceId < 1 || $token === false
			|| !in_array($status, array(self::SUCCESS, self::FAILED, self::BLOCKED), true)) {
			return $this->fail('TakeposguardInvalidOutcome');
		}
		$actual = 'NULL';
		$errorCode = isset($outcome['error_code']) ? $outcome['error_code'] : '';
		$message = isset($outcome['error_message']) ? $outcome['error_message'] : '';
		if (!is_string($errorCode) || !preg_match('/^[a-zA-Z0-9_]{0,64}$/D', $errorCode)
			|| !is_string($message) || !preg_match('//u', $message)) {
			return $this->fail('TakeposguardInvalidOutcome');
		}
		$snapshot = $this->invoiceSnapshot($invoiceId);
		if ($snapshot === false) {
			return false;
		}
		$remain = $this->money($snapshot['remain']);
		if ($remain === false) {
			return $this->fail('TakeposguardInvalidSnapshot');
		}
		$paymentId = isset($outcome['fk_payment']) ? (int) $outcome['fk_payment'] : 0;
		if ($paymentId < 0) {
			return $this->fail('TakeposguardInvalidOutcome');
		}
		if ($paymentId > 0) {
			$payment = $this->fetchOne('SELECT p.rowid, pf.amount FROM '.MAIN_DB_PREFIX.'paiement p'
				.' INNER JOIN '.MAIN_DB_PREFIX.'paiement_facture pf ON pf.fk_paiement = p.rowid'
				.' WHERE p.entity = '.$this->entity.' AND pf.fk_facture = '.((int) $invoiceId).' AND p.rowid = '.$paymentId);
			if (!$payment) {
				return $this->fail('TakeposguardInvalidPayment');
			}
			$actual = $this->money($payment->amount);
			if ($actual === false) {
				return $this->fail('TakeposguardInvalidPayment');
			}
		}
		$now = $this->quote($this->db->idate(dol_now()));
		$sql = 'UPDATE '.MAIN_DB_PREFIX.'takeposguard_payment_attempt SET status = '.$this->quote($status)
			.', remain_after = '.$remain.', invoice_status_after = '.((int) $snapshot['status'])
			.', actual_amount = '.$actual.', fk_payment = '.($paymentId > 0 ? $paymentId : 'NULL')
			.', date_completed = '.$now.', tms = '.$now.', error_code = '.$this->quote($errorCode)
			.', error_message = '.$this->quote($this->boundedText($message))
			.$this->scope($invoiceId, $token).' AND status = '.$this->quote(self::PROCESSING);
		$result = $this->db->query($sql);
		if (!$result) {
			return $this->fail('TakeposguardAttemptUpdateFailed');
		}
		return $this->db->affected_rows($result) === 1 ? true : $this->fail('TakeposguardAttemptNotProcessing');
	}

	/** @return array|false Authoritative snapshot, not values supplied by a browser */
	protected function invoiceSnapshot($invoiceId)
	{
		$invoice = $this->fetchOne('SELECT rowid FROM '.MAIN_DB_PREFIX.'facture'
			.' WHERE rowid = '.((int) $invoiceId).' AND entity = '.$this->entity." AND module_source = 'takepos'");
		if (!$invoice) {
			return $this->fail('TakeposguardInvoiceUnavailable');
		}
		try {
			$object = $this->loadInvoice($invoiceId);
		} catch (Throwable $error) {
			return $this->fail('TakeposguardSnapshotFailed');
		}
		if (!$object || (int) $object->id !== (int) $invoiceId
			|| (int) $object->entity !== $this->entity || $object->module_source !== 'takepos') {
			return $this->fail('TakeposguardInvoiceUnavailable');
		}
		try {
			$remain = $object->getRemainToPay();
		} catch (Throwable $error) {
			return $this->fail('TakeposguardSnapshotFailed');
		}
		// getRemainToPay returns a negative value legitimately for credit notes.
		if (!empty($object->error)) {
			return $this->fail('TakeposguardSnapshotFailed');
		}
		$payments = $this->fetchOne('SELECT COUNT(*) AS payment_count, MAX(p.rowid) AS last_payment'
			.' FROM '.MAIN_DB_PREFIX.'paiement p INNER JOIN '.MAIN_DB_PREFIX.'paiement_facture pf ON pf.fk_paiement = p.rowid'
			.' WHERE p.entity = '.$this->entity.' AND pf.fk_facture = '.((int) $invoiceId));
		if (!$payments) {
			return $this->fail('TakeposguardSnapshotFailed');
		}
		return array('status' => (int) $object->status, 'remain' => $remain,
			'payment_count' => (int) $payments->payment_count, 'last_payment' => (int) $payments->last_payment);
	}

	/** @return Facture|false Reload using Dolibarr's native object API */
	protected function loadInvoice($invoiceId)
	{
		require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
		$invoice = new Facture($this->db);
		return $invoice->fetch((int) $invoiceId) > 0 ? $invoice : false;
	}

	/** @return string Entity-scoped predicate */
	private function scope($invoiceId, $token = null)
	{
		$sql = ' WHERE entity = '.$this->entity.' AND fk_invoice = '.((int) $invoiceId);
		return $token === null ? $sql : $sql.' AND operation_token = '.$this->quote($token);
	}

	/** @return object|null|false One row, absence or SQL error */
	private function fetchOne($sql)
	{
		$result = $this->db->query($sql.$this->db->plimit(1));
		if (!$result) {
			return $this->fail('TakeposguardStorageReadFailed');
		}
		$row = $this->db->fetch_object($result);
		$this->db->free($result);
		return $row ? $row : null;
	}

	/** @return string|false Safe SQL number (24,8); no locale-dependent formatting */
	private function money($value)
	{
		if (is_float($value)) {
			if (!is_finite($value)) {
				return false;
			}
			$value = sprintf('%.8F', $value);
		}
		if (!is_string($value) && !is_int($value)) {
			return false;
		}
		$value = (string) $value;
		return preg_match('/^-?(?:0|[1-9][0-9]{0,15})(?:\.[0-9]{1,8})?$/D', $value) ? $value : false;
	}

	/** @return string Escaped SQL string */
	private function quote($value)
	{
		return "'".$this->db->escape($value)."'";
	}

	/** @return string Bounded printable UTF-8 metadata */
	private function boundedText($value)
	{
		return dol_substr(preg_replace('/[\x00-\x1f\x7f]/', '', $value), 0, 255);
	}

	/** @return false Record a stable code, never expose the raw SQL error */
	private function fail($code)
	{
		$this->error = $code;
		return false;
	}
}
