<?php
/* Copyright (C) 2026 Fred Omega Junior; GPL-3.0-or-later */
require __DIR__.'/../../main.inc.php';
require_once __DIR__.'/class/takeposguardaudit.class.php';
require_once __DIR__.'/lib/takeposguard_ui.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
$langs->loadLangs(array('takeposguard@takeposguard', 'bills'));
if (!isModEnabled('takeposguard') || !takeposguardCanAccess($user, 'audit')) { accessforbidden(); }
$form = new Form($db);
$arrayfields = takeposguardListFields();
$contextpage = 'takeposguard_audit';
$action = GETPOST('action', 'aZ09');

/* Actions */
require DOL_DOCUMENT_ROOT.'/core/actions_changeselectedfields.inc.php';
$filters = takeposguardListFilters($arrayfields);
// Preserve links from older integrations using invoiceid/status.
$invoiceId = GETPOST('button_removefilter_x', 'aZ09') ? 0 : max(0, GETPOSTINT('invoiceid'));
if (!GETPOSTISSET('search_status') && GETPOST('status', 'aZ09') && !GETPOST('button_removefilter_x', 'aZ09')) { $filters['status'] = GETPOST('status', 'aZ09'); }
$limit = max(1, min(100, GETPOSTINT('limit') ?: 50));
$page = max(0, min(100000, GETPOSTINT('page')));
if (GETPOST('button_search_x', 'aZ09') || GETPOST('button_removefilter_x', 'aZ09')) { $page = 0; }
$sortfield = GETPOST('sortfield', 'aZ09');
if (!isset($arrayfields[$sortfield])) { $sortfield = 'datec'; }
$sortorder = GETPOST('sortorder', 'aZ09') === 'ASC' ? 'ASC' : 'DESC';
$repository = new TakeposguardAudit($db);
$rows = $repository->attempts($filters['status'], $invoiceId, $page * $limit, $limit + 1, $filters, $sortfield, $sortorder);
if ($rows === false) { setEventMessages($langs->trans('TakeposguardAuditReadFailed'), null, 'errors'); $rows = array(); }
$num = count($rows);
$rows = array_slice($rows, 0, $limit);
$params = takeposguardListParams($filters, $limit, $invoiceId);

/* Views */
llxHeader('', $langs->trans('TakeposguardAudit'));
print '<form method="post" id="searchFormList" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'">';
print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="formfilteraction" value="list">';
print '<input type="hidden" name="sortfield" value="'.$sortfield.'"><input type="hidden" name="sortorder" value="'.$sortorder.'">';
print '<input type="hidden" name="mainmenu" value="home"><input type="hidden" name="leftmenu" value="takeposguard">';
print_barre_liste($langs->trans('TakeposguardAudit'), $page, $_SERVER['PHP_SELF'], $params, $sortfield, $sortorder, '', $num, '', 'shield-alt', 0, '', '', $limit);
print '<p class="opacitymedium">'.dol_escape_htmltag($langs->trans('TakeposguardAuditRetentionHelp')).'</p>';
$selector = $form->multiSelectArrayWithCheckbox('selectedfields', $arrayfields, $contextpage);
takeposguardListHead($form, $arrayfields, $filters, $selector, $params, $sortfield, $sortorder, false, $invoiceId);
$columns = 1;
foreach ($arrayfields as $field) { if (!empty($field['checked'])) { $columns++; } }
foreach ($rows as $row) {
	$values = array('datec' => dol_print_date($db->jdate($row->datec), 'dayhour'), 'invoice' => takeposguardInvoiceLink($row),
		'terminal' => $row->terminal, 'user_login' => $row->user_login ?: (string) $row->fk_user, 'payment_code' => $row->payment_code,
		'requested_amount' => $row->requested_amount === null ? '-' : price($row->requested_amount),
		'actual_amount' => $row->actual_amount === null ? '-' : price($row->actual_amount),
		'status' => $langs->trans('TakeposguardStatus'.$row->status), 'operation_token' => substr($row->operation_token, 0, 8).'...',
		'remain_before' => price($row->remain_before), 'remain_after' => $row->remain_after === null ? '-' : price($row->remain_after),
		'error' => trim($row->error_code.' '.$row->error_message));
	print '<tr class="oddeven">';
	foreach ($arrayfields as $key => $field) {
		if (!empty($field['checked'])) { print '<td>'.($key === 'invoice' ? $values[$key] : dol_escape_htmltag((string) $values[$key])).'</td>'; }
	}
	print '<td></td></tr>';
}
if (!$rows) { print '<tr><td colspan="'.$columns.'">'.$langs->trans('NoRecordFound').'</td></tr>'; }
print '</table></div></form>';
llxFooter();
$db->close();
