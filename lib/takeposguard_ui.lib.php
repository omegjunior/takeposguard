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
			print '<a class="butAction" href="'.dol_escape_htmltag(dol_buildpath($path, 1).'?mainmenu=home&leftmenu=takeposguard').'">'
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

/** Native list columns; separate keys persist separate preferences on each page. */
function takeposguardListFields($locks = false)
{
	$labels = $locks ? array('invoice' => 'Invoice', 'datec' => 'Date', 'expires_at' => 'TakeposguardExpires',
		'status' => 'Status', 'operation_token' => 'TakeposguardToken')
		: array('datec' => 'Date', 'invoice' => 'Invoice', 'terminal' => 'TakeposguardTerminal', 'user_login' => 'User',
			'payment_code' => 'PaymentMode', 'requested_amount' => 'TakeposguardRequestedAmount', 'actual_amount' => 'TakeposguardActualAmount',
			'status' => 'Status', 'operation_token' => 'TakeposguardToken', 'remain_before' => 'TakeposguardRemainBefore',
			'remain_after' => 'TakeposguardRemainAfter', 'error' => 'Error');
	$fields = array();
	foreach ($labels as $key => $label) { $fields[$key] = array('label' => $label, 'checked' => 1); }
	return $fields;
}

function takeposguardListFilters($fields)
{
	$filters = array();
	foreach ($fields as $key => $field) {
		$filters[$key] = GETPOST('button_removefilter_x', 'aZ09') ? '' : GETPOST('search_'.$key, 'alphanohtml');
	}
	return $filters;
}

/** Filter values also travel with native pagination and sortable title links. */
function takeposguardListParams($filters, $limit, $invoiceId = 0)
{
	$params = '&mainmenu=home&leftmenu=takeposguard&limit='.((int) $limit);
	foreach ($filters as $key => $value) { if ($value !== '') { $params .= '&search_'.$key.'='.urlencode($value); } }
	if ($invoiceId) { $params .= '&invoiceid='.((int) $invoiceId); }
	return $params;
}

/** Render the native list filter/title rows; action column always stays available. */
function takeposguardListHead($form, $fields, $filters, $selector, $params, $sortfield, $sortorder, $locks = false, $invoiceId = 0)
{
	global $langs;
	foreach ($fields as $key => $field) {
		if (!empty($field['checked'])) { continue; }
		// Keep active filters when their column is hidden; clear-filter resets them.
		print '<input type="hidden" name="search_'.$key.'" value="'.dol_escape_htmltag($filters[$key]).'">';
		if ($key === 'invoice' && !$locks) { print '<input type="hidden" name="invoiceid" value="'.((int) $invoiceId).'">'; }
	}
	print '<div class="div-table-responsive"><table class="tagtable nobottomiftotal liste"><tr class="liste_titre_filter">';
	foreach ($fields as $key => $field) {
		if (empty($field['checked'])) { continue; }
		print '<td class="liste_titre">';
		if ($key === 'status') {
			$options = array('' => $langs->trans('All'));
			foreach (array('PROCESSING', 'SUCCESS', 'FAILED', 'BLOCKED') as $status) { $options[$status] = $langs->trans('TakeposguardStatus'.$status); }
			if ($locks) { $options['ORPHAN'] = $langs->trans('TakeposguardOrphanLock'); }
			print $form->selectarray('search_status', $options, $filters[$key], 0, 0, 0, '', 0, 0, 0, '', 'maxwidth150');
		} else {
			$type = in_array($key, array('datec', 'expires_at'), true) ? 'date' : 'text';
			print '<input class="flat maxwidth100" type="'.$type.'" name="search_'.$key.'" value="'.dol_escape_htmltag($filters[$key]).'" aria-label="'.dol_escape_htmltag($langs->trans($field['label'])).'">';
			if ($key === 'invoice' && !$locks) {
				print '<input class="flat maxwidth75" type="number" min="1" name="invoiceid" value="'.($invoiceId ?: '').'" placeholder="'.dol_escape_htmltag($langs->trans('TakeposguardInvoiceId')).'">';
			}
		}
		print '</td>';
	}
	print '<td class="liste_titre center actioncolumn">'.$form->showFilterButtons().'</td></tr><tr class="liste_titre">';
	foreach ($fields as $key => $field) {
		if (!empty($field['checked'])) { print_liste_field_titre($langs->trans($field['label']), $_SERVER['PHP_SELF'], $key, '', $params, '', $sortfield, $sortorder); }
	}
	print '<td class="liste_titre center actioncolumn">'.$selector.'</td></tr>';
}
