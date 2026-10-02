<?php
/* Copyright (C) 2026 Fred Omega Junior
 * SPDX-License-Identifier: GPL-3.0-or-later
 */
require_once __DIR__.'/takeposguardstorage.class.php';

/**
 * Session advisory lock plus durable ownership metadata. Uses the native
 * connection: no begin/commit/rollback and no separate PG connection to reuse.
 * This class does not authorize a payment or reconcile an interrupted attempt.
 */
class TakeposguardLock
{
	const ERROR = -1;
	const BUSY = 0;
	const ACQUIRED = 1;
	const RECOVERY_REQUIRED = 2;

	/** @var DoliDB */
	private $db;
	/** @var int */
	private $entity;
	/** @var string Fix the table namespace for this object's lifetime */
	private $prefix;
	/** @var int */
	private $invoiceId = 0;
	/** @var string */
	private $token = '';
	/** @var int */
	private $ttl = 0;
	/** @var string */
	private $key = '';
	/** @var int[] PostgreSQL signed key pair */
	private $pgKeys = array();
	/** @var string */
	private $registryKey = '';
	/** @var bool */
	private $held = false;
	/** @var bool */
	private $recovery = false;
	/** @var string Interrupted token; reconcile before replacing it */
	public $previousToken = '';
	/** @var string Stable nonsensitive technical error */
	public $error = '';
	/** @var array Prevent recursive advisory acquisition on the same session */
	private static $owners = array();

	/** @param DoliDB $db Native TakePOS connection (nonpersistent session) */
	public function __construct($db)
	{
		global $conf;
		if (empty($conf->entity) || (int) $conf->entity < 1) {
			throw new InvalidArgumentException('TakeposguardEntityRequired');
		}
		$this->db = $db;
		$this->entity = (int) $conf->entity;
		$this->prefix = $db->prefix();
	}

	/**
	 * ACQUIRED alone permits the caller to proceed. RECOVERY_REQUIRED holds the
	 * advisory lock but requires reconciliation before confirmRecovery().
	 * A live session is never displaced, even if its metadata has expired.
	 * @return int One of the four constants above
	 */
	public function acquire($invoiceId, $token, $ttl = 120)
	{
		$this->error = '';
		if ($this->held) {
			return $this->fail('TakeposguardLockAlreadyHeld');
		}
		$token = TakeposguardStorage::normalizeToken($token);
		if (!is_int($invoiceId) || $invoiceId < 1 || $invoiceId > 2147483647
			|| $token === false || !is_int($ttl) || $ttl < 10 || $ttl > 3600) {
			return $this->fail('TakeposguardInvalidLockIdentity');
		}
		if (!in_array($this->db->type, array('mysqli', 'pgsql'), true)) {
			return $this->fail('TakeposguardUnsupportedLockEngine');
		}
		// Do not acquire from a native transaction, or use pooled/persistent sessions.
		if ((int) $this->db->transaction_opened !== 0) {
			return $this->fail('TakeposguardLockTransactionActive');
		}
		if (strpos((string) $this->db->database_host, 'p:') === 0) {
			return $this->fail('TakeposguardPersistentConnectionUnsupported');
		}
		$this->invoiceId = $invoiceId;
		$this->token = $token;
		$this->ttl = $ttl;
		$this->previousToken = '';
		$this->recovery = false;
		$invoice = $this->one('SELECT rowid FROM '.$this->prefix.'facture WHERE rowid = '.$invoiceId
			.' AND entity = '.$this->entity." AND module_source = 'takepos'");
		if (!$invoice) {
			return $this->fail('TakeposguardInvoiceUnavailable');
		}
		$digest = hash('sha256', 'takeposguard|'.$this->db->database_name.'|'.$this->prefix.'|'.$this->entity.'|'.$invoiceId);
		$this->key = 'tpg:'.substr($digest, 0, 60); // MySQL maximum is 64 characters.
		$this->pgKeys = array(self::signedKey(substr($digest, 0, 8)), self::signedKey(substr($digest, 8, 8)));
		$result = $this->acquireAdvisory();
		if ($result !== self::ACQUIRED) {
			return $result;
		}
		// Always INSERT first. There is no unprotected SELECT-then-INSERT race.
		$sql = 'INSERT INTO '.$this->prefix.'takeposguard_invoice_lock'
			.' (entity, fk_invoice, operation_token, datec, tms, expires_at) VALUES ('
			.$this->entity.', '.$invoiceId.', '.$this->quote($token).', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, '.$this->expiration().')';
		if ($this->query($sql)) {
			return self::ACQUIRED; // Autocommitted metadata is visible before native work.
		}
		if ($this->error === 'TakeposguardLockQueryFailed' || $this->db->lasterrno !== 'DB_ERROR_RECORD_ALREADY_EXISTS') {
			return $this->cancel('TakeposguardLockInsertFailed');
		}
		$row = $this->one('SELECT operation_token, CASE WHEN expires_at <= CURRENT_TIMESTAMP THEN 1 ELSE 0 END AS expired'
			.' FROM '.$this->prefix.'takeposguard_invoice_lock'.$this->scope());
		if (!$row) {
			return $this->cancel('TakeposguardLockReadFailed');
		}
		if ((int) $row->expired !== 1) {
			return $this->cancel('TakeposguardLockBusy', self::BUSY);
		}
		$this->previousToken = $row->operation_token;
		$this->recovery = true;
		return self::RECOVERY_REQUIRED;
	}

