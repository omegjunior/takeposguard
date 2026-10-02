<?php
/** CLI-only acceptance fixtures. All SQL is confined to a random test namespace. */
if (PHP_SAPI !== 'cli' || !isset($nativePrefix) || !preg_match('/^tpg_native_[0-9a-f]{12}_$/D', $nativePrefix)) {
	exit(2);
}
error_reporting(E_ALL & ~E_DEPRECATED);
define('DOL_DOCUMENT_ROOT', realpath(__DIR__.'/../../..'));
define('DOL_URL_ROOT', '/dolibarr');
define('MAIN_DB_PREFIX', $nativePrefix);
require DOL_DOCUMENT_ROOT.'/conf/conf.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/functions.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/functions2.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/conf.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/translate.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/hookmanager.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/paiement/class/paiement.class.php';
require_once DOL_DOCUMENT_ROOT.'/product/stock/class/mouvementstock.class.php';
require_once DOL_DOCUMENT_ROOT.'/product/class/productbatch.class.php';
require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';
require_once __DIR__.'/../class/actions_takeposguard.class.php';
require_once __DIR__.'/../class/takeposguardattemptservice.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/db/mysqli.class.php';

/** Delegate exclusively to DoliDB; deny even reads of production application tables. */
class NativeAcceptanceDB extends DoliDBMysqli
{
	public $interruptCommit = false;
	public $failPayment = false;
	public $validations = 0;
	public $failedSql = '';
	public function query($sql, $savepoint = 0, $type = 'auto', $resultmode = 0)
	{
		$unquoted = preg_replace("/'(?:\\\\.|''|[^'])*'/s", "''", $sql);
		$unquoted = preg_replace('/--[^\n]*|\/\*.*?\*\//s', '', $unquoted);
		$unquoted = preg_replace('/\bON UPDATE CURRENT_TIMESTAMP\b/i', '', $unquoted);
		preg_match_all('/\b(?:FROM|JOIN|UPDATE|INTO|TABLE(?:\s+IF\s+(?:NOT\s+)?EXISTS)?)\s+([`a-zA-Z0-9_.]+)/i', $unquoted, $matches);
		foreach ($matches[1] as $table) {
			if (ctype_digit($table)) { continue; } // SUBSTRING(... FROM integer), not a table.
			if (strpos(trim($table, '`'), MAIN_DB_PREFIX) !== 0) {
				throw new RuntimeException('Acceptance SQL attempted to leave the isolated namespace: '.$table);
			}
		}
		if ($this->failPayment && preg_match('/^INSERT INTO '.preg_quote(MAIN_DB_PREFIX, '/').'paiement_facture\b/i', trim($sql))) {
			return false; // Inject a link failure after native payment INSERT; outer rollback must undo it.
		}
		if (preg_match('/^UPDATE '.preg_quote(MAIN_DB_PREFIX, '/').'facture SET.*fk_statut\s*=\s*1/s', trim($sql))) {
			$this->validations++;
		}
		$result = parent::query($sql, $savepoint, $type, $resultmode);
		if (!$result) { $this->failedSql = $sql; }
		return $result;
	}
	public function commit($log = '')
	{
		if ($this->interruptCommit && (int) $this->transaction_opened === 1) {
			exit(0); // Registered native dol_shutdown closes the session and rolls back.
		}
		return parent::commit($log);
	}
}

$conf = new Conf();
$conf->entity = isset($nativeEntity) ? (int) $nativeEntity : 1;
$conf->currency = 'EUR';
$conf->file->dol_document_root = array('main' => DOL_DOCUMENT_ROOT);
$conf->file->dol_url_root = array('main' => DOL_URL_ROOT);
$conf->file->dol_document_root['alt'] = DOL_DOCUMENT_ROOT.'/custom';
$conf->file->dol_url_root['alt'] = DOL_URL_ROOT.'/custom';
$conf->db->dolibarr_main_db_collation = isset($dolibarr_main_db_collation) ? $dolibarr_main_db_collation : 'utf8_unicode_ci';
$conf->modules = array('takepos' => 1, 'takeposguard' => 1, 'facture' => 1, 'product' => 1, 'societe' => 1, 'bank' => 1, 'stock' => 1);
$conf->global->TAKEPOSGUARD_ENABLE = 1;
$conf->global->TAKEPOSGUARD_LOCK_TIMEOUT = 10;
$conf->global->MAIN_MAX_DECIMALS_TOT = 8;
$conf->global->MAIN_MAX_DECIMALS_UNIT = 8;
$conf->global->CASHDESK_ID_BANKACCOUNT_CASH1 = 1;
$conf->global->CASHDESK_ID_WAREHOUSE1 = 1;
$conf->global->STOCK_DISALLOW_NEGATIVE_TRANSFER = 1;
$conf->global->MAIN_DISABLE_PDF_AUTOUPDATE = 1;
$conf->facture = (object) array('dir_output' => sys_get_temp_dir().'/'.$nativePrefix);
foreach (array_keys($conf->modules) as $module) {
	if (!isset($conf->$module)) { $conf->$module = new stdClass(); }
	$conf->$module->enabled = 1; // Legacy core checks coexist with isModEnabled().
}
$conf->productbatch = (object) array('enabled' => 0);
$_SESSION = array('takeposterminal' => 1);
$_SERVER['PHP_SELF'] = '/dolibarr/custom/takeposguard/test/native_acceptance.php';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$langs = new Translate('', $conf);
$langs->setDefaultLang('en_US');
$langs->loadLangs(array('main', 'bills', 'stocks', 'banks', 'takeposguard@takeposguard'));
$hookmanager = new HookManager(null);
$mysoc = new Societe(null);
$mysoc->country_id = 1;
$mysoc->country_code = 'FR';
$user = new User(null);
$user->id = 1;
$user->login = 'acceptance';
$user->rights = (object) array('takepos' => (object) array('run' => 1), 'facture' => (object) array('creer' => 1),
	'takeposguard' => (object) array('maintenance' => (object) array('write' => 1)));
