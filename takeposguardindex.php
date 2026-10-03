<?php
/* Copyright (C) 2026 Fred Omega Junior; GPL-3.0-or-later */
require __DIR__.'/../../main.inc.php';
require_once __DIR__.'/lib/takeposguard_ui.lib.php';
if (!isModEnabled('takeposguard')) { accessforbidden(); }
/* Actions */
if (takeposguardCanAccess($user, 'audit')) {
	header('Location: '.dol_buildpath('/takeposguard/audit.php', 1).'?mainmenu=home&leftmenu=admintools');
} elseif (takeposguardCanAccess($user, 'maintenance')) {
	header('Location: '.dol_buildpath('/takeposguard/admin/maintenance.php', 1).'?mainmenu=home&leftmenu=admintools');
} else {
	accessforbidden();
}
exit;
