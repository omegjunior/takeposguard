<?php
/* Copyright (C) 2026 Fred Omega Junior; GPL-3.0-or-later */
require __DIR__.'/../../main.inc.php';
require_once __DIR__.'/lib/takeposguard_ui.lib.php';
$langs->load('takeposguard@takeposguard');
if (!isModEnabled('takeposguard') || (!takeposguardCanAccess($user, 'audit') && !takeposguardCanAccess($user, 'maintenance'))) {
	accessforbidden();
}
/* Views: landing page for the technical module's left menu group. */
llxHeader('', $langs->trans('ModuleTakeposguardName'));
print load_fiche_titre($langs->trans('ModuleTakeposguardName'), '', 'shield-alt');
print '<p>'.dol_escape_htmltag($langs->trans('TakeposguardMenuHelp')).'</p>';
print '<div class="tabsAction">';
takeposguardNavigation($user);
print '</div>';
llxFooter();
$db->close();
