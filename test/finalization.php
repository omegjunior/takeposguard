<?php
/** php test/finalization.php: conservative decisions and failure handling, no DB/payment. */
require_once __DIR__.'/../class/takeposguardfinalizer.class.php';
$cases = array(
	array(0, 100.0, 1, 100.0, 0, true, null, 'SUCCESS'),
	array(0, 100.0, 2, 0.0, 1, false, 100.0, 'SUCCESS'),
	array(1, 100.0, 1, 60.0, 1, false, 40.0, 'SUCCESS'),
	array(1, -100.0, 1, -60.0, 1, false, -40.0, 'SUCCESS'),
	array(1, 100.0, 2, 0.0, 0, true, null, 'SUCCESS'),
	array(1, 100.0, 1, 100.0, 1, false, 40.0, 'SUCCESS'),
	array(0, 100.0, 0, 100.0, 0, true, null, 'FAILED'),
	array(1, 100.0, 1, 100.0, 0, true, null, 'FAILED'),
	array(0, 100.0, 0, 60.0, 1, false, 40.0, 'BLOCKED'),
	array(1, 100.0, 1, 140.0, 0, true, null, 'BLOCKED'),
	array(1, 100.0, 1, 50.0, 2, false, 40.0, 'BLOCKED'),
	array(0, 100.0, 1, 100.0, -1, false, null, 'BLOCKED'),
	array(1, 100.0, 1, 60.0, 1, false, null, 'BLOCKED'),
	array(1, 100.0, 1, 60.0, 1, false, -40.0, 'BLOCKED'),
	array(1, 100.0, 1, 60.0, 1, false, INF, 'BLOCKED'),
	array(0, 100.0, 3, 100.0, 0, true, null, 'BLOCKED'),
	array(1, 100.0, 0, 100.0, 0, true, null, 'BLOCKED'),
);
$checks = 0;
function outcomeCheck($condition, $label)
{
	global $checks;
	if (!$condition) { throw new RuntimeException($label); }
	$checks++;
}
class OutcomeStorage
{
	public $attempt;
	public $snapshot;
	public $persist = true;
	public $writes = 0;
	public function fetchAttempt($id, $token) { return $this->attempt; }
	public function getInvoiceSnapshot($id) { return $this->snapshot; }
	public function completeAttempt($id, $token, $status, $data) { $this->writes++; return $this->persist; }
}
try {
	foreach ($cases as $case) {
		$expected = array_pop($case);
		outcomeCheck(call_user_func_array(array('TakeposguardFinalizer', 'decide'), $case) === $expected, 'Evidence decision '.$checks);
	}
	$db = (object) array('connected' => true, 'transaction_opened' => 0);
	$storage = new OutcomeStorage();
	$storage->attempt = (object) array('status' => 'PROCESSING', 'remain_before' => 100,
		'invoice_status_before' => 0, 'payment_count_before' => 0, 'last_payment_before' => 0);
	$storage->snapshot = array('status' => 1, 'remain' => 100, 'payment_count' => 0, 'last_payment' => 0);
	$lock = new class {
		public $held = true;
		public function holdsInvoice($id, $token) { return $this->held; }
	};
	$finalizer = new TakeposguardFinalizer($db, $storage);
	$db->connected = false;
	outcomeCheck($finalizer->reconcile(1, 'token', $lock) === false && !$storage->writes, 'Closed native session never assumes success');
	$db->connected = true; $db->transaction_opened = 1;
	outcomeCheck($finalizer->reconcile(1, 'token', $lock) === false && !$storage->writes, 'Open native transaction stays processing');
	$db->transaction_opened = 0; $lock->held = false;
	outcomeCheck($finalizer->reconcile(1, 'token', $lock) === false && !$storage->writes, 'Lost ownership stays processing');
	$lock->held = true; $storage->snapshot = false;
	outcomeCheck($finalizer->reconcile(1, 'token', $lock) === false && !$storage->writes, 'Read failure never becomes native failure');
	$storage->snapshot = array('status' => 1, 'remain' => 100, 'payment_count' => 0, 'last_payment' => 0);
	$storage->persist = false;
	outcomeCheck($finalizer->reconcile(1, 'token', $lock) === false, 'Write failure does not confirm outcome');
	$storage->attempt->status = 'SUCCESS'; $before = $storage->writes;
	outcomeCheck($finalizer->reconcile(1, 'token', $lock) === 'SUCCESS' && $storage->writes === $before, 'Terminal result remains immutable');
	echo $checks." finalization checks passed (evidence/failure doubles).\n";
} catch (Throwable $exception) {
	fwrite(STDERR, 'FAILED: '.$exception->getMessage()."\n");
	exit(1);
}
