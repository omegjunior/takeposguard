<?php
/** Read-only source contract checks; not a certification of an arbitrary core version. */
if (PHP_SAPI !== 'cli') { exit(2); }
$root = isset($argv[1]) ? realpath($argv[1]) : realpath(__DIR__.'/../../..');
$checks = 0;
function contractCheck($condition, $message)
{
	global $checks;
	if (!$condition) { throw new RuntimeException($message.'; inspect this core before enabling the guard.'); }
	$checks++;
}
function contractSource($path)
{
	global $root;
	contractCheck($root && is_file($root.'/'.$path), 'Missing core source '.$path);
	return str_replace("\r\n", "\n", file_get_contents($root.'/'.$path));
}
try {
	$invoice = contractSource('takepos/invoice.php');
	$start = strpos($invoice, "\tif (\$action == 'valid' && \$user->hasRight('facture', 'creer')) {");
	$end = $start === false ? false : strpos($invoice, "\n\t\$creditnote = null;", $start);
	$before = strpos($invoice, "executeHooks('doActions'");
	$after = strpos($invoice, "executeHooks('completeTakePosInvoiceHeader'");
	contractCheck($start !== false && $end !== false && $before !== false && $after !== false
		&& $before < $start && $end < $after, 'Payment hook order changed');
	contractCheck(strpos($invoice, 'takeposinvoice') !== false
		&& strpos(substr($invoice, $before, $start - $before), 'if (empty($reshook))') !== false, 'Native replacement-hook contract changed');
	$block = substr($invoice, $start, $end - $start);
	foreach (array('$db->begin()', '$invoice->validate(', '$payment->create(', '$payment->addPaymentToBank(', '$db->commit()', '$db->rollback()') as $fragment) {
		contractCheck(strpos($block, $fragment) !== false, 'Missing native payment operation '.$fragment);
	}
	contractCheck(strpos($block, '$db->begin()') < strpos($block, '$invoice->validate(')
		&& strpos($block, '$payment->create(') < strpos($block, '$db->commit()'), 'Native transaction boundaries changed');
	contractCheck(strpos($block, "!isModEnabled('productbatch')") !== false
		&& strpos($block, "isModEnabled('productbatch') && \$allowstockchange") !== false
		&& strpos($block, '$mouvP->livraison(') !== false, 'Stock branches changed');
	$pay = contractSource('takepos/pay.php');
	foreach (array('Validate', 'ValidateStripeTerminal', 'ValidateSumup') as $function) {
		contractCheck(preg_match('/function\s+'.preg_quote($function, '/').'\s*\(/', $pay), 'Payment function changed: '.$function);
	}
	$index = contractSource('takepos/index.php');
	contractCheck(preg_match('/function\s+DirectPayment\s*\(/', $index), 'DirectPayment changed');
	$facture = contractSource('compta/facture/class/facture.class.php');
	foreach (array('DRAFT' => 0, 'VALIDATED' => 1, 'CLOSED' => 2) as $status => $value) {
		contractCheck(preg_match('/const\s+STATUS_'.$status.'\s*=\s*'.$value.'\s*;/', $facture), 'Invoice status changed: '.$status);
	}
	$transactions = contractSource('core/db/DoliDB.class.php');
	contractCheck(strpos($transactions, 'if (!$this->transaction_opened)') !== false
		&& substr_count($transactions, '$this->transaction_opened <= 1') >= 2
		&& substr_count($transactions, '$this->transaction_opened--;') >= 2, 'Native nested transaction counters changed');
	$main = contractSource('main.inc.php');
	contractCheck(strpos($main, "register_shutdown_function('dol_shutdown')") !== false, 'Native shutdown registration changed');
	echo $checks." source contract checks passed (not full version qualification).\n";
	echo 'Native payment block SHA-256: '.hash('sha256', $block)."\n";
} catch (Throwable $error) {
	fwrite(STDERR, $error->getMessage()."\n");
	exit(1);
}
