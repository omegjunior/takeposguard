<?php
/* Copyright (C) 2026 Fred Omega Junior
 * SPDX-License-Identifier: GPL-3.0-or-later
 */
require_once __DIR__.'/takeposguardrecovery.class.php';

/** Resolve an existing attempt without invoking native payment or an external provider. */
class TakeposguardAttemptService
{
	private $db;
	private $storage;
	public function __construct($db, $storage)
	{
		$this->db = $db;
		$this->storage = $storage;
	}

	/** @return string Minimal result; unauthorized and absent tokens are indistinguishable */
	public function resolve($token, $userId, $maintenance = false)
	{
		$token = TakeposguardStorage::normalizeToken($token);
		if (!$token) {
			return 'UNKNOWN';
		}
		$owner = $this->storage->fetchTokenOwner($token);
		if (!$owner) {
			return 'UNKNOWN';
		}
		$id = (int) $owner->fk_invoice;
		$attempt = $this->storage->fetchAttempt($id, $token);
		if (!$attempt || (!$maintenance && (int) $attempt->fk_user !== (int) $userId)) {
			return 'UNKNOWN';
		}
		if ($attempt->status !== 'PROCESSING') {
			return $attempt->status;
		}
		$lock = $this->newLock();
		try {
			$result = $lock->acquire($id, $token, getDolGlobalInt('TAKEPOSGUARD_LOCK_TIMEOUT', 120));
			if ($result === TakeposguardLock::BUSY) {
				return 'PROCESSING';
			}
			if ($result === TakeposguardLock::RECOVERY_REQUIRED) {
				$recovery = new TakeposguardRecovery($this->db, $this->storage);
				if (!$recovery->recover($lock, $id)) {
					$lock->abandon();
					$attempt = $this->storage->fetchAttempt($id, $token);
					return $attempt && $attempt->status === 'BLOCKED' ? 'BLOCKED' : 'PROCESSING';
				}
				$result = TakeposguardLock::ACQUIRED;
			}
			if ($result !== TakeposguardLock::ACQUIRED) {
				return 'UNKNOWN';
			}
			$finalizer = new TakeposguardFinalizer($this->db, $this->storage);
			$status = $finalizer->reconcile($id, $token, $lock);
			if (in_array($status, array('SUCCESS', 'FAILED'), true) && $lock->release()) {
				return $status;
			}
			$lock->abandon();
			return $status === 'BLOCKED' ? 'BLOCKED' : 'PROCESSING';
		} catch (Throwable $exception) {
			try { $lock->abandon(); } catch (Throwable $cleanupError) { /* Keep metadata for recovery. */ }
			return 'UNKNOWN';
		}
	}

	protected function newLock()
	{
		return new TakeposguardLock($this->db);
	}
}
