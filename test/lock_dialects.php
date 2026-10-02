<?php
/** Offline PG branch checks with a DoliDB test double, not a live PG test. */
if (PHP_SAPI !== 'cli') {
	exit(2);
}
require_once __DIR__.'/../class/takeposguardlock.class.php';
$conf = (object) array('entity' => 1);

class LockDialectDb
{
	public $type = 'pgsql';
	public $transaction_opened = 0;
	public $database_host = 'localhost';
	public $database_name = 'test';
	public $lasterrno = '';
	public $statements = array();
	public $owned = false;
	public $deny = false;
	public function prefix() { return 'llx_'; }
	public function escape($value) { return str_replace("'", "''", $value); }
	public function free($result) {}
	public function fetch_object($result) { return $result; }
	public function affected_rows($result) { return 1; }
	public function query($sql, $savepoint = 0)
	{
		$this->statements[] = $sql;
		if (strpos($sql, 'FROM llx_facture') !== false) { return (object) array('rowid' => 1); }
		if (strpos($sql, 'AS session_id') !== false) { return (object) array('session_id' => 123); }
		if (strpos($sql, 'pg_try_advisory_lock(') !== false) {
			$this->owned = !$this->deny;
			return (object) array('acquired' => $this->owned ? 1 : 0);
		}
		if (strpos($sql, 'FROM pg_locks') !== false) { return (object) array('owned' => $this->owned ? 1 : 0); }
		if (strpos($sql, 'pg_advisory_unlock(') !== false) {
			$this->owned = false;
			return (object) array('released' => 1);
		}
		return (object) array();
	}
}
$checks = 0;
function dialectCheck($value, $label)
{
	global $checks;
	if (!$value) { fwrite(STDERR, $label.PHP_EOL); exit(1); }
	$checks++;
}
$db = new LockDialectDb();
$lock = new TakeposguardLock($db);
$token = '12345678-1234-4234-8234-123456789abc';
dialectCheck($lock->acquire(1, $token, 120) === TakeposguardLock::ACQUIRED, 'PG acquisition');
dialectCheck($lock->isHeld(), 'PG ownership query');
$db->transaction_opened = 1;
dialectCheck(!$lock->release() && $db->owned, 'PG transaction release refusal');
$db->transaction_opened = 0;
dialectCheck($lock->release(), 'PG release');
$sql = implode("\n", $db->statements);
dialectCheck(strpos($sql, "CURRENT_TIMESTAMP + INTERVAL '120 seconds'") !== false, 'PG database-clock expiration');
dialectCheck(strpos($sql, 'objsubid = 2') !== false && strpos($sql, '::oid') !== false, 'PG two-integer advisory ownership');
preg_match('/pg_try_advisory_lock\((-?[0-9]+), (-?[0-9]+)\)/', $sql, $keys);
dialectCheck(count($keys) === 3 && (float) $keys[1] >= -2147483648 && (float) $keys[1] <= 2147483647
	&& (float) $keys[2] >= -2147483648 && (float) $keys[2] <= 2147483647, 'Signed PG keys');
dialectCheck(!preg_match('/\b(BEGIN|COMMIT|ROLLBACK|GET_LOCK|DATE_ADD)\b/', $sql), 'PG branch leaves transactions intact');
$db->deny = true;
dialectCheck($lock->acquire(1, $token, 120) === TakeposguardLock::BUSY, 'PG busy result');
$db->type = 'sqlite3';
$count = count($db->statements);
dialectCheck($lock->acquire(1, $token, 120) === TakeposguardLock::ERROR && count($db->statements) === $count, 'Unsupported engine fails before queries');
$db->type = 'mysqli';
$db->database_host = 'p:localhost';
dialectCheck($lock->acquire(1, $token, 120) === TakeposguardLock::ERROR && count($db->statements) === $count, 'Persistent mysqli session refused');
echo $checks.' lock dialect checks passed (test double; no PostgreSQL server).'.PHP_EOL;
