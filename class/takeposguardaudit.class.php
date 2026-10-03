<?php
/* Copyright (C) 2026 Fred Omega Junior; GPL-3.0-or-later */
require_once __DIR__.'/takeposguardstorage.class.php';

/** Entity-scoped, bounded queries for authorized audit/maintenance pages. */
class TakeposguardAudit extends TakeposguardStorage
{
	public function attempts($status = '', $invoiceId = 0, $offset = 0, $limit = 50, $filters = array(), $sortfield = 'datec', $sortorder = 'DESC')
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
		$sql .= $this->filters($filters, false);
		return $this->rows($sql.$this->ordering($sortfield, $sortorder, false), $offset, $limit);
	}

	public function locks($offset = 0, $limit = 50, $filters = array(), $sortfield = 'expires_at', $sortorder = 'ASC')
	{
		return $this->rows('SELECT l.*, a.status, f.ref AS invoice_ref,'
			.' CASE WHEN l.expires_at <= CURRENT_TIMESTAMP THEN 1 ELSE 0 END AS expired'
			.' FROM '.MAIN_DB_PREFIX.'takeposguard_invoice_lock l'
			.' LEFT JOIN '.MAIN_DB_PREFIX.'takeposguard_payment_attempt a ON a.entity=l.entity'
			.' AND a.fk_invoice=l.fk_invoice AND a.operation_token=l.operation_token'
			.' LEFT JOIN '.MAIN_DB_PREFIX.'facture f ON f.rowid=l.fk_invoice AND f.entity=l.entity'
			.' WHERE l.entity='.$this->entity.$this->filters($filters, true)
			.$this->ordering($sortfield, $sortorder, true), $offset, $limit);
	}

	/** Shared fixed map: request keys never become SQL identifiers. */
	public static function columns($locks = false)
	{
		return $locks ? array('invoice' => 'f.ref', 'datec' => 'l.datec', 'expires_at' => 'l.expires_at',
			'status' => 'a.status', 'operation_token' => 'l.operation_token')
			: array('datec' => 'a.datec', 'invoice' => 'f.ref', 'terminal' => 'a.terminal', 'user_login' => 'u.login',
				'payment_code' => 'a.payment_code', 'requested_amount' => 'a.requested_amount', 'actual_amount' => 'a.actual_amount',
				'status' => 'a.status', 'operation_token' => 'a.operation_token', 'remain_before' => 'a.remain_before',
				'remain_after' => 'a.remain_after', 'error' => 'a.error_code');
	}

	private function filters($filters, $locks)
	{
		$sql = '';
		foreach (self::columns($locks) as $key => $column) {
			$value = isset($filters[$key]) ? trim((string) $filters[$key]) : '';
			if ($value === '') { continue; }
			if ($key === 'status') {
				if ($value === 'ORPHAN' && $locks) { $sql .= ' AND a.rowid IS NULL'; }
				elseif (in_array($value, array('PROCESSING', 'SUCCESS', 'FAILED', 'BLOCKED'), true)) {
					$sql .= " AND a.status='".$this->db->escape($value)."'";
				} else { $sql .= ' AND 1=0'; }
			} elseif (in_array($key, array('datec', 'expires_at'), true)) {
				if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $date)
					&& checkdate((int) $date[2], (int) $date[3], (int) $date[1])) {
					$sql .= " AND ".$column.">='".$this->db->escape($value)." 00:00:00' AND ".$column."<='".$this->db->escape($value)." 23:59:59'";
				} else { $sql .= ' AND 1=0'; }
			} elseif (in_array($key, array('requested_amount', 'actual_amount', 'remain_before', 'remain_after'), true)) {
				if (preg_match('/^-?\d+(?:[.,]\d+)?$/D', $value)) { $sql .= ' AND '.$column.'='.((float) str_replace(',', '.', $value)); }
				else { $sql .= ' AND 1=0'; }
			} else {
				$sql .= natural_search($key === 'error' ? array('a.error_code', 'a.error_message') : $column, $value);
			}
		}
		return $sql;
	}

	private function ordering($field, $order, $locks)
	{
		$columns = self::columns($locks);
		if (!isset($columns[$field])) { $field = $locks ? 'expires_at' : 'datec'; }
		return ' ORDER BY '.$columns[$field].(strtoupper($order) === 'ASC' ? ' ASC' : ' DESC')
			.', '.($locks ? 'l' : 'a').'.rowid DESC';
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
