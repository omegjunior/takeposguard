<?php
/** php test/native_acceptance.php --mysql : genuine native objects in isolated tables. */
if (PHP_SAPI !== 'cli' || (!in_array('--mysql', $argv, true) && !in_array('--worker', $argv, true))) { exit(2); }
$worker = in_array('--worker', $argv, true);
$nativePrefix = $worker ? $argv[2] : 'tpg_native_'.bin2hex(random_bytes(6)).'_';
require __DIR__.'/native_bootstrap.php';
$dir = sys_get_temp_dir().'/'.$nativePrefix;
function nativeWait($path)
{
	$until = microtime(true) + 15;
	while (!is_file($path)) {
		if (microtime(true) > $until) { throw new RuntimeException('Acceptance barrier timed out'); }
		usleep(10000);
	}
}
if ($worker) {
	if (count($argv) !== 9 || !preg_match('/^[ab]$/D', $argv[8]) || !is_dir($dir)) { exit(2); }
	try {
		nativeWait($dir.'/start');
		$result = nativePay((int) $argv[3], $argv[4], (float) $argv[5], $argv[6] === 'lots', $argv[7],
			function ($result) use ($dir, $argv) {
				file_put_contents($dir.'/ready-'.$argv[8], json_encode($result));
				if ($result['entered']) { nativeWait($dir.'/release'); }
			});
		echo json_encode($result)."\n";
		exit(0);
	} catch (Throwable $error) { fwrite(STDERR, $error->getMessage()."\n"); exit(1); }
}
$tables = array();
$workers = array();
$checks = 0;
$exitCode = 0;
function nativeCheck($condition, $message)
{
	global $checks;
	if (!$condition) { throw new RuntimeException($message); }
	$checks++;
}
function nativeInvoice($id, $lots = false, $entity = 1)
{
	nativeQuery('UPDATE '.MAIN_DB_PREFIX.'product SET tobatch='.($lots ? 1 : 0).' WHERE rowid='.$entity);
	nativeQuery('INSERT INTO '.MAIN_DB_PREFIX."facture(rowid,ref,entity,fk_soc,module_source,total_ht,total_ttc,datef,multicurrency_code,multicurrency_tx) VALUES (".$id.",'TPG-TEST-".$id."',".$entity.",".$entity.",'takepos',100,100,CURRENT_TIMESTAMP,'EUR',1)");
	nativeQuery('INSERT INTO '.MAIN_DB_PREFIX.'facturedet(fk_facture,fk_product,qty,subprice,price,total_ht,total_ttc,product_type,fk_warehouse,batch) VALUES ('.$id.','.$entity.',2,50,50,100,100,0,'.$entity.",".($lots ? "'LOT-A'" : "''").')');
}
function nativeEffects($id)
{
	return array(
		'payments' => (int) nativeOne('SELECT COUNT(*) AS total FROM '.MAIN_DB_PREFIX.'paiement_facture WHERE fk_facture='.$id)->total,
		'bank' => (int) nativeOne('SELECT COUNT(*) AS total FROM '.MAIN_DB_PREFIX.'bank b JOIN '.MAIN_DB_PREFIX.'paiement p ON p.fk_bank=b.rowid JOIN '.MAIN_DB_PREFIX.'paiement_facture pf ON pf.fk_paiement=p.rowid WHERE pf.fk_facture='.$id)->total,
		'movements' => (int) nativeOne('SELECT COUNT(*) AS total FROM '.MAIN_DB_PREFIX."stock_mouvement WHERE origintype='facture' AND fk_origin=".$id)->total,
		'quantity' => (float) nativeOne('SELECT COALESCE(SUM(value),0) AS quantity FROM '.MAIN_DB_PREFIX."stock_mouvement WHERE origintype='facture' AND fk_origin=".$id)->quantity,
		'status' => (int) nativeOne('SELECT fk_statut FROM '.MAIN_DB_PREFIX.'facture WHERE rowid='.$id)->fk_statut,
	);
}
function nativeWorker($id, $token, $lots, $mode, $tag)
{
	$command = implode(' ', array_map('escapeshellarg', array(PHP_BINARY, __FILE__, '--worker', MAIN_DB_PREFIX,
		(string) $id, $token, '0', $lots ? 'lots' : 'plain', $mode, $tag)));
	$process = proc_open($command, array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes,
		null, null, array('bypass_shell' => true));
	if (!is_resource($process)) { throw new RuntimeException('Cannot start acceptance worker'); }
	fclose($pipes[0]);
	return array($process, $pipes);
}
function nativeFinish($worker)
{
	$output = stream_get_contents($worker[1][1]);
	$errors = stream_get_contents($worker[1][2]);
	fclose($worker[1][1]); fclose($worker[1][2]);
	$status = proc_close($worker[0]);
	if ($status !== 0 || $errors !== '') { throw new RuntimeException('Native worker failed: '.$errors); }
	return trim($output) === '' ? null : json_decode(trim($output), true);
}
function nativeResetBarriers()
{
	global $dir;
	foreach (array('start', 'release', 'ready-a', 'ready-b') as $name) {
		if (is_file($dir.'/'.$name)) { unlink($dir.'/'.$name); }
	}
}
try {
	mkdir($dir);
	$outsideDenied = false;
	try { $db->query('SELECT rowid FROM outside_fixture'); }
	catch (RuntimeException $error) { $outsideDenied = true; }
	nativeCheck($outsideDenied, 'Fixture refuses SQL outside its random namespace before database execution');
	$schemas = array('facture', 'facturedet', 'facture_extrafields', 'facturedet_extrafields', 'societe', 'societe_extrafields',
		'societe_remise_except', 'c_country', 'c_regions', 'c_departements', 'c_typent', 'c_forme_juridique', 'c_units', 'c_paiement', 'c_payment_term', 'c_incoterms', 'c_input_reason',
		'product', 'product_extrafields', 'product_stock', 'product_batch', 'product_lot', 'stock_mouvement', 'entrepot',
		'paiement', 'paiement_facture', 'bank', 'bank_url', 'bank_account', 'bank_account_extrafields', 'extrafields',
		'element_element', 'element_contact', 'ecm_files', 'user', 'user_extrafields', 'accounting_journal');
	foreach ($schemas as $table) {
		$tables[] = MAIN_DB_PREFIX.$table;
		$schema = preg_replace('/--[^\r\n]*/', '', file_get_contents(DOL_DOCUMENT_ROOT.'/install/mysql/tables/llx_'.$table.'.sql'));
		$schemaPath = tempnam(sys_get_temp_dir(), 'tpg-native-schema-');
		try {
			file_put_contents($schemaPath, $schema);
			if (run_sql($schemaPath, 1, 0, 1, '', 'none') <= 0) {
				throw new RuntimeException('Native fixture schema failed: '.$table.' '.$db->lasterror());
			}
		} finally {
			unlink($schemaPath);
		}
	}
	foreach (array('payment_attempt', 'invoice_lock') as $table) {
		$tables[] = MAIN_DB_PREFIX.'takeposguard_'.$table;
		foreach (array('.sql', '.key.sql') as $suffix) {
			if (run_sql(__DIR__.'/../sql/llx_takeposguard_'.$table.$suffix, 1, 0, 1, '', 'none') <= 0) {
				throw new RuntimeException('Guard fixture schema failed');
			}
		}
	}
	nativeQuery('INSERT INTO '.MAIN_DB_PREFIX."societe(rowid,nom,entity,client) VALUES (1,'Acceptance customer',1,1)");
	nativeQuery('INSERT INTO '.MAIN_DB_PREFIX."c_paiement(id,code,libelle,type,active) VALUES (4,'LIQ','Cash',2,1)");
	nativeQuery('INSERT INTO '.MAIN_DB_PREFIX."bank_account(rowid,ref,label,entity,fk_pays,currency_code) VALUES (1,'TEST','Acceptance bank',1,1,'EUR')");
	nativeQuery('INSERT INTO '.MAIN_DB_PREFIX."entrepot(rowid,ref,lieu,entity) VALUES (1,'TEST','Acceptance warehouse',1)");
	nativeQuery('INSERT INTO '.MAIN_DB_PREFIX."product(rowid,ref,label,entity,fk_product_type,tobatch) VALUES (1,'TEST','Acceptance product',1,0,0)");
	nativeQuery('INSERT INTO '.MAIN_DB_PREFIX.'product_stock(rowid,fk_product,fk_entrepot,reel) VALUES (1,1,1,100)');
	nativeQuery('INSERT INTO '.MAIN_DB_PREFIX."product_batch(rowid,fk_product_stock,batch,qty) VALUES (1,1,'LOT-A',100)");
	nativeInvoice(1);
	$result = nativePay(1, nativeToken(1));
	nativeCheck($result['entered'] && $result['status'] === 'SUCCESS', 'Native payment failed: '.json_encode($result));
	nativeCheck((int) nativeOne('SELECT COUNT(*) AS total FROM '.MAIN_DB_PREFIX.'paiement')->total === 1, 'One native payment');
	nativeCheck((int) nativeOne('SELECT COUNT(*) AS total FROM '.MAIN_DB_PREFIX.'bank')->total === 1, 'One native bank entry');
	nativeCheck((int) nativeOne('SELECT COUNT(*) AS total FROM '.MAIN_DB_PREFIX.'stock_mouvement')->total === 1, 'One native stock movement');
	nativeCheck($result['validations'] === 1, 'One actual native validation UPDATE');
	nativeCheck(!nativePay(1, nativeToken(1))['entered'] && nativeEffects(1)['payments'] === 1, 'Total payment replay produces no native effects');
	foreach (array(false, true) as $lots) {
		$id = $lots ? 3 : 2;
		nativeInvoice($id, $lots);
		$quantityBefore = (float) nativeOne('SELECT reel FROM '.MAIN_DB_PREFIX.'product_stock WHERE rowid=1')->reel;
		$batchBefore = (float) nativeOne('SELECT qty FROM '.MAIN_DB_PREFIX.'product_batch WHERE rowid=1')->qty;
		$initial = nativePay($id, nativeToken($id * 10), 40, $lots);
		$before = nativeEffects($id);
		nativeCheck($initial['status'] === 'SUCCESS' && $before['payments'] === 1 && $before['status'] === 1, 'Partial native payment committed');
		nativeCheck(!nativePay($id, nativeToken($id * 10), 40, $lots)['entered'] && nativeEffects($id) === $before, 'Partial replay changes no payment/bank/stock');
		$balance = nativePay($id, nativeToken($id * 10 + 1), 60, $lots);
		$after = nativeEffects($id);
		nativeCheck($balance['status'] === 'SUCCESS' && $after['payments'] === 2 && $after['bank'] === 2 && $after['status'] === 2, 'New token legitimately pays balance');
		nativeCheck($after['movements'] === 1 && $after['quantity'] === -2.0, 'Partial native payment never repeats original stock '.($lots ? 'with lots' : 'without lots'));
		nativeCheck((float) nativeOne('SELECT reel FROM '.MAIN_DB_PREFIX.'product_stock WHERE rowid=1')->reel === $quantityBefore - 2,
			'Actual warehouse quantity decremented once across partial payments');
		nativeCheck((float) nativeOne('SELECT qty FROM '.MAIN_DB_PREFIX.'product_batch WHERE rowid=1')->qty === $batchBefore - ($lots ? 2 : 0),
			'Actual batch quantity follows enabled lot management only once: lots='.(int) $lots.' before='.$batchBefore.' after='.nativeOne('SELECT qty FROM '.MAIN_DB_PREFIX.'product_batch WHERE rowid=1')->qty);
	}
	foreach (array(false, true) as $lots) {
		foreach (array(true, false) as $sameToken) {
			$id = 10 + ($lots ? 2 : 0) + ($sameToken ? 0 : 1);
			nativeInvoice($id, $lots);
			$quantityBefore = (float) nativeOne('SELECT reel FROM '.MAIN_DB_PREFIX.'product_stock WHERE rowid=1')->reel;
			nativeResetBarriers();
			$token = nativeToken($id * 10);
			$workers = array(nativeWorker($id, $token, $lots, '', 'a'), nativeWorker($id, $sameToken ? $token : nativeToken($id * 10 + 1), $lots, '', 'b'));
			file_put_contents($dir.'/start', 'start');
			nativeWait($dir.'/ready-a'); nativeWait($dir.'/ready-b');
			$a = json_decode(file_get_contents($dir.'/ready-a'), true);
			$b = json_decode(file_get_contents($dir.'/ready-b'), true);
			nativeCheck((int) $a['entered'] + (int) $b['entered'] === 1 && (int) $a['loaded_status'] === 0 && (int) $b['loaded_status'] === 0,
				'Two truly concurrent draft snapshots authorize only one action');
			file_put_contents($dir.'/release', 'release');
			$results = array(nativeFinish($workers[0]), nativeFinish($workers[1])); $workers = array();
			nativeCheck($results[0]['validations'] + $results[1]['validations'] === 1, 'Concurrent requests validate once');
			$effects = nativeEffects($id);
			nativeCheck($effects['payments'] === 1 && $effects['bank'] === 1 && $effects['movements'] === 1 && $effects['quantity'] === -2.0,
				'Concurrent native payment/bank/stock exactly once');
			nativeCheck((float) nativeOne('SELECT reel FROM '.MAIN_DB_PREFIX.'product_stock WHERE rowid=1')->reel === $quantityBefore - 2, 'Concurrent requests decrement real warehouse once');
		}
	}
	foreach (array('bank', 'payment', 'stock', 'lines') as $offset => $failure) {
		$id = 20 + $offset;
		nativeInvoice($id, $failure === 'stock');
		if ($failure === 'stock') {
			nativeQuery('UPDATE '.MAIN_DB_PREFIX.'product_stock SET reel=1 WHERE rowid=1');
			nativeQuery('UPDATE '.MAIN_DB_PREFIX.'product_batch SET qty=1 WHERE rowid=1');
		}
		if ($failure === 'lines') { nativeQuery('DELETE FROM '.MAIN_DB_PREFIX.'facturedet WHERE fk_facture='.$id); }
		$quantityBefore = (float) nativeOne('SELECT reel FROM '.MAIN_DB_PREFIX.'product_stock WHERE rowid=1')->reel;
		$paymentsBefore = (int) nativeOne('SELECT COUNT(*) AS total FROM '.MAIN_DB_PREFIX.'paiement')->total;
		$bankBefore = (int) nativeOne('SELECT COUNT(*) AS total FROM '.MAIN_DB_PREFIX.'bank')->total;
		$result = nativePay($id, nativeToken($id * 10), 0, $failure === 'stock', $failure);
		$effects = nativeEffects($id);
		nativeCheck($result['status'] === 'FAILED' && $effects['status'] === 0 && $effects['payments'] === 0
			&& $effects['bank'] === 0 && $effects['movements'] === 0, 'Native rollback removes all effects: '.$failure.' '.json_encode($result));
		nativeCheck((new TakeposguardStorage($db))->fetchInvoiceLock($id) === null, 'Failure releases guard lock: '.$failure);
		nativeCheck((float) nativeOne('SELECT reel FROM '.MAIN_DB_PREFIX.'product_stock WHERE rowid=1')->reel === $quantityBefore, 'Native rollback restores warehouse quantity: '.$failure);
		nativeCheck((int) nativeOne('SELECT COUNT(*) AS total FROM '.MAIN_DB_PREFIX.'paiement')->total === $paymentsBefore
			&& (int) nativeOne('SELECT COUNT(*) AS total FROM '.MAIN_DB_PREFIX.'bank')->total === $bankBefore, 'Rollback leaves no orphan payment or bank entry: '.$failure);
	}
	nativeQuery('UPDATE '.MAIN_DB_PREFIX.'product_stock SET reel=100 WHERE rowid=1');
	nativeQuery('UPDATE '.MAIN_DB_PREFIX.'product_batch SET qty=100 WHERE rowid=1');
	foreach (array('crash-commit', 'crash-rollback') as $offset => $mode) {
		$id = 25 + $offset; $token = nativeToken($id * 10);
		nativeInvoice($id, true); nativeResetBarriers();
		$workers = array(nativeWorker($id, $token, true, $mode, 'a'));
		file_put_contents($dir.'/start', 'start'); nativeWait($dir.'/ready-a'); file_put_contents($dir.'/release', 'release');
		nativeFinish($workers[0]); $workers = array();
		$storage = new TakeposguardStorage($db);
		nativeCheck($storage->fetchAttempt($id, $token)->status === 'PROCESSING', 'PHP interruption does not assume success');
		nativeQuery('UPDATE '.MAIN_DB_PREFIX."takeposguard_invoice_lock SET expires_at='2020-01-01 00:00:00' WHERE entity=1 AND fk_invoice=".$id);
		$service = new TakeposguardAttemptService($db, $storage);
		nativeCheck($service->resolve($token, 1) === ($mode === 'crash-commit' ? 'SUCCESS' : 'FAILED'), 'Crash result reconciled from actual native effects');
		$effects = nativeEffects($id);
		nativeCheck($effects['payments'] === ($mode === 'crash-commit' ? 1 : 0) && $effects['movements'] === ($mode === 'crash-commit' ? 1 : 0)
			&& $storage->fetchInvoiceLock($id) === null, 'Crash commit/rollback preserves consistent native effects and releases expired lock');
	}
	nativeInvoice(28);
	$conf->global->TAKEPOSGUARD_ENABLE = 0;
	$disabled = nativePay(28, 'absent');
	nativeCheck($disabled['entered'] && $disabled['status'] === null && nativeEffects(28)['payments'] === 1, 'Disabled guard leaves native payment untouched without token');
	$conf->global->TAKEPOSGUARD_ENABLE = 1;
	// Real native effects in a second entity with the same operation UUID.
	nativeQuery('INSERT INTO '.MAIN_DB_PREFIX."c_paiement(id,code,libelle,type,active,entity) VALUES (5,'LIQ','Cash',2,1,2)");
	nativeQuery('INSERT INTO '.MAIN_DB_PREFIX."societe(rowid,nom,entity,client) VALUES (2,'Entity two customer',2,1)");
	nativeQuery('INSERT INTO '.MAIN_DB_PREFIX."bank_account(rowid,ref,label,entity,fk_pays,currency_code) VALUES (2,'TEST2','Entity two bank',2,1,'EUR')");
	nativeQuery('INSERT INTO '.MAIN_DB_PREFIX."entrepot(rowid,ref,lieu,entity) VALUES (2,'TEST2','Entity two warehouse',2)");
	nativeQuery('INSERT INTO '.MAIN_DB_PREFIX."product(rowid,ref,label,entity,fk_product_type,tobatch) VALUES (2,'TEST2','Entity two product',2,0,0)");
	nativeQuery('INSERT INTO '.MAIN_DB_PREFIX.'product_stock(rowid,fk_product,fk_entrepot,reel) VALUES (2,2,2,100)');
	nativeInvoice(30, false, 2);
	$conf->entity = 2;
	$entityTwo = nativePay(30, nativeToken(1));
	nativeCheck($entityTwo['status'] === 'SUCCESS' && nativeEffects(30)['payments'] === 1 && nativeEffects(30)['movements'] === 1, 'Native payment accepts same token in another entity');
	nativeCheck((new TakeposguardStorage($db))->fetchAttempt(1, nativeToken(1)) === null, 'Second entity cannot inspect first entity outcome');
	$conf->entity = 1;
	nativeCheck((new TakeposguardStorage($db))->fetchAttempt(1, nativeToken(1))->status === 'SUCCESS'
		&& (new TakeposguardStorage($db))->fetchAttempt(30, nativeToken(1)) === null, 'Original entity idempotence remains intact');
	nativeCheck((float) nativeOne('SELECT reel FROM '.MAIN_DB_PREFIX.'product_stock WHERE rowid=2')->reel === 98.0, 'Second entity warehouse decremented once');
	echo $checks." native acceptance checks passed.\n";
} catch (Throwable $error) {
	fwrite(STDERR, 'FAILED: '.$error->getMessage()."\n");
	$exitCode = 1;
} finally {
	foreach ($workers as $process) {
		if (is_resource($process[0])) {
			proc_terminate($process[0]);
			foreach ($process[1] as $pipe) { if (is_resource($pipe)) { fclose($pipe); } }
			proc_close($process[0]);
		}
	}
	if ($db->transaction_opened) { $db->rollback(); }
	foreach (array_reverse($tables) as $table) {
		if (strpos($table, MAIN_DB_PREFIX) !== 0) { throw new RuntimeException('Unsafe fixture cleanup'); }
		if (!$db->query('DROP TABLE IF EXISTS '.$table)) {
			fwrite(STDERR, 'Fixture cleanup failed for '.$table."\n");
			$exitCode = 1;
		}
	}
	$db->close();
	nativeResetBarriers();
	if (is_dir($dir)) { rmdir($dir); }
}
exit($exitCode);
