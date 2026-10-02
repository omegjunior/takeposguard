<?php
/**
 * php test/locks.php --mysql
 * Shared fixtures use random tpg_locktest_* tables only; never native tables.
 * Two PHP worker processes exercise genuine concurrent DB sessions.
 */
if (PHP_SAPI !== 'cli' || (!in_array('--mysql', $argv, true) && !in_array('--worker', $argv, true))) {
	fwrite(STDERR, "Usage: php test/locks.php --mysql\n");
	exit(2);
}
define('DOL_DOCUMENT_ROOT', realpath(__DIR__.'/../../..'));
require DOL_DOCUMENT_ROOT.'/conf/conf.php';
$worker = in_array('--worker', $argv, true);
$prefix = $worker ? $argv[2] : 'tpg_locktest_'.bin2hex(random_bytes(6)).'_';
if (!preg_match('/^tpg_locktest_[0-9a-f]{12}_$/D', $prefix)) {
	exit(2);
}
define('MAIN_DB_PREFIX', $prefix);
require_once DOL_DOCUMENT_ROOT.'/core/lib/functions.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once __DIR__.'/../class/takeposguardlock.class.php';
$conf = new stdClass();
$conf->entity = 1;
$conf->global = new stdClass();
$conf->modules = array();
$conf->db = new stdClass();
$conf->db->dolibarr_main_db_collation = isset($dolibarr_main_db_collation) ? $dolibarr_main_db_collation : 'utf8_unicode_ci';
function lockTestConnection()
{
	global $dolibarr_main_db_type, $dolibarr_main_db_host, $dolibarr_main_db_user,
		$dolibarr_main_db_pass, $dolibarr_main_db_name, $dolibarr_main_db_port;
	try {
		$db = getDoliDBInstance($dolibarr_main_db_type, $dolibarr_main_db_host, $dolibarr_main_db_user,
			$dolibarr_main_db_pass, $dolibarr_main_db_name, empty($dolibarr_main_db_port) ? 0 : (int) $dolibarr_main_db_port);
		if (!$db->connected) {
			throw new RuntimeException('Database unavailable');
		}
		return $db;
	} catch (Throwable $error) {
		throw new RuntimeException('Test database connection unavailable');
	}
}
function lockTestQuery($db, $sql)
{
	$result = $db->query($sql, 1);
	if (!$result) {
		throw new RuntimeException('Fixture SQL failed');
	}
	return $result;
}
function lockTestWaitFile($path)
{
	$limit = microtime(true) + 10;
	while (!is_file($path)) {
		if (microtime(true) > $limit) {
			throw new RuntimeException('Worker barrier timeout');
		}
		usleep(10000);
		clearstatcache(true, $path);
	}
}

// Worker exits without release when crash mode is selected. PHP destroys the
// nonpersistent DB session; lock metadata must remain for recovery.
if ($worker) {
	try {
		$db = lockTestConnection();
		lockTestWaitFile($argv[4]);
		$lock = new TakeposguardLock($db);
		$result = $lock->acquire(1, $argv[3], 10);
		echo json_encode(array('result' => $result, 'error' => $lock->error))."\n";
		flush();
		if ($result === TakeposguardLock::ACQUIRED) {
			if ($argv[6] === 'crash') {
				exit(0);
			}
			lockTestWaitFile($argv[5]);
			if (!$lock->release()) {
				throw new RuntimeException('Worker release failed');
			}
		}
		$db->close();
		exit(0);
	} catch (Throwable $error) {
		fwrite(STDERR, 'Worker failed: '.$error->getMessage()."\n");
		exit(1);
	}
}

$checks = 0;
function lockCheck($condition, $label)
{
	global $checks;
	if (!$condition) {
		throw new RuntimeException($label);
	}
	$checks++;
}
function lockWorker($token, $start, $stop, $mode)
{
	$command = implode(' ', array_map('escapeshellarg', array(PHP_BINARY, __FILE__, '--worker', MAIN_DB_PREFIX, $token, $start, $stop, $mode)));
	$process = proc_open($command, array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes, null, null, array('bypass_shell' => true));
	if (!is_resource($process)) {
		throw new RuntimeException('Cannot start worker');
	}
	fclose($pipes[0]);
	stream_set_blocking($pipes[1], false);
	return array($process, $pipes);
}
function lockWorkerResult($worker)
{
	$limit = microtime(true) + 10;
	$line = '';
	do {
		$chunk = fgets($worker[1][1]);
		if ($chunk !== false) {
			$line .= $chunk;
		}
		if (strpos($line, "\n") !== false) {
			$result = json_decode(trim($line), true);
			if (!is_array($result)) {
				throw new RuntimeException('Invalid worker result');
			}
			return $result['result'];
		}
		usleep(10000);
	} while (microtime(true) < $limit);
	throw new RuntimeException('Worker result timeout');
}
function lockWorkerFinish($worker)
{
	$error = stream_get_contents($worker[1][2]);
	fclose($worker[1][1]);
	fclose($worker[1][2]);
	$code = proc_close($worker[0]);
	lockCheck($code === 0 && $error === '', 'Worker exit code '.$code.' '.$error);
}

