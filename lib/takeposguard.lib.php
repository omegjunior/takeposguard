<?php
/* Copyright (C) 2026		SuperAdmin
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
 * \file    takeposguard/lib/takeposguard.lib.php
 * \ingroup takeposguard
 * \brief   Library files with common functions for Takeposguard
 */

/**
 * Prepare admin pages header
 *
 * @return array<array{string,string,string}>
 */
function takeposguardAdminPrepareHead()
{
	global $langs, $conf;

	// global $db;
	// $extrafields = new ExtraFields($db);
	// $extrafields->fetch_name_optionals_label('myobject');

	$langs->load("takeposguard@takeposguard");

	$h = 0;
	$head = array();

	$head[$h][0] = dol_buildpath("/takeposguard/admin/setup.php", 1);
	$head[$h][1] = $langs->trans("Settings");
	$head[$h][2] = 'settings';
	$h++;

	/*
	$head[$h][0] = dol_buildpath("/takeposguard/admin/myobject_extrafields.php", 1);
	$head[$h][1] = $langs->trans("ExtraFields");
	$nbExtrafields = (isset($extrafields->attributes['myobject']['label']) && is_countable($extrafields->attributes['myobject']['label'])) ? count($extrafields->attributes['myobject']['label']) : 0;
	if ($nbExtrafields > 0) {
		$head[$h][1] .= '<span class="badge marginleftonlyshort">' . $nbExtrafields . '</span>';
	}
	$head[$h][2] = 'myobject_extrafields';
	$h++;

	$head[$h][0] = dol_buildpath("/takeposguard/admin/myobjectline_extrafields.php", 1);
	$head[$h][1] = $langs->trans("ExtraFieldsLines");
	$nbExtrafields = (isset($extrafields->attributes['myobjectline']['label']) && is_countable($extrafields->attributes['myobjectline']['label'])) ? count($extrafields->attributes['myobject']['label']) : 0;
	if ($nbExtrafields > 0) {
		$head[$h][1] .= '<span class="badge marginleftonlyshort">' . $nbExtrafields . '</span>';
	}
	$head[$h][2] = 'myobject_extrafieldsline';
	$h++;
	*/

	$head[$h][0] = dol_buildpath("/takeposguard/admin/about.php", 1);
	$head[$h][1] = $langs->trans("About");
	$head[$h][2] = 'about';
	$h++;

	// Show more tabs from modules
	// Entries must be declared in modules descriptor with line
	//$this->tabs = array(
	//	'entity:+tabname:Title:@takeposguard:/takeposguard/mypage.php?id=__ID__'
	//); // to add new tab
	//$this->tabs = array(
	//	'entity:-tabname:Title:@takeposguard:/takeposguard/mypage.php?id=__ID__'
	//); // to remove a tab
	complete_head_from_modules($conf, $langs, null, $head, $h, 'takeposguard@takeposguard');

	complete_head_from_modules($conf, $langs, null, $head, $h, 'takeposguard@takeposguard', 'remove');

	return $head;
}

/**
 * Validate setup values before any database write.
 *
 * @param string $type Setting type
 * @param string $value Submitted value
 * @return bool
 */
function takeposguardValidateSetting($type, $value)
{
	if ($type === 'boolean') {
		return $value === '0' || $value === '1';
	}
	if ($type === 'policy') {
		return $value === 'reject';
	}
	if (!preg_match('/^[1-9][0-9]{0,3}$/D', $value)) {
		return false;
	}
	if ($type === 'seconds') {
		return (int) $value >= 10 && (int) $value <= 3600;
	}
	return $type === 'days' && (int) $value >= 1 && (int) $value <= 3650;
}
