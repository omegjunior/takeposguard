<?php
/* Copyright (C) 2026 Fred Omega Junior; GPL-3.0-or-later */
require __DIR__.'/../../main.inc.php';
require_once __DIR__.'/class/takeposguardaudit.class.php';
require_once __DIR__.'/lib/takeposguard_ui.lib.php';
$langs->loadLangs(array('takeposguard@takeposguard', 'bills'));
if (!isModEnabled('takeposguard') || !takeposguardCanAccess($user, 'audit')) {
	accessforbidden();
}

/* Actions */
$page = max(0, min(100000, GETPOSTINT('page')));
$invoiceId = max(0, GETPOSTINT('invoiceid'));
$status = GETPOST('status', 'aZ09');
$repository = new TakeposguardAudit($db);
$rows = $repository->attempts($status, $invoiceId, $page * 50, 51);
if ($rows === false) {
	setEventMessages($langs->trans('TakeposguardAuditReadFailed'), null, 'errors');
	$rows = array();
}
$more = count($rows) > 50;
$rows = array_slice($rows, 0, 50);

/* Views */
llxHeader('', $langs->trans('TakeposguardAudit'));
print load_fiche_titre($langs->trans('TakeposguardAudit'), '', 'shield-alt');
takeposguardNavigation($user);
print '<form method="get" action="'.dol_escape_htmltag(dol_buildpath('/takeposguard/audit.php', 1)).'">';
print '<label>'.$langs->trans('TakeposguardInvoiceId').' <input type="number" min="1" name="invoiceid" value="'.($invoiceId ?: '').'"></label> ';
print '<label>'.$langs->trans('Status').' <select name="status">';
foreach (array('', 'PROCESSING', 'SUCCESS', 'FAILED', 'BLOCKED') as $value) {
	print '<option value="'.$value.'"'.($status === $value ? ' selected' : '').'>'
		.dol_escape_htmltag($langs->trans($value === '' ? 'All' : 'TakeposguardStatus'.$value)).'</option>';
}
print '</select></label> <button class="button" type="submit">'.$langs->trans('Search').'</button></form>';
print '<p class="opacitymedium">'.dol_escape_htmltag($langs->trans('TakeposguardAuditRetentionHelp')).'</p>';
print '<div class="div-table-responsive"><table class="noborder centpercent"><tr class="liste_titre">';
foreach (array('Date', 'Invoice', 'TakeposguardTerminal', 'User', 'PaymentMode', 'TakeposguardRequestedAmount',
	'TakeposguardActualAmount', 'Status', 'TakeposguardToken', 'TakeposguardRemainBefore', 'TakeposguardRemainAfter', 'Error') as $key) {
	print '<th>'.dol_escape_htmltag($langs->trans($key)).'</th>';
}
print '</tr>';
foreach ($rows as $row) {
	print '<tr class="oddeven"><td>'.dol_print_date($db->jdate($row->datec), 'dayhour').'</td><td>'.takeposguardInvoiceLink($row).'</td>';
	foreach (array($row->terminal, $row->user_login ?: (string) $row->fk_user, $row->payment_code,
		$row->requested_amount === null ? '-' : price($row->requested_amount), $row->actual_amount === null ? '-' : price($row->actual_amount),
		$langs->trans('TakeposguardStatus'.$row->status), substr($row->operation_token, 0, 8).'...', price($row->remain_before),
		$row->remain_after === null ? '-' : price($row->remain_after), trim($row->error_code.' '.$row->error_message)) as $value) {
		print '<td>'.dol_escape_htmltag((string) $value).'</td>';
	}
	print '</tr>';
}
if (!$rows) {
	print '<tr><td colspan="12">'.$langs->trans('NoRecordFound').'</td></tr>';
}
print '</table></div>';
takeposguardPaging('/takeposguard/audit.php', $page, $more, '&invoiceid='.$invoiceId.'&status='.urlencode($status));
llxFooter();
$db->close();