$db = null;
$second = null;
$lossDb = null;
$tables = array();
$workers = array();
$dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.$prefix;
$exitCode = 0;
try {
	if ($dolibarr_main_db_type !== 'mysqli') {
		throw new RuntimeException('This integration test requires mysqli');
	}
	$db = lockTestConnection();
	$second = lockTestConnection();
	mkdir($dir);
	foreach (array('llx_takeposguard_invoice_lock.sql', 'llx_takeposguard_invoice_lock.key.sql') as $file) {
		$tables[] = MAIN_DB_PREFIX.'takeposguard_invoice_lock';
		lockCheck(run_sql(__DIR__.'/../sql/'.$file, 1, 0, 1, '', 'none') > 0, 'Install lock fixture');
	}
	$tables[] = MAIN_DB_PREFIX.'facture';
	lockTestQuery($db, 'CREATE TABLE '.MAIN_DB_PREFIX.'facture (rowid integer PRIMARY KEY, entity integer, module_source varchar(32)) ENGINE=innodb');
	lockTestQuery($db, "INSERT INTO ".MAIN_DB_PREFIX."facture VALUES (1,1,'takepos'),(2,2,'takepos'),(3,1,'other')");
	$a = '12345678-1234-4234-8234-123456789abc';
	$b = '12345678-1234-4234-8234-123456789abd';
	$first = new TakeposguardLock($db);
	$other = new TakeposguardLock($second);
	lockCheck($first->acquire(1, $a, 10) === 1 && $first->isHeld(), 'Acquire first session');
	$row = $second->fetch_object(lockTestQuery($second, 'SELECT * FROM '.MAIN_DB_PREFIX.'takeposguard_invoice_lock WHERE entity=1 AND fk_invoice=1'));
	lockCheck($row->operation_token === $a, 'Metadata visible before native transaction');
	lockCheck($other->acquire(1, $a, 10) === 0, 'Same token concurrent busy');
	lockCheck($other->acquire(1, $b, 10) === 0, 'Different token concurrent busy');
	lockTestQuery($second, 'UPDATE '.MAIN_DB_PREFIX.'takeposguard_invoice_lock SET expires_at=DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 1 SECOND) WHERE entity=1 AND fk_invoice=1');
	lockCheck($other->acquire(1, $b, 10) === 0, 'Expired metadata cannot displace live session');
	$db->begin();
	lockCheck(!$first->release() && !$first->abandon() && $first->isHeld(), 'Cannot release during native transaction');
	$db->commit();
	lockCheck($first->isHeld(), 'Native commit does not release advisory lock');
	$db->begin();
	$db->rollback();
	lockCheck($first->isHeld(), 'Native rollback does not release advisory lock');
	$recursive = new TakeposguardLock($db);
	lockCheck($recursive->acquire(1, $b, 10) === 0, 'Same session cannot acquire recursively');
	lockCheck($first->release() && !$first->isHeld(), 'Release owned metadata and advisory lock');
	lockCheck($other->acquire(1, $b, 10) === 1 && $other->release(), 'Next voluntary attempt accepted');
	lockCheck($first->acquire(3, $a, 10) === -1 && $first->acquire(2, $a, 10) === -1, 'Non-TakePOS and foreign invoice rejected');
	lockCheck($first->acquire(1, 'invalid', 10) === -1 && $first->acquire(1, $a, 9) === -1, 'Invalid identity/lifetime rejected');
	$db->begin();
	lockCheck($first->acquire(1, $a, 10) === -1 && (int) $db->transaction_opened === 1, 'Acquire refuses an existing transaction');
	$db->rollback();
	lockCheck($first->acquire(1, $a, 10) === 1, 'Entity one acquired');
	$conf->entity = 2;
	$entityTwo = new TakeposguardLock($second);
	lockCheck($entityTwo->acquire(2, $a, 10) === 1, 'Other entity has independent lock');
	lockCheck($entityTwo->release() && $first->release(), 'Entity scopes remain fixed');
	$conf->entity = 1;
	lockCheck($first->acquire(1, $a, 10) === 1 && $first->abandon(), 'Interrupted ownership preserved');
	lockCheck($other->acquire(1, $b, 10) === 0, 'Unexpired orphan still blocks');
	lockTestQuery($db, 'UPDATE '.MAIN_DB_PREFIX.'takeposguard_invoice_lock SET expires_at=DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 1 SECOND) WHERE entity=1 AND fk_invoice=1');
	lockCheck($other->acquire(1, $b, 10) === 2 && $other->previousToken === $a && $other->isHeld(), 'Recovery requires exclusive ownership and reconciliation');
	lockCheck(!$other->release() && !$other->confirmRecovery($b), 'Cannot erase unreconciled previous owner');
	lockCheck($other->confirmRecovery($a) && $other->release(), 'Explicit recovery and release');
	// The winner retains ownership until the parent has collected both results.
	foreach (array('same' => $a, 'different' => $b) as $label => $token) {
		$start = $dir.DIRECTORY_SEPARATOR.$label.'-start';
		$stop = $dir.DIRECTORY_SEPARATOR.$label.'-stop';
		$workers = array(lockWorker($a, $start, $stop, 'normal'), lockWorker($token, $start, $stop, 'normal'));
		file_put_contents($start, 'start');
		$results = array(lockWorkerResult($workers[0]), lockWorkerResult($workers[1]));
		sort($results);
		lockCheck($results === array(0, 1), 'True parallel workers: '.$label.' token');
		file_put_contents($stop, 'stop');
		foreach ($workers as $process) {
			lockWorkerFinish($process);
		}
		$workers = array();
	}
	$start = $dir.DIRECTORY_SEPARATOR.'crash-start';
	$stop = $dir.DIRECTORY_SEPARATOR.'crash-stop';
	$workers = array(lockWorker($a, $start, $stop, 'crash'));
	file_put_contents($start, 'start');
	lockCheck(lockWorkerResult($workers[0]) === 1, 'Crash worker acquired');
	lockWorkerFinish($workers[0]);
	$workers = array();
	lockCheck($other->acquire(1, $b, 10) === 0, 'Worker exit preserves unexpired metadata');
	lockTestQuery($db, 'UPDATE '.MAIN_DB_PREFIX.'takeposguard_invoice_lock SET expires_at=DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 1 SECOND) WHERE entity=1 AND fk_invoice=1');
	lockCheck($other->acquire(1, $b, 10) === 2 && $other->confirmRecovery($a) && $other->release(), 'Session termination permits expired recovery');
	$lossDb = lockTestConnection();
	$lost = new TakeposguardLock($lossDb);
	lockCheck($lost->acquire(1, $a, 10) === 1, 'Ownership loss fixture acquired');
	lockTestQuery($lossDb, 'SELECT RELEASE_ALL_LOCKS()');
	lockTestQuery($db, 'UPDATE '.MAIN_DB_PREFIX.'takeposguard_invoice_lock SET expires_at=DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 1 SECOND) WHERE entity=1 AND fk_invoice=1');
	lockCheck($other->acquire(1, $b, 10) === 2 && $other->confirmRecovery($a), 'Replacement after ownership loss');
	lockCheck(!$lost->release(), 'Previous owner cannot erase replacement');
	$row = $db->fetch_object(lockTestQuery($db, 'SELECT operation_token FROM '.MAIN_DB_PREFIX.'takeposguard_invoice_lock WHERE entity=1 AND fk_invoice=1'));
	lockCheck($row->operation_token === $b && $other->release(), 'Replacement metadata preserved');
	lockTestQuery($db, 'DROP TABLE '.MAIN_DB_PREFIX.'takeposguard_invoice_lock');
	lockCheck($first->acquire(1, $a, 10) === -1 && !$first->isHeld(), 'Missing schema fails closed and unlocks');
	echo $checks." lock checks passed on MariaDB, including two-process races.\n";
} catch (Throwable $error) {
	fwrite(STDERR, 'FAILED: '.$error->getMessage()."\n");
	$exitCode = 1;
} finally {
	foreach ($workers as $process) {
		if (!is_resource($process[0])) {
			continue;
		}
		proc_terminate($process[0]);
		foreach ($process[1] as $pipe) {
			if (is_resource($pipe)) {
				fclose($pipe);
			}
		}
		proc_close($process[0]);
	}
	if ($second) {
		$second->close();
	}
	if ($lossDb) {
		$lossDb->close();
	}
	if ($db) {
		if ($db->transaction_opened) {
			$db->rollback();
		}
		foreach (array_unique($tables) as $table) {
			// Never drop any table outside the exact random test namespace.
			if ($table === MAIN_DB_PREFIX.'facture' || $table === MAIN_DB_PREFIX.'takeposguard_invoice_lock') {
				$db->query('DROP TABLE IF EXISTS '.$table);
			}
		}
		$db->close();
	}
	foreach (glob($dir.DIRECTORY_SEPARATOR.'*') ?: array() as $file) {
		unlink($file);
	}
	if (is_dir($dir)) {
		rmdir($dir);
	}
}
exit($exitCode);
