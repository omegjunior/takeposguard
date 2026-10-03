<?php
/* Copyright (C) 2026 Fred Omega Junior; GPL-3.0-or-later */
if (!defined('CSRFCHECK_WITH_TOKEN')) { define('CSRFCHECK_WITH_TOKEN', 1); }
require __DIR__.'/../../../main.inc.php';
require_once __DIR__.'/../class/takeposguardaudit.class.php';
require_once __DIR__.'/../class/takeposguardmaintenance.class.php';
require_once __DIR__.'/../lib/takeposguard_ui.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
$form = new Form($db);
$langs->loadLangs(array('takeposguard@takeposguard', 'bills'));
if (!isModEnabled('takeposguard') || !takeposguardCanAccess($user, 'maintenance')) {
	accessforbidden();
}

/* Actions */
$action = GETPOST('action', 'aZ09');
$maintenance = new TakeposguardMaintenance($db);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($action, array('recover', 'purge'), true)) {
	if ($action === 'recover') {
		$ok = $maintenance->releaseExpired(GETPOSTINT('invoiceid'), GETPOST('operation_token', 'none'));
		setEventMessages($langs->trans($ok ? 'TakeposguardMaintenanceReleased' : $maintenance->error), null, $ok ? 'mesgs' : 'errors');
	} else {
		$count = $maintenance->purgeDetails(getDolGlobalInt('TAKEPOSGUARD_HISTORY_DAYS', 90));
		setEventMessages($count === false ? $langs->trans($maintenance->error) : $langs->trans('TakeposguardMaintenancePurged', $count),
			null, $count === false ? 'errors' : 'mesgs');
	}
	header('Location: '.dol_buildpath('/takeposguard/admin/maintenance.php', 1).'?mainmenu=home&leftmenu=admintools');
	exit;
}
$arrayfields = takeposguardListFields(true);
$contextpage = 'takeposguard_maintenance';
require DOL_DOCUMENT_ROOT.'/core/actions_changeselectedfields.inc.php';
$filters = takeposguardListFilters($arrayfields);
$page = max(0, min(100000, GETPOSTINT('page')));
if (GETPOST('button_search_x', 'aZ09') || GETPOST('button_removefilter_x', 'aZ09')) { $page = 0; }
$limit = max(1, min(100, GETPOSTINT('limit') ?: 50));
$sortfield = GETPOST('sortfield', 'aZ09');
if (!isset($arrayfields[$sortfield])) { $sortfield = 'expires_at'; }
$sortorder = GETPOST('sortorder', 'aZ09') === 'DESC' ? 'DESC' : 'ASC';
$audit = new TakeposguardAudit($db);
$rows = $audit->locks($page * $limit, $limit + 1, $filters, $sortfield, $sortorder);
if ($rows === false) { setEventMessages($langs->trans('TakeposguardAuditReadFailed'), null, 'errors'); $rows = array(); }
$num = count($rows);
$rows = array_slice($rows, 0, $limit);
$params = takeposguardListParams($filters, $limit);

/* Views */
llxHeader('', $langs->trans('TakeposguardMaintenance'));
takeposguardNavigation($user);
print '<p class="warning">'.dol_escape_htmltag($langs->trans('TakeposguardMaintenanceHelp')).'</p>';
print '<form method="post" id="searchFormList" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'">';
print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="formfilteraction" value="list">';
print '<input type="hidden" name="sortfield" value="'.$sortfield.'"><input type="hidden" name="sortorder" value="'.$sortorder.'">';
print '<input type="hidden" name="mainmenu" value="home"><input type="hidden" name="leftmenu" value="admintools">';
print_barre_liste($langs->trans('TakeposguardMaintenance'), $page, $_SERVER['PHP_SELF'], $params, $sortfield, $sortorder, '', $num, '', 'shield-alt', 0, '', '', $limit);
$selector = $form->multiSelectArrayWithCheckbox('selectedfields', $arrayfields, $contextpage);
takeposguardListHead($form, $arrayfields, $filters, $selector, $params, $sortfield, $sortorder, true);
$columns = 1;
foreach ($arrayfields as $field) { if (!empty($field['checked'])) { $columns++; } }
$recoveryForms = '';
foreach ($rows as $row) {
	$values = array('invoice' => takeposguardInvoiceLink($row), 'datec' => dol_print_date($db->jdate($row->datec), 'dayhour'),
		'expires_at' => dol_print_date($db->jdate($row->expires_at), 'dayhour'),
		'status' => $langs->trans($row->status ? 'TakeposguardStatus'.$row->status : 'TakeposguardOrphanLock'),
		'operation_token' => substr($row->operation_token, 0, 8).'...');
	print '<tr class="oddeven">';
	foreach ($arrayfields as $key => $field) {
		if (!empty($field['checked'])) { print '<td>'.($key === 'invoice' ? $values[$key] : dol_escape_htmltag((string) $values[$key])).'</td>'; }
	}
	print '<td class="center nowrap">';
	if ((int) $row->expired && getDolGlobalInt('TAKEPOSGUARD_ENABLE')) {
		$formId = 'recover_'.((int) $row->fk_invoice);
		// Separate forms outside the filter form: no nested forms and no mixed action payload.
		print '<button class="butAction" type="submit" form="'.$formId.'">'.$langs->trans('TakeposguardRecoverExpired').'</button>';
		$recoveryForms .= '<form id="'.$formId.'" method="post" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'">'
			.'<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="recover">'
			.'<input type="hidden" name="invoiceid" value="'.((int) $row->fk_invoice).'">'
			.'<input type="hidden" name="operation_token" value="'.dol_escape_htmltag($row->operation_token).'">'
			.'<input type="hidden" name="mainmenu" value="home"><input type="hidden" name="leftmenu" value="admintools"></form>';
	} else { print dol_escape_htmltag($langs->trans('TakeposguardMaintenanceRecoveryDenied')); }
	print '</td></tr>';
}
if (!$rows) { print '<tr><td colspan="'.$columns.'">'.$langs->trans('NoRecordFound').'</td></tr>'; }
print '</table></div></form>'.$recoveryForms;
print '<p>'.dol_escape_htmltag($langs->trans('TakeposguardPurgeHelp', getDolGlobalInt('TAKEPOSGUARD_HISTORY_DAYS', 90))).'</p>';
print '<form class="tabsAction" method="post" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'">';
print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="purge">';
print '<button class="butAction" type="submit">'.$langs->trans('TakeposguardPurgeDetails').'</button></form>';
llxFooter();
$db->close();