try {
	if ($dolibarr_main_db_type !== 'mysqli') { throw new RuntimeException('mysqli required'); }
	$db = new NativeAcceptanceDB($dolibarr_main_db_type, $dolibarr_main_db_host, $dolibarr_main_db_user,
		$dolibarr_main_db_pass, $dolibarr_main_db_name, empty($dolibarr_main_db_port) ? 0 : (int) $dolibarr_main_db_port);
	if (!$db->connected) { throw new RuntimeException('Connection unavailable'); }
} catch (Throwable $error) {
	fwrite(STDERR, "Native acceptance database unavailable; check local configuration.\n");
	exit(1);
}
register_shutdown_function('dol_shutdown');

function nativeQuery($sql)
{
	global $db;
	$result = $db->query($sql, 1);
	if (!$result) { throw new RuntimeException('Isolated fixture SQL failed: '.$db->lasterror()); }
	return $result;
}
function nativeOne($sql)
{
	global $db;
	return $db->fetch_object(nativeQuery($sql));
}
function nativeToken($number)
{
	return sprintf('12345678-1234-4234-8234-%012x', $number);
}

/** Extract unchanged valid action only. Delimiters must fail closed on upstream changes. */
function nativePaymentBlock()
{
	$source = str_replace("\r\n", "\n", file_get_contents(DOL_DOCUMENT_ROOT.'/takepos/invoice.php'));
	$start = strpos($source, "\tif (\$action == 'valid' && \$user->hasRight('facture', 'creer')) {");
	$end = $start === false ? false : strpos($source, "\n\t\$creditnote = null;", $start);
	if ($start === false || $end === false) { throw new RuntimeException('Native action boundaries changed; re-inspect upstream'); }
	return substr($source, $start, $end - $start);
}

function nativePay($id, $token, $amount = 0, $lots = false, $failure = '', $barrier = null)
{
	global $db, $conf, $user, $langs, $hookmanager, $mysoc;
	$conf->modules['productbatch'] = $lots ? 1 : 0;
	$conf->productbatch->enabled = $lots ? 1 : 0;
	$conf->global->CASHDESK_ID_BANKACCOUNT_CASH1 = $failure === 'bank' ? 0 : $conf->entity;
	$conf->global->CASHDESK_ID_WAREHOUSE1 = $conf->entity;
	$db->failPayment = $failure === 'payment';
	$db->interruptCommit = $failure === 'crash-rollback';
	$validationBefore = $db->validations;
	$_GET = array('takeposguard_token' => $token, 'pay' => 'LIQ', 'amount' => (string) $amount);
	$object = new Facture($db);
	if ($object->fetch($id) <= 0) { throw new RuntimeException('Native invoice fixture fetch failed: '.$object->error); }
	$loadedStatus = $object->status;
	$action = 'valid';
	$guard = new ActionsTakeposguard($db);
	ob_start();
	$reshook = $guard->doActions(array('context' => 'takeposinvoice'), $object, $action, $hookmanager);
	if ($barrier) { $barrier(array('entered' => $reshook === 0, 'loaded_status' => $loadedStatus)); }
	if ($reshook === 0) {
		$placeid = $id;
		$pay = 'LIQ';
		$amountofpayment = $amount;
		$paiementid = $conf->entity === 2 ? 5 : 4;
		eval(nativePaymentBlock()); // Native code, native Facture/Paiement/Bank/MouvementStock, real DoliDB.
		if ($failure === 'crash-commit') { exit(0); }
		$guard->completeTakePosInvoiceHeader(array('context' => 'takeposinvoice'), $object, $action, $hookmanager);
	}
	$html = ob_get_clean();
	$storage = new TakeposguardStorage($db);
	$attempt = $storage->fetchAttempt($id, $token);
	return array('entered' => $reshook === 0, 'status' => $attempt ? $attempt->status : null,
		'error' => $guard->error, 'native_errors' => strip_tags($html), 'validations' => $db->validations - $validationBefore);
}
