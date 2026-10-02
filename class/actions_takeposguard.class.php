<?php
/** Hook registration placeholder. No payment actions are intercepted yet. */
class ActionsTakeposguard
{
	/** @var DoliDB */
	public $db;

	/** @param DoliDB $db Database handler */
	public function __construct($db)
	{
		$this->db = $db;
	}
}
