<?php
/* Copyright (C) 2004-2018	Laurent Destailleur			<eldy@users.sourceforge.net>
 * Copyright (C) 2018-2019	Nicolas ZABOURI				<info@inovea-conseil.com>
 * Copyright (C) 2019-2024	Frédéric France				<frederic.france@free.fr>
 * Copyright (C) 2026		SuperAdmin
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

require_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

/** Descriptor for TakePOS Payment Guard. */
class modTakeposguard extends DolibarrModules
{
	/** @param DoliDB $db Database handler */
	public function __construct($db)
	{
		$this->db = $db;
		// Checked against local descriptors and rights_def; not globally reserved.
		$this->numero = 501117;
		$this->rights_class = 'takeposguard';
		$this->family = 'Fred Omega Junior';
		$this->module_position = '90';
		$this->name = 'Takeposguard';
		$this->description = 'ModuleTakeposguardDesc';
		$this->version = '0.5.0';
		$this->editor_name = 'Fred Omega Junior';
		$this->const_name = 'MAIN_MODULE_TAKEPOSGUARD';
		$this->picto = 'fa-shield-alt';
		$this->module_parts = array(
			'triggers' => 0,
			'js' => array('/takeposguard/js/takeposguard.js.php'),
			'hooks' => array('data' => array('takeposinvoice', 'takepospay', 'takeposfrontend'), 'entity' => '0'),
			'moduleforexternal' => 0,
		);
		$this->dirs = array();
		$this->config_page_url = array('setup.php@takeposguard');
		$this->depends = array('modTakePos');
		$this->requiredby = array();
		$this->conflictwith = array();
		$this->langfiles = array('takeposguard@takeposguard');
		$this->phpmin = array(7, 2);
		$this->need_dolibarr_version = array(22, 0, 4);
		$this->need_javascript_ajax = 1;
		// Keep configuration when disabling/re-enabling the module (last flag = 0).
		$this->const = array(
			array('TAKEPOSGUARD_ENABLE', 'chaine', '0', 'Enable payment protection', 0, 'current', 0),
			array('TAKEPOSGUARD_LOCK_TIMEOUT', 'chaine', '120', 'Lock lifetime in seconds', 0, 'current', 0),
			array('TAKEPOSGUARD_HISTORY_DAYS', 'chaine', '90', 'Detailed history retention in days', 0, 'current', 0),
			array('TAKEPOSGUARD_DEBUG_LOG', 'chaine', '0', 'Detailed diagnostic logging', 0, 'current', 0),
			array('TAKEPOSGUARD_MISSING_TOKEN_POLICY', 'chaine', 'reject', 'Missing operation token policy', 0, 'current', 0),
		);
		// Audit and maintenance rights never control whether payment protection applies.
		$this->rights = array(
			array(50111701, 'TakeposguardReadAudit', 'r', 0, 'audit', 'read'),
			array(50111702, 'TakeposguardMaintain', 'w', 0, 'maintenance', 'write'),
		);
		$this->menu = array();
	}

	/** @return int Activation result */
	public function init($options = '')
	{
		if ($this->_load_tables('/takeposguard/sql/') <= 0) {
			return -1;
		}
		return $this->_init(array(), $options);
	}

	/** @return int Deactivation result */
	public function remove($options = '')
	{
		return $this->_remove(array(), $options);
	}
}
