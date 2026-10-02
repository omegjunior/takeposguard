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
		return $this->paymentLock->release();
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
		public function hasRight($module, $right) { return true; }
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
	$conf->entity = 2;
	$other = new StorageFixtureRepository($db);
	storageCheck($other->fetchAttempt(1, $a) === null && !$other->completeAttempt(1, $a, 'FAILED', array()), 'Cross-entity access denied');
	storageCheck($other->createProcessing(3, $a, 1) > 0, 'Token reusable in another entity');
	storageCheck($other->fetchSuccessfulValidation(1) === null, 'Validation evidence cannot cross entities');
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