	/**
	 * After caller reconciliation, replace only the expected expired owner.
	 * Never call this merely because a timestamp has elapsed.
	 * @return bool
	 */
	public function confirmRecovery($expectedToken)
	{
		$this->error = '';
		$expectedToken = TakeposguardStorage::normalizeToken($expectedToken);
		if (!$this->recovery || $expectedToken === false || $expectedToken !== $this->previousToken
			|| !$this->mutationAllowed()) {
			$this->error = 'TakeposguardRecoveryNotAllowed';
			return false;
		}
		$result = $this->query('UPDATE '.$this->prefix.'takeposguard_invoice_lock'
			.' SET operation_token = '.$this->quote($this->token).', datec = CURRENT_TIMESTAMP, tms = CURRENT_TIMESTAMP, expires_at = '.$this->expiration()
			.$this->scope().' AND operation_token = '.$this->quote($expectedToken).' AND expires_at <= CURRENT_TIMESTAMP');
		if (!$result || $this->db->affected_rows($result) !== 1) {
			$this->error = 'TakeposguardRecoveryUpdateFailed';
			return false;
		}
		$this->recovery = false;
		return true;
	}

	/** @return bool Advisory session ownership, including during a native transaction */
	public function isHeld()
	{
		if (!$this->held) {
			return false;
		}
		if ($this->db->type === 'mysqli') {
			$row = $this->one('SELECT CASE WHEN IS_USED_LOCK('.$this->quote($this->key).') = CONNECTION_ID() THEN 1 ELSE 0 END AS owned');
		} else {
			$first = $this->unsignedKey($this->pgKeys[0]);
			$second = $this->unsignedKey($this->pgKeys[1]);
			$row = $this->one("SELECT CASE WHEN EXISTS (SELECT 1 FROM pg_locks WHERE locktype = 'advisory' AND pid = pg_backend_pid()"
				.' AND classid = '.$first.'::oid AND objid = '.$second.'::oid AND objsubid = 2 AND granted) THEN 1 ELSE 0 END AS owned');
		}
		return $row && (int) $row->owned === 1;
	}

	/** @return bool Exact durable owner (previous token while recovery is pending) */
	public function holdsInvoice($invoiceId, $token)
	{
		$owner = $this->recovery ? $this->previousToken : $this->token;
		return (int) $invoiceId === $this->invoiceId && TakeposguardStorage::normalizeToken($token) === $owner && $this->isHeld();
	}

	/** @return bool Remove owned metadata after native completion, then unlock */
	public function release()
	{
		$this->error = '';
		if ($this->recovery || !$this->mutationAllowed()) {
			$this->error = 'TakeposguardLockReleaseNotAllowed';
			return false;
		}
		$result = $this->query('DELETE FROM '.$this->prefix.'takeposguard_invoice_lock'.$this->scope()
			.' AND operation_token = '.$this->quote($this->token));
		if (!$result || $this->db->affected_rows($result) !== 1) {
			$this->error = 'TakeposguardLockDeleteFailed';
			return false;
		}
		return $this->unlockAdvisory();
	}

	/**
	 * Preserve metadata for later reconciliation, release advisory ownership only.
	 * Refuses while a native transaction is open. On hard crash the DB session
	 * closes and releases its advisory lock; persisted metadata remains.
	 * @return bool
	 */
	public function abandon()
	{
		$this->error = '';
		if (!$this->mutationAllowed()) {
			$this->error = 'TakeposguardLockReleaseNotAllowed';
			return false;
		}
		return $this->unlockAdvisory();
	}

