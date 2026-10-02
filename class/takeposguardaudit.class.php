<?php
/* Copyright (C) 2026 Fred Omega Junior; GPL-3.0-or-later */
require_once __DIR__.'/takeposguardstorage.class.php';

/** Entity-scoped, bounded queries for authorized audit/maintenance pages. */
class TakeposguardAudit extends TakeposguardStorage
{
	public function attempts($status = '', $invoiceId = 0, $offset = 0, $limit = 50)
	{
		if (!in_array($status, array('', 'PROCESSING', 'SUCCESS', 'FAILED', 'BLOCKED'), true)) {
			$this->error = 'TakeposguardInvalidFilter';
			return false;
		}
		$sql = 'SELECT a.*, f.ref AS invoice_ref, u.login AS user_login FROM '.MAIN_DB_PREFIX.'takeposguard_payment_attempt a'
			.' LEFT JOIN '.MAIN_DB_PREFIX.'facture f ON f.rowid=a.fk_invoice AND f.entity=a.entity'
			.' LEFT JOIN '.MAIN_DB_PREFIX.'user u ON u.rowid=a.fk_user WHERE a.entity='.$this->entity;
		if ($status !== '') {
			$sql .= " AND a.status='".$this->db->escape($status)."'";
		}
		if ((int) $invoiceId > 0) {
			$sql .= ' AND a.fk_invoice='.((int) $invoiceId);
		}
		return $this->rows($sql.' ORDER BY a.datec DESC, a.rowid DESC', $offset, $limit);
	}

	public function locks($offset = 0, $limit = 50)
	{
		return $this->rows('SELECT l.*, a.status, f.ref AS invoice_ref,'
			.' CASE WHEN l.expires_at <= CURRENT_TIMESTAMP THEN 1 ELSE 0 END AS expired'
			.' FROM '.MAIN_DB_PREFIX.'takeposguard_invoice_lock l'
			.' LEFT JOIN '.MAIN_DB_PREFIX.'takeposguard_payment_attempt a ON a.entity=l.entity'
			.' AND a.fk_invoice=l.fk_invoice AND a.operation_token=l.operation_token'
			.' LEFT JOIN '.MAIN_DB_PREFIX.'facture f ON f.rowid=l.fk_invoice AND f.entity=l.entity'
			.' WHERE l.entity='.$this->entity.' ORDER BY l.expires_at, l.rowid', $offset, $limit);
	}

	private function rows($sql, $offset, $limit)
	{
		$this->error = '';
		$result = $this->db->query($sql.$this->db->plimit(max(1, min(101, (int) $limit)), max(0, (int) $offset)));
		if (!$result) {
			$this->error = 'TakeposguardStorageReadFailed';
			return false;
		}
		$rows = array();
		while ($row = $this->db->fetch_object($result)) {
			$rows[] = $row;
		}
		$this->db->free($result);
		return $rows;
	}
}
