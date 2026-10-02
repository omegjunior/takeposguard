<?php
/** Native stock block with spies, plus shutdown restoration; no stock DB writes. */
require_once __DIR__.'/../class/takeposguardpaymentpolicy.class.php';
$conf = (object) array('global' => new stdClass());
if (isset($argv[1]) && $argv[1] === '--shutdown') {
	$present = isset($argv[2]) && $argv[2] === 'present';
	if ($present) {
		$conf->global->CASHDESK_NO_DECREASE_STOCK1 = null;
	}
	$policy = new TakeposguardPaymentPolicy();
	$policy->suppressStock('1');
	register_shutdown_function(function () use ($conf, $present) {
		$exists = property_exists($conf->global, 'CASHDESK_NO_DECREASE_STOCK1');
		echo ($exists === $present && (!$exists || $conf->global->CASHDESK_NO_DECREASE_STOCK1 === null)) ? 'restored' : 'FAILED';
	});
	exit(0); // Earlier registered restore runs before the assertion callback.
}
$root = sys_get_temp_dir().'/tpg-stock-'.bin2hex(random_bytes(6));
$stub = $root.'/product/stock/class/mouvementstock.class.php';
$checks = 0;
$exitCode = 0;
function partialCheck($condition, $message)
{
	global $checks;
	if (!$condition) {
		throw new RuntimeException($message);
	}
	$checks++;
}
function getDolGlobalString($key) { global $conf; return isset($conf->global->$key) ? (string) $conf->global->$key : ''; }
function getDolGlobalInt($key) { return (int) getDolGlobalString($key); }
function isModEnabled($module) { return true; }
function dol_syslog($message) {}
function dol_now() { return time(); }
function dol_print_date($time, $format) { return 'test'; }
function dol_htmloutput_errors($error, $errors, $flag) { throw new RuntimeException('Native stock block error'); }
class Productbatch
{
	public $id = 10;
	public $batch = 'LOT-A';
	public function find(...$args) { return 1; }
}
try {
	$source = file_get_contents(__DIR__.'/../../../takepos/invoice.php');
	$source = str_replace("\r\n", "\n", $source);
	$start = strpos($source, "\t\t\$warehouseid = 0;\n\t\t// Update stock for batch products");
	$end = $start === false ? false : strpos($source, "\t\tif (!\$error && \$res >= 0) {\n\t\t\t\$db->commit();", $start);
	partialCheck($start !== false && $end !== false, 'Native stock block boundaries changed; re-inspect this version');
	$block = substr($source, $start, $end - $start);
	$configStart = strpos($source, "\t\t\$constantforkey = 'CASHDESK_NO_DECREASE_STOCK'");
	$configEnd = $configStart === false ? false : strpos($source, "\n\n", $configStart);
	$header = strpos($source, "executeHooks('completeTakePosInvoiceHeader'");
	partialCheck($configStart !== false && $configEnd !== false && $configEnd < $start && $header > $end,
		'Native configuration is read before stock dispatch and header runs after native transaction');
	$configuration = substr($source, $configStart, $configEnd - $configStart);
	mkdir(dirname($stub), 0700, true);
	file_put_contents($stub, '<?php class MouvementStock { public static $calls = array(); public function setOrigin($type, $id) {} public function livraison(...$args) { self::$calls[] = $args; return 1; } }');
	define('DOL_DOCUMENT_ROOT', $root);
	$_SESSION['takeposterminal'] = 1;
	$conf->global->CASHDESK_ID_WAREHOUSE1 = 5;
	$langs = new class { public function trans($key) { return $key; } };
	$user = new stdClass();
	$db = new stdClass(); // Passed to spies by the unchanged native block.
	$invoice = (object) array('id' => 1, 'element' => 'facture', 'ref' => 'TEST', 'lines' => array(
		(object) array('fk_product' => 1, 'fk_warehouse' => 5, 'qty' => 2, 'price' => 10, 'batch' => 'LOT-A'),
		(object) array('fk_product' => 2, 'fk_warehouse' => 5, 'qty' => 3, 'price' => 10, 'batch' => ''),
	));
	$error = 0;
	$res = 1;
	eval($configuration);
	// Execute only the trusted installed native stock block; spies replace its writers.
	eval($block);
	partialCheck(count(MouvementStock::$calls) === 2 && MouvementStock::$calls[0][3] === 2
		&& MouvementStock::$calls[1][3] === 3, 'Initial native loop dispatches each line once with its quantity');
	$policy = new TakeposguardPaymentPolicy();
	partialCheck($policy->suppressStock('1'), 'Partial-payment stock suppression prepared');
	MouvementStock::$calls = array();
	eval($configuration);
	eval($block);
	partialCheck(count(MouvementStock::$calls) === 0, 'Native batch and nonbatch lines make no stock call on later partial payment');
	$policy->restore();
	partialCheck(!property_exists($conf->global, 'CASHDESK_NO_DECREASE_STOCK1'), 'Absent setting restored after native stock block');
	foreach (array('present', 'absent') as $mode) {
		$command = escapeshellarg(PHP_BINARY).' '.escapeshellarg(__FILE__).' --shutdown '.escapeshellarg($mode);
		$process = proc_open($command, array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
		if (!is_resource($process)) {
			throw new RuntimeException('Shutdown probe could not start');
		}
		fclose($pipes[0]);
		$output = stream_get_contents($pipes[1]);
		$errorOutput = stream_get_contents($pipes[2]);
		fclose($pipes[1]); fclose($pipes[2]);
		partialCheck(proc_close($process) === 0 && $output === 'restored' && $errorOutput === '', 'Early exit restores '.$mode.' setting without finalizing payment');
	}
	echo $checks." native stock dispatch/shutdown checks passed (spies, no stock writes).\n";
} catch (Throwable $exception) {
	fwrite(STDERR, 'FAILED: '.$exception->getMessage()."\n");
	$exitCode = 1;
} finally {
	if (is_file($stub)) { unlink($stub); }
	foreach (array(dirname($stub), $root.'/product/stock', $root.'/product', $root) as $directory) {
		if (is_dir($directory)) { rmdir($directory); }
	}
}
exit($exitCode);