	/** @return int Nonblocking session lock acquisition */
	private function acquireAdvisory()
	{
		$session = $this->one($this->db->type === 'mysqli' ? 'SELECT CONNECTION_ID() AS session_id' : 'SELECT pg_backend_pid() AS session_id');
		if (!$session) {
			return $this->fail('TakeposguardLockSessionUnavailable');
		}
		// One guarded invoice per native session, including old MySQL versions
		// where acquiring a second named lock would implicitly release the first.
		$this->registryKey = $this->db->type.'|'.$session->session_id;
		if (isset(self::$owners[$this->registryKey])) {
			return $this->fail('TakeposguardLockBusy', self::BUSY);
		}
		if ($this->db->type === 'mysqli') {
			$owner = $this->one('SELECT IS_USED_LOCK('.$this->quote($this->key).') AS owner_id');
			if (!$owner) {
				return $this->fail('TakeposguardLockSessionUnavailable');
			}
			if ($owner->owner_id !== null) {
				return $this->fail('TakeposguardLockBusy', self::BUSY);
			}
			$row = $this->one('SELECT GET_LOCK('.$this->quote($this->key).', 0) AS acquired');
		} else {
			$row = $this->one('SELECT CASE WHEN pg_try_advisory_lock('.implode(', ', $this->pgKeys).') THEN 1 ELSE 0 END AS acquired');
		}
		if (!$row || $row->acquired === null) {
			return $this->fail('TakeposguardLockAcquisitionFailed');
		}
		if ((int) $row->acquired !== 1) {
			return $this->fail('TakeposguardLockBusy', self::BUSY);
		}
		$this->held = true;
		self::$owners[$this->registryKey] = true;
		return self::ACQUIRED;
	}

	/** @return bool */
	private function mutationAllowed()
	{
		return (int) $this->db->transaction_opened === 0 && $this->isHeld();
	}

	/** @return bool */
	private function unlockAdvisory()
	{
		if (!$this->isHeld()) {
			$this->error = 'TakeposguardLockOwnershipLost';
			return false;
		}
		$sql = $this->db->type === 'mysqli'
			? 'SELECT RELEASE_LOCK('.$this->quote($this->key).') AS released'
			: 'SELECT CASE WHEN pg_advisory_unlock('.implode(', ', $this->pgKeys).') THEN 1 ELSE 0 END AS released';
		$row = $this->one($sql);
		if (!$row || (int) $row->released !== 1) {
			$this->error = 'TakeposguardLockUnlockFailed';
			return false;
		}
		unset(self::$owners[$this->registryKey]);
		$this->held = false;
		return true;
	}

	/** @return int Unlock on refusal, but never erase existing ownership metadata */
	private function cancel($error, $result = self::ERROR)
	{
		if (!$this->unlockAdvisory()) {
			return self::ERROR;
		}
		return $this->fail($error, $result);
	}

	/** @return string Database clock, not the PHP/web server clock */
	private function expiration()
	{
		return $this->db->type === 'mysqli' ? 'DATE_ADD(CURRENT_TIMESTAMP, INTERVAL '.$this->ttl.' SECOND)'
			: "CURRENT_TIMESTAMP + INTERVAL '".$this->ttl." seconds'";
	}

	/** @return string */
	private function scope()
	{
		return ' WHERE entity = '.$this->entity.' AND fk_invoice = '.$this->invoiceId;
	}

	/** @return string */
	private function quote($value)
	{
		return "'".$this->db->escape($value)."'";
	}

	/** @return object|null|false */
	private function one($sql)
	{
		$result = $this->query($sql);
		if (!$result) {
			return false;
		}
		$row = $this->db->fetch_object($result);
		$this->db->free($result);
		return $row ? $row : null;
	}

	/** @return mixed Dolibarr query result; never emit driver exceptions */
	private function query($sql)
	{
		try {
			return $this->db->query($sql, 1);
		} catch (Throwable $error) {
			$this->error = 'TakeposguardLockQueryFailed';
			return false;
		}
	}

	/** @return int PostgreSQL signed 32-bit advisory key */
	private static function signedKey($hex)
	{
		$value = hexdec($hex);
		return (int) ($value > 2147483647 ? $value - 4294967296 : $value);
	}

	/** @return string PostgreSQL OID representation */
	private function unsignedKey($value)
	{
		return sprintf('%.0F', $value < 0 ? $value + 4294967296 : $value);
	}

	/** @return int */
	private function fail($error, $result = self::ERROR)
	{
		$this->error = $error;
		return $result;
	}
}
