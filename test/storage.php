<?php
/**
 * Integration test on connection-local temporary tables only.
 * Run: php test/storage.php --mysql
 * Reads the local Dolibarr DB configuration without printing credentials.
 * No native invoice, payment, stock or module configuration is modified.
 */
if (PHP_SAPI !== 'cli' || !in_array('--mysql', $argv, true)) {
	fwrite(STDERR, "Usage: php test/storage.php --mysql\n");
	exit(2);
}
error_reporting(E_ALL);
define('DOL_DOCUMENT_ROOT', realpath(__DIR__.'/../../..'));
define('DOL_URL_ROOT', '/dolibarr');
require DOL_DOCUMENT_ROOT.'/conf/conf.php';
define('MAIN_DB_PREFIX', 'tpg_test_');
require_once DOL_DOCUMENT_ROOT.'/core/lib/functions.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/commoninvoice.class.php';
require_once __DIR__.'/../class/takeposguardstorage.class.php';
require_once __DIR__.'/../class/actions_takeposguard.class.php';

$conf = new stdClass();
$conf->entity = 1;
$conf->global = new stdClass();
$conf->modules = array();
$conf->currency = 'EUR';
$conf->db = new stdClass();
$conf->db->dolibarr_main_db_collation = isset($dolibarr_main_db_collation) ? $dolibarr_main_db_collation : 'utf8_unicode_ci';
$conf->global->MAIN_MAX_DECIMALS_TOT = 8;
$conf->global->MAIN_MAX_DECIMALS_UNIT = 8;
if (!in_array($dolibarr_main_db_type, array('mysql', 'mysqli'), true)) {
	fwrite(STDERR, "This test requires the Dolibarr MySQL/MariaDB driver.\n");
	exit(2);
}
try {
	$db = getDoliDBInstance($dolibarr_main_db_type, $dolibarr_main_db_host, $dolibarr_main_db_user,
		$dolibarr_main_db_pass, $dolibarr_main_db_name, empty($dolibarr_main_db_port) ? 0 : (int) $dolibarr_main_db_port);
} catch (Throwable $error) {
	// Never emit a connection stack trace containing database credentials.
	fwrite(STDERR, "Test database connection unavailable.\n");
	exit(1);
}
if (!$db->connected) {
	fwrite(STDERR, "Test database connection unavailable.\n");
	exit(1);
}

/** Use native remain-to-pay calculation on a minimal temporary invoice fixture. */
class StorageFixtureInvoice extends CommonInvoice
{
	public $element = 'facture';
	public function __construct($db, $row)
	{
		$this->db = $db;
		$this->id = (int) $row->rowid;
		$this->entity = (int) $row->entity;
		$this->status = (int) $row->fk_statut;
		$this->total_ttc = $row->total_ttc;
		$this->type = isset($row->type) ? (int) $row->type : 0;
		$this->module_source = $row->module_source;
	}
	public function fetch($id, $ref = '', $ref_ext = '', $ref_int = '')
	{
		$result = $this->db->query('SELECT * FROM '.MAIN_DB_PREFIX.'facture WHERE rowid = '.((int) $id));
		if (!$result || !($row = $this->db->fetch_object($result))) {
			return -1;
		}
		$this->__construct($this->db, $row);
		return 1;
	}
}

/** Only replace full Facture::fetch hydration, not snapshot SQL or calculation. */
class StorageFixtureRepository extends TakeposguardStorage
{
	protected function loadInvoice($invoiceId)
	{
		$result = $this->db->query('SELECT * FROM '.MAIN_DB_PREFIX.'facture WHERE rowid = '.((int) $invoiceId));
		$row = $this->db->fetch_object($result);
		return $row ? new StorageFixtureInvoice($this->db, $row) : false;
	}
}

/** Native hook, lock and storage SQL, with minimal invoice hydration only. */
class StorageFixtureHook extends ActionsTakeposguard
{
	protected function newStorage()
	{
		return new StorageFixtureRepository($this->db);
	}
	public function releaseFixtureLock()
	{
		return !$this->paymentLock->isHeld() || $this->paymentLock->release();
	}
}

$checks = 0;
function storageCheck($condition, $label)
{
	global $checks;
	if (!$condition) {
		throw new RuntimeException($label);
	}
	$checks++;
}
function fixtureSql($sql)
{
	global $db;
	$result = $db->query($sql, 1);
	if (!$result) {
		throw new RuntimeException('Fixture SQL failed');
	}
	return $result;
}

