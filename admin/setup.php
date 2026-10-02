<?php
/* Copyright (C) 2004-2017  Laurent Destailleur     <eldy@users.sourceforge.net>
 * Copyright (C) 2024       Frédéric France         <frederic.france@free.fr>
 * Copyright (C) 2026		SuperAdmin
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file    takeposguard/admin/setup.php
 * \ingroup takeposguard
 * \brief   Takeposguard setup page.
 */

// Load Dolibarr environment
$res = 0;
// Try main.inc.php into web root known defined into CONTEXT_DOCUMENT_ROOT (not always defined)
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
}
// Try main.inc.php into web root detected using web root calculated from SCRIPT_FILENAME
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME'];
$tmp2 = realpath(__FILE__);
$i = strlen($tmp) - 1;
$j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) {
	$i--;
	$j--;
}
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i + 1))."/main.inc.php")) {
	$res = @include substr($tmp, 0, ($i + 1))."/main.inc.php";
}
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php")) {
	$res = @include dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php";
}
// Try main.inc.php using relative path
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once __DIR__.'/../lib/takeposguard.lib.php';
$langs->loadLangs(array('admin', 'takeposguard@takeposguard'));

if (!$user->admin || !isModEnabled('takeposguard')) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');
$settings = array(
	'TAKEPOSGUARD_ENABLE' => array('boolean', '0', 'TakeposguardEnable'),
	'TAKEPOSGUARD_LOCK_TIMEOUT' => array('seconds', '120', 'TakeposguardLockTimeout'),
	'TAKEPOSGUARD_HISTORY_DAYS' => array('days', '90', 'TakeposguardHistoryDays'),
	'TAKEPOSGUARD_MAX_ATTEMPTS' => array('attempts', '1000', 'TakeposguardMaxAttempts'),
	'TAKEPOSGUARD_DEBUG_LOG' => array('boolean', '0', 'TakeposguardDebugLog'),
	'TAKEPOSGUARD_MISSING_TOKEN_POLICY' => array('policy', 'reject', 'TakeposguardMissingTokenPolicy'),
);
$values = array();
foreach ($settings as $name => $setting) {
	$values[$name] = getDolGlobalString($name, $setting[1]);
}

/* Actions */
if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
	$error = 0;
	foreach ($settings as $name => $setting) {
		$values[$name] = GETPOST($name, 'alphanohtml');
		if (!takeposguardValidateSetting($setting[0], $values[$name])) {
			$error++;
			setEventMessages($langs->trans('TakeposguardInvalidSetting', $langs->trans($setting[2])), null, 'errors');
		}
	}
	if (!$error) {
		if (!$db->begin()) {
			$error++;
		} else {
			foreach ($values as $name => $value) {
				if (dolibarr_set_const($db, $name, $value, 'chaine', 0, '', $conf->entity) <= 0) {
					$error++;
					break;
				}
			}
			if ($error) {
				$db->rollback();
			} elseif (!$db->commit()) {
				$db->rollback();
				$error++;
			}
		}
		if ($error) {
			setEventMessages($langs->trans('TakeposguardSaveFailed'), null, 'errors');
		} else {
			setEventMessages($langs->trans('SetupSaved'), null, 'mesgs');
			header('Location: '.dol_buildpath('/takeposguard/admin/setup.php', 1));
			exit;
		}
	}
}

/* Views */
llxHeader('', $langs->trans('TakeposguardSetup'));
print load_fiche_titre($langs->trans('TakeposguardSetup'), '<a href="'.DOL_URL_ROOT.'/admin/modules.php">'.$langs->trans('BackToModuleList').'</a>', 'title_setup');
print dol_get_fiche_head(takeposguardAdminPrepareHead(), 'settings', $langs->trans('ModuleTakeposguardName'), -1, 'shield-alt');
print '<a class="butAction" href="'.dol_escape_htmltag(dol_buildpath('/takeposguard/audit.php', 1)).'">'.dol_escape_htmltag($langs->trans('TakeposguardAudit')).'</a>';
print '<a class="butAction" href="'.dol_escape_htmltag(dol_buildpath('/takeposguard/admin/maintenance.php', 1)).'">'.dol_escape_htmltag($langs->trans('TakeposguardMaintenance')).'</a>';
print '<div class="warning">'.$langs->trans('TakeposguardConfigurationOnly').'</div>';
print '<form method="post" action="'.dol_buildpath('/takeposguard/admin/setup.php', 1).'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="save">';
print '<table class="noborder centpercent">';
foreach ($settings as $name => $setting) {
	print '<tr class="oddeven"><td><label for="'.$name.'">'.$langs->trans($setting[2]).'</label><br>';
	print '<span class="opacitymedium">'.$langs->trans($setting[2].'Help').'</span></td><td>';
	if ($setting[0] === 'boolean') {
		print '<select id="'.$name.'" name="'.$name.'">';
		foreach (array('0' => 'No', '1' => 'Yes') as $value => $label) {
			print '<option value="'.$value.'"'.((string) $values[$name] === (string) $value ? ' selected' : '').'>'.$langs->trans($label).'</option>';
		}
		print '</select>';
	} elseif ($setting[0] === 'policy') {
		print '<select id="'.$name.'" name="'.$name.'"><option value="reject">'.$langs->trans('TakeposguardRejectMissingToken').'</option></select>';
	} else {
		$min = ($setting[0] === 'seconds' ? 10 : 1);
		$max = ($setting[0] === 'seconds' ? 3600 : ($setting[0] === 'attempts' ? 9999 : 3650));
		if ($setting[0] === 'attempts') { $min = 10; }
		print '<input type="number" id="'.$name.'" name="'.$name.'" min="'.$min.'" max="'.$max.'" step="1" required value="'.dol_escape_htmltag($values[$name]).'">';
	}
	print '</td></tr>';
}
print '</table><div class="center"><input type="submit" class="button" value="'.$langs->trans('Save').'"></div></form>';
print dol_get_fiche_end();
llxFooter();
$db->close();
