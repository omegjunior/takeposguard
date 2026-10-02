<?php
/* Copyright (C) 2026 Fred Omega Junior; GPL-3.0-or-later */
require_once __DIR__.'/takeposguardrecovery.class.php';

/** Never delete an idempotence key or displace a live native session. */
class TakeposguardMaintenance extends TakeposguardStorage
{
	public $output = '';
	/** @return bool Recover the exact expired owner submitted by the administrator */
	public function releaseExpired($invoiceId, $expectedToken)
	{
		$this->error = '';
		$expectedToken = self::normalizeToken($expectedToken);
		if (!$expectedToken || (int) $invoiceId < 1 || !getDolGlobalInt('TAKEPOSGUARD_ENABLE')) {
			$this->error = 'TakeposguardMaintenanceRecoveryDenied';
			return false;
		}
		$result = $this->db->query('SELECT operation_token FROM '.MAIN_DB_PREFIX.'takeposguard_invoice_lock'
			.' WHERE entity='.$this->entity.' AND fk_invoice='.((int) $invoiceId)
			." AND operation_token='".$this->db->escape($expectedToken)."' AND expires_at <= CURRENT_TIMESTAMP".$this->db->plimit(1));
		if (!$result || !$this->db->fetch_object($result)) {
			$this->error = 'TakeposguardMaintenanceRecoveryDenied';
			return false;
		}
		$lock = $this->newLock();
		try {
			$state = $lock->acquire((int) $invoiceId, $expectedToken, 120);
			if ($state === TakeposguardLock::RECOVERY_REQUIRED && $lock->previousToken === $expectedToken) {
				$recovery = new TakeposguardRecovery($this->db, $this);
				if (!$recovery->recover($lock, (int) $invoiceId)) {
					$lock->abandon();
					$this->error = 'TakeposguardMaintenanceAmbiguous';
					return false;
				}
				$state = TakeposguardLock::ACQUIRED;
			}
			if ($state === TakeposguardLock::ACQUIRED && $lock->release()) {
				dol_syslog('TakeposguardMaintenance: expired owner reconciled and released', LOG_INFO);
				return true;
			}
			$lock->abandon();
		} catch (Throwable $exception) {
			try { $lock->abandon(); } catch (Throwable $cleanupError) { /* Preserve metadata. */ }
		}
		$this->error = 'TakeposguardMaintenanceRecoveryDenied';
		return false;
	}

	/** @return int|false Redact old detailed history; preserve token/result/stock evidence */
	public function purgeDetails($days)
	{
		$this->error = '';
		if (!is_int($days) || $days < 1 || $days > 3650 || (int) $this->db->transaction_opened !== 0) {
			$this->error = 'TakeposguardMaintenancePurgeDenied';
			return false;
		}
		$cutoff = $this->db->escape($this->db->idate(dol_now() - $days * 86400));
		$where = ' WHERE entity='.$this->entity." AND status IN ('SUCCESS','FAILED')"
			." AND date_completed < '".$cutoff."' AND datec < '".$cutoff."'"
			.' AND (terminal IS NOT NULL OR payment_code IS NOT NULL OR requested_amount IS NOT NULL'
			.' OR actual_amount IS NOT NULL OR ip_address IS NOT NULL OR user_agent IS NOT NULL OR error_message IS NOT NULL)'
			.' AND NOT EXISTS (SELECT 1 FROM '.MAIN_DB_PREFIX.'takeposguard_invoice_lock l'
			.' WHERE l.entity='.MAIN_DB_PREFIX.'takeposguard_payment_attempt.entity'
			.' AND l.fk_invoice='.MAIN_DB_PREFIX.'takeposguard_payment_attempt.fk_invoice)';
		$selected = $this->db->query('SELECT rowid FROM '.MAIN_DB_PREFIX.'takeposguard_payment_attempt'.$where
			.' ORDER BY date_completed, rowid'.$this->db->plimit(500));
		if (!$selected) {
			$this->error = 'TakeposguardMaintenancePurgeDenied';
			return false;
		}
		$ids = array();
		while ($row = $this->db->fetch_object($selected)) { $ids[] = (int) $row->rowid; }
		$this->db->free($selected);
		if (!$ids) { return 0; }
		$sql = 'UPDATE '.MAIN_DB_PREFIX.'takeposguard_payment_attempt SET terminal=NULL, payment_code=NULL,'
			.' requested_amount=NULL, actual_amount=NULL, ip_address=NULL, user_agent=NULL, error_message=NULL'
			.$where.' AND rowid IN ('.implode(',', $ids).')';
		$result = $this->db->query($sql);
		if (!$result) {
			$this->error = 'TakeposguardMaintenancePurgeDenied';
			return false;
		}
		$count = (int) $this->db->affected_rows($result);
		dol_syslog('TakeposguardMaintenance: old detail redaction count='.$count, LOG_INFO);
		return $count;
	}

	/** Native CronJob entrypoint, disabled by default in the module descriptor. */
	public function doScheduledJob($parameters = '')
	{
		global $user, $langs;
		require_once __DIR__.'/../lib/takeposguard_ui.lib.php';
		if (!isModEnabled('takeposguard') || !is_object($user) || !takeposguardCanAccess($user, 'maintenance')) {
			$this->error = 'TakeposguardMaintenancePurgeDenied';
			return 1;
		}
		$count = $this->purgeDetails(getDolGlobalInt('TAKEPOSGUARD_HISTORY_DAYS', 90));
		if (is_object($langs)) {
			$langs->load('takeposguard@takeposguard');
			$this->output = $count === false ? $langs->trans($this->error) : $langs->trans('TakeposguardMaintenancePurged', $count);
		} else {
			$this->output = $count === false ? $this->error : 'TakeposguardMaintenancePurged: '.$count;
		}
		return $count === false ? 1 : 0;
	}

	protected function newLock()
	{
		return new TakeposguardLock($this->db);
	}
}
