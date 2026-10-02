<?php
/** Isolated hook orchestration: php test/interception.php (no native payments). */
require_once __DIR__.'/../class/actions_takeposguard.class.php';
$settings = array('TAKEPOSGUARD_ENABLE' => 1, 'TAKEPOSGUARD_LOCK_TIMEOUT' => 120);
$input = array('takeposguard_token' => '12345678-1234-4234-8234-123456789abc', 'pay' => 'LIQ', 'amount' => 50);
$conf = (object) array('entity' => 1, 'global' => new stdClass());
$modules = array();
$langs = new class {
	public function load($name) {}
	public function trans($key) { return '<'.$key.'>'; }
};
$user = new class {
	public $id = 1;
	public $socid = 0;
	public $allowed = true;
	public function hasRight($module, $right) { return $this->allowed; }
};
function getDolGlobalInt($key, $default = 0) { global $settings; return isset($settings[$key]) ? (int) $settings[$key] : $default; }
function isModEnabled($key) { global $modules; return !empty($modules[$key]); }
function getDolGlobalString($key) { global $conf; return isset($conf->global->$key) ? (string) $conf->global->$key : ''; }
function GETPOST($key, $type) { global $input; return isset($input[$key]) ? $input[$key] : ''; }
function GETPOSTFLOAT($key) { return (float) GETPOST($key, 'none'); }
function setEventMessages($message, $errors, $style) {}
function dol_escape_htmltag($text) { return htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); }
function dol_syslog($message, $level) {}
class InterceptionInvoice
{
	public $id = 1;
	public $entity = 1;
	public $module_source = 'takepos';
	public $status = 0;
	public $type = 0;
	public $remain = 100;
	public $error = '';
	public $reloadedStatus = 1;
	public $fetchResult = 1;
	public $fetches = 0;
	public function getRemainToPay() { return $this->remain; }
	public function fetch($id) { $this->fetches++; $this->status = $this->reloadedStatus; return $this->fetchResult; }
}
class InterceptionLock
{
	public $result = TakeposguardLock::ACQUIRED;
	public $released = 0;
	public $abandoned = 0;
	public $calls = 0;
	public function acquire($id, $token, $ttl) { $this->calls++; return $this->result; }
	public function release() { $this->released++; return true; }
	public function abandon() { $this->abandoned++; return true; }
}
class InterceptionStorage
{
	public $previous = null;
	public $validation = true;
	public $validationReads = 0;
	public $insertResult = 1;
	public $creates = 0;
	public $throws = false;
	public function fetchSuccessfulValidation($id) { $this->validationReads++; return $this->validation; }
	public function fetchTokenOwner($token) { return $this->previous; }
	public function createProcessing($id, $token, $user, $metadata) {
		$this->creates++;
		if ($this->throws) { throw new RuntimeException('sensitive failure'); }
		return $this->insertResult;
	}
}
class InterceptionHook extends ActionsTakeposguard
{
	public $lock;
	public $storage;
	public function __construct() { parent::__construct(null); $this->lock = new InterceptionLock(); $this->storage = new InterceptionStorage(); }
	protected function newLock() { return $this->lock; }
	protected function newStorage() { return $this->storage; }
}
$checks = 0;
function checkHook($condition, $label) { global $checks; $checks++; if (!$condition) { throw new RuntimeException($label); } }
function invoke($hook, $invoice, $action = 'valid', $context = 'takeposinvoice') {
	ob_start();
	try { $result = $hook->doActions(array('context' => $context), $invoice, $action, null); $html = ob_get_contents(); }
	finally { ob_end_clean(); }
	return array($result, $html);
}
try {
	foreach (array(array('history', 'takeposinvoice'), array('valid', 'takepospay'), array('valid', 'nottakeposinvoice')) as $case) {
		$h = new InterceptionHook(); checkHook(invoke($h, new InterceptionInvoice(), $case[0], $case[1])[0] === 0 && $h->lock->calls === 0, 'Unrelated action/context untouched');
	}
	$settings['TAKEPOSGUARD_ENABLE'] = 0;
	$h = new InterceptionHook(); checkHook(invoke($h, null)[0] === 0 && !$h->lock->calls, 'Disabled is transparent');
	$settings['TAKEPOSGUARD_ENABLE'] = 1;
	foreach (array('', 'bad', array('uuid'), '12345678-1234-1234-8234-123456789abc') as $token) {
		$input['takeposguard_token'] = $token; $h = new InterceptionHook(); $r = invoke($h, new InterceptionInvoice());
		checkHook($r[0] === 1 && !$h->lock->calls && strpos($r[1], '&lt;TakeposguardPaymentTokenRequired&gt;') !== false, 'Missing/invalid token rejected and escaped fragment rendered');
	}
	$input['takeposguard_token'] = '12345678-1234-4234-8234-123456789abc';
	$user->allowed = false; $h = new InterceptionHook(); checkHook(invoke($h, new InterceptionInvoice())[0] === 1 && !$h->lock->calls, 'Native rights enforced'); $user->allowed = true;
	$user->socid = 4; checkHook(invoke(new InterceptionHook(), new InterceptionInvoice())[0] === 1, 'External users denied'); $user->socid = 0;
	foreach (array('entity' => 2, 'module_source' => 'other', 'id' => 0) as $key => $value) {
		$i = new InterceptionInvoice(); $i->$key = $value; $h = new InterceptionHook(); checkHook(invoke($h, $i)[0] === 1 && !$h->lock->calls, 'Invoice identity/origin/entity enforced');
	}
	foreach (array(TakeposguardLock::BUSY, TakeposguardLock::ERROR, TakeposguardLock::RECOVERY_REQUIRED) as $result) {
		$h = new InterceptionHook(); $h->lock->result = $result; checkHook(invoke($h, new InterceptionInvoice())[0] === 1 && !$h->storage->creates, 'Only exact ACQUIRED enters processing');
		if ($result === TakeposguardLock::RECOVERY_REQUIRED) { checkHook($h->lock->abandoned === 1 && !$h->lock->released, 'Expired metadata preserved pending reconciliation'); }
	}
	foreach (array('PROCESSING', 'SUCCESS', 'FAILED', 'BLOCKED') as $status) {
		$h = new InterceptionHook(); $h->storage->previous = (object) array('fk_invoice' => 1, 'status' => $status);
		checkHook(invoke($h, new InterceptionInvoice())[0] === 1 && !$h->storage->creates && $h->lock->released === 1, 'Used token cannot be retried for '.$status);
	}
	$h = new InterceptionHook(); $h->storage->previous = (object) array('fk_invoice' => 2);
	checkHook(invoke($h, new InterceptionInvoice())[0] === 1 && !$h->storage->creates, 'Token bound to another invoice rejected');
	$h = new InterceptionHook(); $h->storage->previous = false;
	checkHook(invoke($h, new InterceptionInvoice())[0] === 1 && $h->lock->released === 1, 'Read failure is fail closed');
	$h = new InterceptionHook(); $i = new InterceptionInvoice(); $i->fetchResult = -1;
	checkHook(invoke($h, $i)[0] === 1 && !$h->storage->creates && $h->lock->released === 1, 'Native reload failure blocks');
	$h = new InterceptionHook(); $h->storage->insertResult = false;
	checkHook(invoke($h, new InterceptionInvoice())[0] === 1 && $h->lock->released === 1, 'Insert failure blocks native action');
	$h = new InterceptionHook(); $h->storage->throws = true;
	checkHook(invoke($h, new InterceptionInvoice())[0] === 1 && $h->lock->abandoned === 1, 'Uncertain insertion preserves recovery metadata');
	$h = new InterceptionHook(); $i = new InterceptionInvoice();
	checkHook(invoke($h, $i, 'valid', 'other:takeposinvoice')[0] === 0 && $i->fetches === 1 && $i->status === 1 && $h->storage->creates === 1 && !$h->lock->released, 'Accepted attempt reloads invoice and keeps lock throughout native action');
	checkHook(invoke($h, $i)[0] === 1 && $h->storage->creates === 1, 'Repeated hook call cannot accept twice');
	$h = new InterceptionHook(); $i = new InterceptionInvoice(); $i->status = 1;
	checkHook(invoke($h, $i)[0] === 0, 'New token on validated invoice can enter native partial payment');

	foreach (array(0, -5) as $remain) {
		$h = new InterceptionHook(); $i = new InterceptionInvoice(); $i->remain = $remain;
		checkHook(invoke($h, $i)[0] === 1 && !$h->storage->creates && $h->lock->released === 1, 'No positive balance cannot receive a regular partial payment');
	}
	foreach (array(2, 3, 99) as $status) {
		$h = new InterceptionHook(); $i = new InterceptionInvoice(); $i->reloadedStatus = $status;
		checkHook(invoke($h, $i)[0] === 1 && !$h->storage->creates, 'Closed/abandoned/unknown invoice status refused');
	}
	$h = new InterceptionHook(); $i = new InterceptionInvoice(); $i->type = 2; $i->remain = -50;
	checkHook(invoke($h, $i)[0] === 0, 'Credit note balance follows native negative direction');
	foreach (array(INF, NAN, 'not a balance') as $remain) {
		$h = new InterceptionHook(); $i = new InterceptionInvoice(); $i->remain = $remain;
		checkHook(invoke($h, $i)[0] === 1 && !$h->storage->creates, 'Unusable native balance is fail closed');
	}
	$h = new InterceptionHook(); $i = new InterceptionInvoice(); $i->error = 'NativeCalculationFailed';
	checkHook(invoke($h, $i)[0] === 1 && !$h->storage->creates, 'Native calculation error blocks partial payment');
	$h = new InterceptionHook(); $i = new InterceptionInvoice(); $i->type = 2; $i->remain = 50;
	checkHook(invoke($h, $i)[0] === 1 && !$h->storage->creates, 'Wrong credit-note balance direction refused');
	$modules = array('stock' => true, 'productbatch' => true); $_SESSION['takeposterminal'] = 1;
	foreach (array(null, false) as $evidence) {
		$h = new InterceptionHook(); $h->storage->validation = $evidence;
		checkHook(invoke($h, new InterceptionInvoice())[0] === 1 && !$h->storage->creates
			&& !isset($conf->global->CASHDESK_NO_DECREASE_STOCK1), 'Missing evidence/SQL failure blocks before stock override');
	}
	$h = new InterceptionHook(); $conf->global->CASHDESK_NO_DECREASE_STOCK1 = '0'; $i = new InterceptionInvoice();
	checkHook(invoke($h, $i)[0] === 0 && $conf->global->CASHDESK_NO_DECREASE_STOCK1 === '1'
		&& $h->storage->validationReads === 1, 'Proven validation suppresses manual lot stock during payment');
	$action = 'valid';
	$h->completeTakePosInvoiceHeader(array('context' => 'takepospay'), $i, $action, null);
	checkHook($conf->global->CASHDESK_NO_DECREASE_STOCK1 === '1', 'Unrelated header leaves active override intact');
	$other = new InterceptionInvoice(); $other->id = 2;
	$h->completeTakePosInvoiceHeader(array('context' => 'takeposinvoice'), $other, $action, null);
	checkHook($conf->global->CASHDESK_NO_DECREASE_STOCK1 === '1', 'Another invoice header leaves active override intact');
	$h->completeTakePosInvoiceHeader(array('context' => 'takeposinvoice'), $i, $action, null);
	checkHook($conf->global->CASHDESK_NO_DECREASE_STOCK1 === '0' && !$h->lock->released, 'Post-native header restores exact value without releasing lock');
	unset($conf->global->CASHDESK_NO_DECREASE_STOCK1);
	$h = new InterceptionHook(); $i = new InterceptionInvoice(); $i->reloadedStatus = 0;
	checkHook(invoke($h, $i)[0] === 0 && !isset($conf->global->CASHDESK_NO_DECREASE_STOCK1)
		&& !$h->storage->validationReads, 'Initial draft validation retains native stock handling');
	$conf->global->CASHDESK_NO_DECREASE_STOCK1 = '1'; $h = new InterceptionHook(); $h->storage->validation = null;
	checkHook(invoke($h, new InterceptionInvoice())[0] === 0 && !$h->storage->validationReads, 'Explicitly disabled stock keeps native setting');
	unset($conf->global->CASHDESK_NO_DECREASE_STOCK1);
	$modules['productbatch'] = false; $h = new InterceptionHook(); $h->storage->validation = null;
	checkHook(invoke($h, new InterceptionInvoice())[0] === 0 && !$h->storage->validationReads
		&& !isset($conf->global->CASHDESK_NO_DECREASE_STOCK1), 'No-lot stock partial payment remains native');
	$modules['productbatch'] = true; $h = new InterceptionHook(); $h->storage->insertResult = false;
	checkHook(invoke($h, new InterceptionInvoice())[0] === 1 && !isset($conf->global->CASHDESK_NO_DECREASE_STOCK1), 'Failed attempt creation never overrides stock');
	$settings['TAKEPOSGUARD_ENABLE'] = 0; $h = new InterceptionHook();
	checkHook(invoke($h, new InterceptionInvoice())[0] === 0 && !isset($conf->global->CASHDESK_NO_DECREASE_STOCK1), 'Disabled module never changes stock policy');
	echo $checks." interception checks passed (doubles, no native payments).\n";
} catch (Throwable $e) { fwrite(STDERR, 'FAILED: '.$e->getMessage()."\n"); exit(1); }
