<?php
/* Copyright (C) 2026 Fred Omega Junior; GPL-3.0-or-later */
require_once DOL_DOCUMENT_ROOT.'/core/class/commonobject.class.php';

/** Render-only adapter to the native CommonObject input renderer; no persistence. */
class TakeposguardListForm extends CommonObject
{
	public function __construct($db)
	{
		$this->db = $db;
	}

	public function input($key, $value, $label, $prefix = 'search_')
	{
		$this->fields[$key] = array('type' => 'varchar(255)', 'label' => $label, 'visible' => 1);
		return $this->showInputField($this->fields[$key], $key, $value,
			'aria-label="'.dol_escape_htmltag($label).'"', '', $prefix, 'maxwidth100');
	}
}
