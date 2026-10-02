<?php
/* Copyright (C) 2026 Fred Omega Junior; GPL-3.0-or-later */
if (!defined('CSRFCHECK_WITH_TOKEN')) { define('CSRFCHECK_WITH_TOKEN', 1); }
require __DIR__.'/../../../main.inc.php';
require_once __DIR__.'/../class/takeposguardaudit.class.php';
require_once __DIR__.'/../class/takeposguardmaintenance.class.php';
require_once __DIR__.'/../lib/takeposguard_ui.lib.php';
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
	header('Location: '.dol_buildpath('/takeposguard/admin/maintenance.php', 1));
	exit;
}
$page = max(0, min(100000, GETPOSTINT('page')));
$audit = new TakeposguardAudit($db);
$rows = $audit->locks($page * 50, 51);
if ($rows === false) {
	setEventMessages($langs->trans('TakeposguardAuditReadFailed'), null, 'errors');
	$rows = array();
}
$more = count($rows) > 50;
$rows = array_slice($rows, 0, 50);

/* Views */
llxHeader('', $langs->trans('TakeposguardMaintenance'));
print load_fiche_titre($langs->trans('TakeposguardMaintenance'), '', 'shield-alt');
takeposguardNavigation($user);
print '<p class="warning">'.dol_escape_htmltag($langs->trans('TakeposguardMaintenanceHelp')).'</p>';
print '<div class="div-table-responsive"><table class="noborder centpercent"><tr class="liste_titre">';
foreach (array('Invoice', 'Date', 'TakeposguardExpires', 'Status', 'TakeposguardToken', 'Action') as $key) {
	print '<th>'.dol_escape_htmltag($langs->trans($key)).'</th>';
}
print '</tr>';
foreach ($rows as $row) {
	print '<tr class="oddeven"><td>'.takeposguardInvoiceLink($row).'</td><td>'.dol_print_date($db->jdate($row->datec), 'dayhour').'</td>';
	print '<td>'.dol_print_date($db->jdate($row->expires_at), 'dayhour').'</td><td>'
		.dol_escape_htmltag($row->status ? $langs->trans('TakeposguardStatus'.$row->status) : $langs->trans('TakeposguardOrphanLock')).'</td>';
	print '<td>'.dol_escape_htmltag(substr($row->operation_token, 0, 8).'...').'</td><td>';
	if ((int) $row->expired && getDolGlobalInt('TAKEPOSGUARD_ENABLE')) {
		print '<form method="post" action="'.dol_escape_htmltag(dol_buildpath('/takeposguard/admin/maintenance.php', 1)).'">';
		print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="recover">';
		print '<input type="hidden" name="invoiceid" value="'.((int) $row->fk_invoice).'">';
		print '<input type="hidden" name="operation_token" value="'.dol_escape_htmltag($row->operation_token).'">';
		print '<button class="button" type="submit">'.$langs->trans('TakeposguardRecoverExpired').'</button></form>';
	} else {
		print dol_escape_htmltag($langs->trans('TakeposguardMaintenanceRecoveryDenied'));
	}
	print '</td></tr>';
}
if (!$rows) { print '<tr><td colspan="6">'.$langs->trans('NoRecordFound').'</td></tr>'; }
print '</table></div>';
takeposguardPaging('/takeposguard/admin/maintenance.php', $page, $more);
print '<p>'.dol_escape_htmltag($langs->trans('TakeposguardPurgeHelp', getDolGlobalInt('TAKEPOSGUARD_HISTORY_DAYS', 90))).'</p>';
print '<form method="post" action="'.dol_escape_htmltag(dol_buildpath('/takeposguard/admin/maintenance.php', 1)).'">';
print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="purge">';
print '<button class="button" type="submit">'.$langs->trans('TakeposguardPurgeDetails').'</button></form>';
llxFooter();
$db->close();
