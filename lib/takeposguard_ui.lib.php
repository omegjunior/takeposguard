<?php
/* Copyright (C) 2026 Fred Omega Junior; GPL-3.0-or-later */

/** Centralize permission checks for pages and tests, without granting payment rights. */
function takeposguardCanAccess($user, $operation)
{
	if (empty($user->id) || !empty($user->socid) || !in_array($operation, array('audit', 'maintenance'), true)) {
		return false;
	}
	return !empty($user->admin) || $user->hasRight('takeposguard', $operation, $operation === 'audit' ? 'read' : 'write');
}

/** Navigation reused by audit and maintenance; no SQL in rendering loops. */
function takeposguardNavigation($user)
{
	global $langs;
	foreach (array('audit' => '/takeposguard/audit.php', 'maintenance' => '/takeposguard/admin/maintenance.php') as $right => $path) {
		if (takeposguardCanAccess($user, $right)) {
			print '<a class="butAction" href="'.dol_escape_htmltag(dol_buildpath($path, 1)).'">'
				.dol_escape_htmltag($langs->trans($right === 'audit' ? 'TakeposguardAudit' : 'TakeposguardMaintenance')).'</a>';
		}
	}
}

function takeposguardInvoiceLink($row)
{
	$id = (int) $row->fk_invoice;
	return '<a href="'.DOL_URL_ROOT.'/compta/facture/card.php?facid='.$id.'">'
		.dol_escape_htmltag(!empty($row->invoice_ref) ? $row->invoice_ref : (string) $id).'</a>';
}

function takeposguardPaging($path, $page, $more, $query = '')
{
	global $langs;
	print '<div class="center">';
	if ($page > 0) {
		print '<a class="button" href="'.dol_escape_htmltag(dol_buildpath($path, 1).'?page='.($page - 1).$query).'">'.$langs->trans('Previous').'</a> ';
	}
	if ($more) {
		print '<a class="button" href="'.dol_escape_htmltag(dol_buildpath($path, 1).'?page='.($page + 1).$query).'">'.$langs->trans('Next').'</a>';
	}
	print '</div>';
}