try {
	// Exercise the native installation parser with the supplied SQL files.
	foreach (array('llx_takeposguard_payment_attempt.sql', 'llx_takeposguard_invoice_lock.sql',
		'llx_takeposguard_payment_attempt.key.sql', 'llx_takeposguard_invoice_lock.key.sql') as $file) {
		$sql = file_get_contents(__DIR__.'/../sql/'.$file);
		$sql = preg_replace('/CREATE TABLE /', 'CREATE TEMPORARY TABLE ', $sql);
		$path = tempnam(sys_get_temp_dir(), 'tpg-sql-');
		try {
			file_put_contents($path, $sql);
			storageCheck(run_sql($path, 1, 0, 1, '', 'none') > 0, 'Install '.$file);
		} finally {
			unlink($path);
		}
	}
	fixtureSql('CREATE TEMPORARY TABLE tpg_test_facture (rowid integer PRIMARY KEY, entity integer, module_source varchar(32), fk_statut integer, total_ttc double(24,8), type integer) ENGINE=innodb');
	fixtureSql('CREATE TEMPORARY TABLE tpg_test_paiement (rowid integer PRIMARY KEY, entity integer) ENGINE=innodb');
	fixtureSql('CREATE TEMPORARY TABLE tpg_test_paiement_facture (fk_paiement integer, fk_facture integer, amount double(24,8), multicurrency_amount double(24,8)) ENGINE=innodb');
	fixtureSql('CREATE TEMPORARY TABLE tpg_test_societe_remise_except (fk_facture_source integer, fk_facture integer, amount_ttc double(24,8), multicurrency_amount_ttc double(24,8)) ENGINE=innodb');
	fixtureSql("INSERT INTO tpg_test_facture VALUES (1,1,'takepos',0,100,0),(2,1,'takepos',1,100,0),(3,2,'takepos',0,100,0),(4,1,'other',0,100,0),(5,1,'takepos',1,-100,2)");
	$conf->global->TAKEPOSGUARD_ENABLE = 1;
	$conf->global->TAKEPOSGUARD_LOCK_TIMEOUT = 120;
	$user = new class {
		public $id = 1;
		public $socid = 0;
		public $allowed = true;
		public function hasRight($module, $right, $detail = '') { return $this->allowed; }
	};
	$_GET = array('takeposguard_token' => '12345678-1234-4234-8234-123456789afe', 'pay' => 'LIQ', 'amount' => '25');
	$_SESSION['takeposterminal'] = 1;
	$hook = new StorageFixtureHook($db);
	$nativeFixture = new StorageFixtureInvoice($db, (object) array('rowid' => 2, 'entity' => 1, 'fk_statut' => 0, 'total_ttc' => 100, 'module_source' => 'takepos'));
	$action = 'valid';
	storageCheck($hook->doActions(array('context' => 'takeposinvoice'), $nativeFixture, $action, null) === 0
		&& (int) $nativeFixture->status === 1, 'Hook accepts under real lock and refreshes stale status');
	$hookStorage = new StorageFixtureRepository($db);
	$hookAttempt = $hookStorage->fetchAttempt(2, $_GET['takeposguard_token']);
	storageCheck($hookAttempt && $hookAttempt->status === 'PROCESSING' && (float) $hookAttempt->remain_before === 100.0
		&& $hookStorage->fetchInvoiceLock(2) !== null && (int) $db->transaction_opened === 0,
		'Hook persists authoritative snapshot and lock before native transaction');
	// No native action executed in this fixture; explicit cleanup is test-only.
	storageCheck($hook->releaseFixtureLock(), 'Integration fixture owns its lock');
	$_GET = array();
	$a = '12345678-1234-4234-8234-123456789abc';
	$b = '12345678-1234-4234-8234-123456789abd';
	$c = '12345678-1234-4234-8234-123456789abe';
	$d = '12345678-1234-4234-8234-123456789abf';
	$storage = new StorageFixtureRepository($db);
	storageCheck(TakeposguardStorage::normalizeToken(strtoupper($a)) === $a, 'Canonical UUID');
	storageCheck(TakeposguardStorage::normalizeToken('bad') === false, 'Invalid UUID');
	storageCheck(TakeposguardStorage::normalizeToken(str_replace('-4234-', '-1234-', $a)) === false, 'Non-v4 UUID');
	storageCheck($storage->fetchAttempt(1, $a) === null, 'Missing attempt');
	$metadata = array('terminal' => '1', 'payment_code' => 'LIQ', 'requested_amount' => '40.12345678',
		'ip_address' => '::1', 'user_agent' => str_repeat("é", 300)."'\n");
	storageCheck($storage->createProcessing(1, strtoupper($a), 1, $metadata) > 0, 'Create attempt');
	$row = $storage->fetchAttempt(1, $a);
	storageCheck($row->status === 'PROCESSING' && (float) $row->remain_before === 100.0 && $row->remain_after === null, 'Initial authoritative snapshot');
	storageCheck($storage->fetchSuccessfulValidation(1) === null, 'Processing is not initial validation evidence');
	storageCheck(abs((float) $row->requested_amount - 40.12345678) < 0.00000001 && $row->actual_amount === null, 'Requested amount separate from result');
	storageCheck(mb_strlen($row->user_agent, 'UTF-8') === 255, 'UTF-8 bounded metadata');
	storageCheck($storage->createProcessing(1, $a, 1) === false, 'Duplicate token rejected');
	storageCheck($storage->createProcessing(2, $a, 1) === false, 'Token bound to one invoice');
	storageCheck($storage->createProcessing(3, $b, 1) === false, 'Foreign entity invoice rejected');
	storageCheck($storage->createProcessing(4, $b, 1) === false, 'Non-TakePOS invoice rejected');
	storageCheck($storage->createProcessing(2, $b, 1, array('requested_amount' => '1e2')) === false, 'Unsafe amount rejected');
	storageCheck($storage->createProcessing(2, $b, 1, array('requested_amount' => INF)) === false, 'Infinite amount rejected');
	storageCheck($storage->createProcessing(2, $b, 1, array('requested_amount' => NAN)) === false, 'NaN amount rejected');
	storageCheck($storage->createProcessing(2, $b, 1, array('requested_amount' => '1.123456789')) === false, 'Excess precision rejected');
	storageCheck($storage->createProcessing(2, $b, 1, array('requested_amount' => '10000000000000000')) === false, 'Numeric overflow rejected');
	storageCheck($storage->createProcessing(2, $b, 1, array('user_agent' => "\xff")) === false, 'Invalid UTF-8 rejected');
	storageCheck($storage->createProcessing(2, $b, 1, array('payment_code' => "CB'")) === false, 'Invalid code rejected');
	storageCheck($storage->createProcessing(2, $b, 1, array('ip_address' => 'not-an-ip')) === false, 'Invalid IP rejected');
	fixtureSql('INSERT INTO tpg_test_paiement VALUES (10,1),(11,2)');
	fixtureSql('INSERT INTO tpg_test_paiement_facture VALUES (10,1,40,40),(11,3,10,10)');
	fixtureSql('UPDATE tpg_test_facture SET fk_statut=1 WHERE rowid=1');
	storageCheck(!$storage->completeAttempt(1, $a, 'SUCCESS', array('fk_payment' => 11)), 'Foreign payment rejected');
	storageCheck(!$storage->completeAttempt(1, $a, 'PROCESSING', array()), 'Invalid transition rejected');
	storageCheck($storage->completeAttempt(1, $a, 'SUCCESS', array('actual_amount' => '999', 'fk_payment' => 10)), 'Store confirmed outcome');
	storageCheck($storage->fetchSuccessfulValidation(1) !== null, 'Committed draft-to-validated success is evidence');
	$row = $storage->fetchAttempt(1, $a);
	storageCheck((float) $row->remain_after === 60.0 && (int) $row->fk_payment === 10 && $row->date_completed !== null, 'Persistent native balance');
	storageCheck((float) $row->actual_amount === 40.0, 'Actual amount comes from database, not caller');
	storageCheck(!$storage->completeAttempt(1, $a, 'FAILED', array()), 'Final outcome immutable');
	storageCheck($storage->createProcessing(1, $b, 1) > 0, 'New token for partial payment');
	$row = $storage->fetchAttempt(1, $b);
	storageCheck((float) $row->remain_before === 60.0 && (int) $row->payment_count_before === 1 && (int) $row->last_payment_before === 10, 'Payment baseline');
	storageCheck($storage->completeAttempt(1, $b, 'FAILED', array('error_code' => 'NativeFailure', 'error_message' => "can't pay\n")), 'Failure persisted');
	storageCheck($storage->fetchAttempt(1, $b)->error_message === "can't pay", 'SQL escaping and control character removal');
	storageCheck($storage->fetchSuccessfulValidation(2) === null, 'Partial-only and uncompleted attempts do not prove initial validation');
	$conf->modules = array('stock' => 1, 'productbatch' => 1);
	$_GET = array('takeposguard_token' => '12345678-1234-4234-8234-123456789af1', 'pay' => 'LIQ', 'amount' => '60');
	$partialHook = new StorageFixtureHook($db);
	$partialFixture = new StorageFixtureInvoice($db, (object) array('rowid' => 1, 'entity' => 1, 'fk_statut' => 0, 'total_ttc' => 100, 'module_source' => 'takepos'));
	$action = 'valid';
	storageCheck($partialHook->doActions(array('context' => 'takeposinvoice'), $partialFixture, $action, null) === 0
		&& getDolGlobalString('CASHDESK_NO_DECREASE_STOCK1') === '1', 'New partial-payment token accepted with real initial validation evidence and stock override');
	$partialAttempt = $storage->fetchAttempt(1, $_GET['takeposguard_token']);
	storageCheck($partialAttempt && (float) $partialAttempt->remain_before === 60.0
		&& (int) $partialAttempt->invoice_status_before === 1, 'Partial snapshot uses authoritative remaining balance');
	$partialHook->completeTakePosInvoiceHeader(array('context' => 'takeposinvoice'), $partialFixture, $action, null);
	storageCheck(!property_exists($conf->global, 'CASHDESK_NO_DECREASE_STOCK1') && $partialHook->releaseFixtureLock(), 'Post-native fixture header restores missing constant exactly');
	$_GET = array();
	$conf->modules = array();
	storageCheck($storage->createProcessing(2, $c, 1) > 0 && $storage->completeAttempt(2, $c, 'BLOCKED', array()), 'Blocked outcome persisted');
	storageCheck($storage->createProcessing(5, $d, 1, array('requested_amount' => '-10')) > 0, 'Negative credit-note balance');
	fixtureSql('INSERT INTO tpg_test_societe_remise_except VALUES (5,2,20,20)');
	storageCheck($storage->createProcessing(2, '12345678-1234-4234-8234-123456789ac1', 1) > 0
		&& (float) $storage->fetchAttempt(2, '12345678-1234-4234-8234-123456789ac1')->remain_before === 80.0, 'Native credit-note deduction included');
	// Post-native finalization, rollback and crash recovery on isolated InnoDB fixtures.
	$finalizer = new TakeposguardFinalizer($db, $storage);
	$recovery = new TakeposguardRecovery($db, $storage);
	foreach (array('draft-success', 'partial-success', 'rollback', 'interrupted-commit', 'interrupted-rollback', 'ambiguous') as $offset => $scenario) {
		$id = 20 + $offset;
		$token = sprintf('12345678-1234-4234-8234-%012x', $id);
		$nextToken = sprintf('12345678-1234-4234-8234-%012x', $id + 100);
		$status = $scenario === 'partial-success' ? 1 : 0;
		fixtureSql("INSERT INTO tpg_test_facture VALUES ($id,1,'takepos',$status,100,0)");
		$lock = new TakeposguardLock($db);
		storageCheck($lock->acquire($id, $token, 120) === TakeposguardLock::ACQUIRED
			&& $storage->createProcessing($id, $token, 1) > 0, 'Finalization baseline '.$scenario);
		$db->begin();
		fixtureSql('UPDATE tpg_test_facture SET fk_statut=1 WHERE rowid='.$id);
		fixtureSql('INSERT INTO tpg_test_paiement VALUES ('.($id + 1000).',1)');
		fixtureSql('INSERT INTO tpg_test_paiement_facture VALUES ('.($id + 1000).','.$id.',40,40)');
		storageCheck($finalizer->reconcile($id, $token, $lock) === false
			&& $storage->fetchAttempt($id, $token)->status === 'PROCESSING', 'Never finalize an uncommitted native transaction '.$scenario);
		if (in_array($scenario, array('rollback', 'interrupted-rollback'), true)) {
			$db->rollback();
		} else {
			$db->commit();
		}
		if ($scenario === 'ambiguous') {
			fixtureSql('INSERT INTO tpg_test_paiement VALUES ('.($id + 2000).',1)');
			fixtureSql('INSERT INTO tpg_test_paiement_facture VALUES ('.($id + 2000).','.$id.',10,10)');
		}
		if (strpos($scenario, 'interrupted-') === 0 || $scenario === 'ambiguous') {
			storageCheck($lock->abandon(), 'Interrupted request keeps metadata '.$scenario);
			$lock = new TakeposguardLock($db);
			storageCheck($lock->acquire($id, $nextToken, 120) === TakeposguardLock::BUSY, 'TTL alone cannot be bypassed '.$scenario);
			fixtureSql('UPDATE tpg_test_takeposguard_invoice_lock SET expires_at=\'2020-01-01 00:00:00\' WHERE fk_invoice='.$id.' AND entity=1');
			storageCheck($lock->acquire($id, $nextToken, 120) === TakeposguardLock::RECOVERY_REQUIRED, 'Expired metadata requires reconciliation '.$scenario);
			storageCheck($recovery->recover($lock, $id) === ($scenario !== 'ambiguous'), 'Recovery decision '.$scenario);
			if ($scenario === 'ambiguous') {
				storageCheck($storage->fetchAttempt($id, $token)->status === 'BLOCKED'
					&& $storage->fetchInvoiceLock($id)->operation_token === $token, 'Ambiguous effects never authorize a new owner');
				$lock->abandon();
				continue;
			}
			storageCheck($storage->fetchInvoiceLock($id)->operation_token === $nextToken, 'Only reconciled owner replaced '.$scenario);
		} else {
			storageCheck($finalizer->reconcile($id, $token, $lock) === ($scenario === 'rollback' ? 'FAILED' : 'SUCCESS'), 'Committed-effect outcome '.$scenario);
		}
		$row = $storage->fetchAttempt($id, $token);
		$failed = in_array($scenario, array('rollback', 'interrupted-rollback'), true);
		storageCheck($row->status === ($failed ? 'FAILED' : 'SUCCESS') && (float) $row->remain_after === ($failed ? 100.0 : 60.0)
			&& $row->date_completed !== null, 'Durable outcome and native balance '.$scenario);
		storageCheck($failed ? $row->fk_payment === null : (int) $row->fk_payment === $id + 1000, 'Payment attribution '.$scenario);
		storageCheck($lock->release() && $storage->fetchInvoiceLock($id) === null, 'Release after persistence '.$scenario);
	}
	$conf->global->TAKEPOSGUARD_DEBUG_LOG = 0;
	$_GET = array('takeposguard_token' => '12345678-1234-4234-8234-123456789af2', 'pay' => 'LIQ', 'amount' => 40);
	fixtureSql("INSERT INTO tpg_test_facture VALUES (40,1,'takepos',0,100,0)");
	$liveHook = new StorageFixtureHook($db);
	$liveInvoice = new StorageFixtureInvoice($db, (object) array('rowid' => 40, 'entity' => 1, 'fk_statut' => 0, 'total_ttc' => 100, 'module_source' => 'takepos'));
	storageCheck($liveHook->doActions(array('context' => 'takeposinvoice'), $liveInvoice, $action, null) === 0, 'Native header fixture accepted');
	$db->begin();
	fixtureSql('UPDATE tpg_test_facture SET fk_statut=1 WHERE rowid=40');
	fixtureSql('INSERT INTO tpg_test_paiement VALUES (4000,1)');
	fixtureSql('INSERT INTO tpg_test_paiement_facture VALUES (4000,40,40,40)');
	$liveHook->completeTakePosInvoiceHeader(array('context' => 'takeposinvoice'), $liveInvoice, $action, null);
	storageCheck($storage->fetchAttempt(40, $_GET['takeposguard_token'])->status === 'PROCESSING', 'Early header never releases an open transaction');
	$db->commit();
	$liveHook->completeTakePosInvoiceHeader(array('context' => 'takeposinvoice'), $liveInvoice, $action, null);
	storageCheck($storage->fetchAttempt(40, $_GET['takeposguard_token'])->status === 'SUCCESS'
		&& $storage->fetchInvoiceLock(40) === null && (int) $liveInvoice->status === 1, 'Post-commit native header finalizes, releases and refreshes');
	storageCheck($liveHook->finalizePayment() === false, 'Repeated finalization has no effects');
	$_GET = array();
	require_once __DIR__.'/../class/takeposguardattemptservice.class.php';
	$service = new TakeposguardAttemptService($db, $storage);
	storageCheck($service->resolve('12345678-1234-4234-8234-123456789af2', 1) === 'SUCCESS', 'Cashier can resolve confirmed result without replay');
	storageCheck($service->resolve('12345678-1234-4234-8234-123456789af2', 2) === 'UNKNOWN', 'Another cashier cannot inspect or recover a token');
	storageCheck($service->resolve('12345678-1234-4234-8234-123456789af2', 2, true) === 'SUCCESS', 'Maintenance may resolve another cashier attempt');
	storageCheck($service->resolve('invalid', 1) === 'UNKNOWN', 'Recovery validates token');
	storageCheck($service->resolve('12345678-1234-4234-8234-123456789aff', 1) === 'UNKNOWN', 'Unknown token does not authorize a retry');
	$recoverToken = '12345678-1234-4234-8234-123456789af3';
	fixtureSql("INSERT INTO tpg_test_facture VALUES (41,1,'takepos',0,100,0)");
	$recoverLock = new TakeposguardLock($db);
	storageCheck($recoverLock->acquire(41, $recoverToken, 120) === 1 && $storage->createProcessing(41, $recoverToken, 1), 'Status recovery fixture');
	storageCheck($finalizer->reconcile(40, $recoverToken, $recoverLock) === false, 'Lock for another invoice cannot finalize');
	storageCheck($finalizer->reconcile(41, '12345678-1234-4234-8234-123456789af4', $recoverLock) === false, 'Lock for another token cannot finalize');
	storageCheck($service->resolve($recoverToken, 1) === 'PROCESSING', 'Status check cannot displace live same-session ownership');
	$recoverLock->abandon();
	storageCheck($service->resolve($recoverToken, 1) === 'PROCESSING', 'Status check respects durable unexpired metadata');
	fixtureSql("UPDATE tpg_test_takeposguard_invoice_lock SET expires_at='2020-01-01 00:00:00' WHERE entity=1 AND fk_invoice=41");
	storageCheck($service->resolve($recoverToken, 1) === 'FAILED' && $storage->fetchInvoiceLock(41) === null, 'Status endpoint reconciles expired no-effect attempt and releases lock');
	fixtureSql("INSERT INTO tpg_test_facture VALUES (42,1,'takepos',0,100,0)");
	$orphan = new TakeposguardLock($db);
	$orphanToken = '12345678-1234-4234-8234-123456789af4';
	storageCheck($orphan->acquire(42, $orphanToken, 120) === 1 && $orphan->abandon(), 'Crash between lock acquisition and attempt insertion');
	fixtureSql("UPDATE tpg_test_takeposguard_invoice_lock SET expires_at='2020-01-01 00:00:00' WHERE entity=1 AND fk_invoice=42");
	$orphan = new TakeposguardLock($db);
	storageCheck($orphan->acquire(42, $recoverToken, 120) === 2 && $recovery->recover($orphan, 42)
		&& $orphan->release(), 'Absent attempt permits safe expired owner replacement without native processing');
	// Audit and administrative maintenance operate on the same temporary namespace.
	require_once __DIR__.'/../class/takeposguardaudit.class.php';
	require_once __DIR__.'/../class/takeposguardmaintenance.class.php';
	fixtureSql('ALTER TABLE tpg_test_facture ADD ref varchar(128)');
	fixtureSql('CREATE TEMPORARY TABLE tpg_test_user (rowid integer PRIMARY KEY, login varchar(128))');
	fixtureSql("INSERT INTO tpg_test_user VALUES (1,'cashier')");
	fixtureSql("UPDATE tpg_test_facture SET ref='<img src=x onerror=alert(1)>' WHERE rowid=20");
	$audit = new TakeposguardAudit($db);
	storageCheck(count($audit->attempts('', 0, 0, 2)) === 2 && count($audit->attempts('', 0, 2, 2)) === 2, 'Audit pagination bounded in SQL');
	$audited = $audit->attempts('SUCCESS', 20);
	storageCheck(count($audited) === 1 && $audited[0]->user_login === 'cashier' && $audited[0]->invoice_ref === '<img src=x onerror=alert(1)>', 'Audit joins invoice/user once with authoritative outcome');
	storageCheck($audit->attempts("SUCCESS' OR 1=1", 0) === false, 'Audit rejects unsafe status filter');
	storageCheck($audit->attempts('', 3) === array(), 'Audit never reads another entity invoice');
	storageCheck(count($audit->attempts('', 0, 0, 50, array('user_login' => 'cashier', 'invoice' => 'img'))) === 1, 'Native text filters combine with entity scope');
	storageCheck(count($audit->attempts('', 20, 0, 50, array('remain_before' => (string) $audited[0]->remain_before))) === 1, 'Exact amount filter accepts persisted value');
	storageCheck($audit->attempts('', 0, 0, 50, array('remain_before' => '0 OR 1=1')) === array(), 'Invalid amount filter never widens results');
	storageCheck($audit->attempts('', 0, 0, 50, array('datec' => '2026-02-31')) === array(), 'Invalid calendar date fails closed');
	storageCheck(count($audit->attempts('', 20, 0, 50, array(), 'datec DESC; DROP TABLE facture', 'ASC; DELETE')) === 1, 'Sorting uses only fixed SQL identifiers and directions');
	storageCheck($audit->locks(0, 50, array('status' => "SUCCESS' OR 1=1")) === array(), 'Invalid maintenance status cannot widen lock list');
	class MaintenanceFixtureRepository extends TakeposguardMaintenance {
		protected function loadInvoice($id) {
			$result = $this->db->query('SELECT * FROM '.MAIN_DB_PREFIX.'facture WHERE rowid='.((int) $id));
			$row = $result ? $this->db->fetch_object($result) : false;
			return $row ? new StorageFixtureInvoice($this->db, $row) : false;
		}
	}
	$maintenance = new MaintenanceFixtureRepository($db);
	$maintenanceToken = '12345678-1234-4234-8234-123456789af5';
	fixtureSql("INSERT INTO tpg_test_facture VALUES (43,1,'takepos',0,100,0,'draft')");
	$live = new TakeposguardLock($db);
	storageCheck($live->acquire(43, $maintenanceToken, 120) === 1 && $storage->createProcessing(43, $maintenanceToken, 1), 'Maintenance live fixture');
	storageCheck(!$maintenance->releaseExpired(43, $maintenanceToken), 'Maintenance does not release unexpired metadata');
	fixtureSql("UPDATE tpg_test_takeposguard_invoice_lock SET expires_at='2020-01-01 00:00:00' WHERE entity=1 AND fk_invoice=43");
	storageCheck(!$maintenance->releaseExpired(43, $maintenanceToken) && $live->isHeld(), 'Maintenance never displaces live ownership despite expiry');
	$live->abandon();
	storageCheck(!$maintenance->releaseExpired(43, $recoverToken), 'Stale form token cannot recover another owner');
	$conf->global->TAKEPOSGUARD_ENABLE = 0;
	storageCheck(!$maintenance->releaseExpired(43, $maintenanceToken), 'Disabled guard cannot safely reconcile native unguarded requests');
	$conf->global->TAKEPOSGUARD_ENABLE = 1;
	storageCheck($maintenance->releaseExpired(43, $maintenanceToken) && $storage->fetchInvoiceLock(43) === null
		&& $storage->fetchAttempt(43, $maintenanceToken)->status === 'FAILED', 'Maintenance safely reconciles and releases expired no-effect request');
	$ambiguousToken = sprintf('12345678-1234-4234-8234-%012x', 25);
	storageCheck(!$maintenance->releaseExpired(25, $ambiguousToken) && $storage->fetchInvoiceLock(25) !== null, 'BLOCKED effects remain locked without forced release');
	fixtureSql("UPDATE tpg_test_takeposguard_payment_attempt SET datec='2020-01-01 00:00:00', date_completed='2020-01-02 00:00:00', terminal='old', user_agent='old', error_message='old' WHERE entity=1 AND fk_invoice IN (20,22,25)");
	fixtureSql("UPDATE tpg_test_takeposguard_payment_attempt SET datec='2020-01-01 00:00:00', user_agent='unresolved' WHERE entity=1 AND fk_invoice=2 AND status='PROCESSING'");
	$initialToken = sprintf('12345678-1234-4234-8234-%012x', 20);
	$failedToken = sprintf('12345678-1234-4234-8234-%012x', 22);
	$lockedToken = sprintf('12345678-1234-4234-8234-%012x', 23);
	$lockedHistory = new TakeposguardLock($db);
	storageCheck($lockedHistory->acquire(23, $lockedToken, 120) === 1 && $lockedHistory->abandon(), 'Old confirmed result with retained lock');
	fixtureSql("UPDATE tpg_test_takeposguard_payment_attempt SET datec='2020-01-01 00:00:00', date_completed='2020-01-02 00:00:00', terminal='locked' WHERE entity=1 AND fk_invoice=23");
	$db->begin();
	storageCheck($maintenance->purgeDetails(90) === false, 'Purge does not nest into a native transaction');
	$db->rollback();
	storageCheck($maintenance->purgeDetails(0) === false, 'Invalid retention never purges recent history');
	storageCheck($maintenance->purgeDetails(90) === 2, 'Purge redacts only old confirmed unlocked outcomes');
	storageCheck($storage->fetchAttempt(20, $initialToken)->user_agent === null && $storage->fetchAttempt(22, $failedToken)->error_message === null, 'Old sensitive details removed');
	storageCheck($storage->fetchTokenOwner($initialToken)->status === 'SUCCESS' && $storage->fetchTokenOwner($failedToken)->status === 'FAILED'
		&& $storage->fetchSuccessfulValidation(20) !== null, 'Purge preserves replay rejection and initial stock evidence');
	storageCheck($storage->fetchAttempt(25, $ambiguousToken)->user_agent === 'old'
		&& $storage->fetchAttempt(2, $c)->status === 'BLOCKED', 'Ambiguous outcomes never purged');
	$unresolved = $db->fetch_object(fixtureSql("SELECT COUNT(*) AS total FROM tpg_test_takeposguard_payment_attempt WHERE entity=1 AND fk_invoice=2 AND status='PROCESSING' AND user_agent='unresolved'"));
	storageCheck((int) $unresolved->total > 0, 'Old unresolved processing details retained');
	storageCheck($storage->fetchAttempt(40, '12345678-1234-4234-8234-123456789af2')->payment_code === 'LIQ', 'Recent outcome details preserved');
	storageCheck($maintenance->purgeDetails(90) === 0, 'Repeated detail purge is idempotent');
	storageCheck($storage->fetchAttempt(23, $lockedToken)->terminal === 'locked', 'Purge excludes old confirmed outcomes on locked invoices');
	fixtureSql("UPDATE tpg_test_takeposguard_invoice_lock SET expires_at='2020-01-01 00:00:00' WHERE entity=1 AND fk_invoice=23");
	storageCheck($maintenance->releaseExpired(23, $lockedToken) && $maintenance->purgeDetails(90) === 1, 'Confirmed expired owner released before old detail redaction');
	$conf->global->TAKEPOSGUARD_MAX_ATTEMPTS = 10;
	fixtureSql("INSERT INTO tpg_test_facture VALUES (44,1,'takepos',0,100,0,'limited')");
	for ($n = 0; $n < 10; $n++) {
		storageCheck($storage->createProcessing(44, sprintf('12345678-1234-4234-8234-%012x', 500 + $n), 1) > 0, 'Invoice limit baseline');
	}
	storageCheck($storage->createProcessing(44, sprintf('12345678-1234-4234-8234-%012x', 510), 1) === false
		&& $storage->error === 'TakeposguardAttemptLimitReached', 'Bounded persistent keys prevent unbounded attempts on an invoice');
	$conf->global->TAKEPOSGUARD_MAX_ATTEMPTS = 1000;
	$batch = array();
	for ($n = 0; $n < 501; $n++) {
		$batch[] = "(1,44,'".sprintf('12345678-1234-4234-8234-%012x', 10000 + $n)."',1,100,0,'FAILED','2020-01-01 00:00:00','2020-01-02 00:00:00','batch')";
	}
	fixtureSql('INSERT INTO tpg_test_takeposguard_payment_attempt(entity,fk_invoice,operation_token,fk_user,remain_before,invoice_status_before,status,datec,date_completed,terminal) VALUES '.implode(',', $batch));
	storageCheck($maintenance->purgeDetails(90) === 500 && $maintenance->purgeDetails(90) === 1, 'Cleanup bounded to 500 outcomes per invocation');
	$conf->modules['takeposguard'] = 1;
	storageCheck($maintenance->doScheduledJob() === 0, 'Native scheduled cleanup entrypoint succeeds with maintenance rights');
	$user->allowed = false;
	storageCheck($maintenance->doScheduledJob() === 1, 'Scheduled cleanup refuses an unauthorized execution user');
	$user->allowed = true;
	unset($conf->modules['takeposguard']);
	storageCheck($maintenance->doScheduledJob() === 1, 'Scheduled cleanup is inert when module disabled');
	$conf->entity = 2;
	$other = new StorageFixtureRepository($db);
	storageCheck($other->fetchAttempt(1, $a) === null && !$other->completeAttempt(1, $a, 'FAILED', array()), 'Cross-entity access denied');
	storageCheck($other->createProcessing(3, $a, 1) > 0, 'Token reusable in another entity');
	storageCheck($other->fetchSuccessfulValidation(1) === null, 'Validation evidence cannot cross entities');
	$otherAudit = new TakeposguardAudit($db);
	storageCheck(count($otherAudit->attempts()) === 1 && $otherAudit->attempts()[0]->fk_invoice == 3, 'Audit scoped to current entity');
	$otherMaintenance = new MaintenanceFixtureRepository($db);
	storageCheck(!$otherMaintenance->releaseExpired(25, $ambiguousToken) && $otherMaintenance->purgeDetails(90) === 0, 'Maintenance cannot release or redact another entity');
	storageCheck($storage->fetchAttempt(1, $a)->status === 'SUCCESS', 'Original entity scope remains fixed');
	storageCheck((int) $storage->fetchTokenOwner($a)->fk_invoice === 1
		&& $storage->fetchTokenOwner($a)->status === 'SUCCESS', 'Token lookup finds invoice binding and terminal status');
	storageCheck((int) $other->fetchTokenOwner($a)->fk_invoice === 3, 'Token lookup isolated by entity');
	storageCheck($storage->fetchTokenOwner('12345678-1234-4234-8234-123456789aff') === null, 'Unused token distinct from SQL failure');
	storageCheck($storage->fetchTokenOwner('invalid') === false, 'Token lookup validates UUID');
	fixtureSql("INSERT INTO tpg_test_takeposguard_invoice_lock(entity,fk_invoice,operation_token,datec,expires_at) VALUES (1,1,'$a','2026-01-01 00:00:00','2026-01-01 00:02:00')");
	storageCheck($db->query("INSERT INTO tpg_test_takeposguard_invoice_lock(entity,fk_invoice,operation_token,datec,expires_at) VALUES (1,1,'$b','2026-01-01 00:00:00','2026-01-01 00:02:00')", 1) === false, 'Unique lock per entity/invoice');
	fixtureSql("INSERT INTO tpg_test_takeposguard_invoice_lock(entity,fk_invoice,operation_token,datec,expires_at) VALUES (2,1,'$a','2026-01-01 00:00:00','2026-01-01 00:02:00')");
	storageCheck($storage->fetchInvoiceLock(1)->operation_token === $a && (int) $other->fetchInvoiceLock(1)->entity === 2, 'Entity-scoped lock metadata');
	storageCheck((int) $db->transaction_opened === 0, 'Repository leaves transaction depth unchanged');
	$db->begin();
	storageCheck($storage->createProcessing(2, '12345678-1234-4234-8234-123456789ac0', 1) > 0 && (int) $db->transaction_opened === 1, 'Caller transaction preserved');
	storageCheck(!$storage->createProcessing(1, $a, 1) && (int) $db->transaction_opened === 1, 'Duplicate does not change caller transaction depth');
	$db->rollback();
	storageCheck($storage->fetchAttempt(2, '12345678-1234-4234-8234-123456789ac0') === null, 'Caller rollback preserved');
	fixtureSql('DROP TEMPORARY TABLE tpg_test_societe_remise_except');
	storageCheck($storage->createProcessing(2, '12345678-1234-4234-8234-123456789ac2', 1) === false
		&& $storage->error === 'TakeposguardSnapshotFailed', 'Native calculation failure blocks persistence');
	fixtureSql('DROP TEMPORARY TABLE tpg_test_takeposguard_payment_attempt');
	storageCheck($storage->fetchTokenOwner($a) === false, 'Token SQL failure remains fail closed');
	storageCheck($storage->fetchSuccessfulValidation(1) === false, 'Evidence SQL failure distinct from absence');
	storageCheck($storage->fetchAttempt(1, $a) === false && $storage->error === 'TakeposguardStorageReadFailed', 'SQL read failure remains distinct from absence');
	echo $checks." storage/schema checks passed on ".$db->type." (temporary tables only).\n";
} catch (Throwable $error) {
	fwrite(STDERR, 'FAILED: '.$error->getMessage()."\n");
	exit(1);
} finally {
	// Closing the connection drops all temporary tables, including on failure.
	$db->close();
}
