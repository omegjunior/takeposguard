<?php
/** php test/audit.php: permissions and escaping, without a Dolibarr session. */
require_once __DIR__.'/../lib/takeposguard_ui.lib.php';
define('DOL_URL_ROOT', '/erp');
function dol_escape_htmltag($value) { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
function dol_buildpath($value, $absolute) { return '/erp/custom'.$value; }
$langs = new class {
	public function trans($key) { return $key; }
};
$checks = 0;
function auditCheck($value, $message) {
	global $checks;
	if (!$value) { throw new RuntimeException($message); }
	$checks++;
}
class AuditFixtureUser
{
	public $id = 1;
	public $socid = 0;
	public $admin = 0;
	public $grants = array();
	public function hasRight($module, $first, $second) { return in_array($first.'/'.$second, $this->grants, true); }
}
try {
	$user = new AuditFixtureUser();
	auditCheck(!takeposguardCanAccess($user, 'audit') && !takeposguardCanAccess($user, 'maintenance'), 'No rights deny both pages');
	$user->grants = array('audit/read');
	auditCheck(takeposguardCanAccess($user, 'audit') && !takeposguardCanAccess($user, 'maintenance'), 'Audit read cannot maintain');
	$user->grants = array('maintenance/write');
	auditCheck(!takeposguardCanAccess($user, 'audit') && takeposguardCanAccess($user, 'maintenance'), 'Maintenance cannot read full attempt history');
	ob_start(); takeposguardNavigation($user); $nav = ob_get_clean();
	auditCheck(strpos($nav, '/admin/maintenance.php') !== false && strpos($nav, '/audit.php') === false, 'Navigation respects separate permissions');
	$user->admin = 1;
	auditCheck(takeposguardCanAccess($user, 'audit') && takeposguardCanAccess($user, 'maintenance'), 'Administrator allowed');
	$user->socid = 1;
	auditCheck(!takeposguardCanAccess($user, 'audit') && !takeposguardCanAccess($user, 'maintenance'), 'External user refused even with admin flag');
	$user->socid = 0; $user->id = 0;
	auditCheck(!takeposguardCanAccess($user, 'audit'), 'Unauthenticated user refused');
	$user->id = 1;
	auditCheck(!takeposguardCanAccess($user, 'unknown'), 'Unknown operation refused');
	$link = takeposguardInvoiceLink((object) array('fk_invoice' => 42, 'invoice_ref' => '<img src=x onerror=alert(1)>'));
	auditCheck(strpos($link, '<img') === false && strpos($link, '&lt;img') !== false && strpos($link, 'facid=42') !== false, 'Invoice reference escaped with native invoice link');
	ob_start(); takeposguardPaging('/takeposguard/audit.php', 1, true, '&status=" onclick="bad'); $pagination = ob_get_clean();
	auditCheck(strpos($pagination, ' onclick="bad') === false && strpos($pagination, 'page=0') !== false && strpos($pagination, 'page=2') !== false, 'Pagination escapes complete URLs');
	echo $checks." audit permission/escaping checks passed.\n";
} catch (Throwable $exception) {
	fwrite(STDERR, 'FAILED: '.$exception->getMessage()."\n");
	exit(1);
}
