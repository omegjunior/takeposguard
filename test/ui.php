<?php
/** CLI native component render smoke test; no HTTP session or configuration writes. */
if (PHP_SAPI !== 'cli' || !in_array('--mysql', $argv, true)) { exit(2); }
$nativePrefix = 'tpg_native_'.bin2hex(random_bytes(6)).'_';
require __DIR__.'/native_bootstrap.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/ajax.lib.php';
require_once __DIR__.'/../lib/takeposguard_ui.lib.php';
$conf->browser->name = 'chrome';
$checks = 0;
function uiCheck($condition, $message)
{
	global $checks;
	if (!$condition) { throw new RuntimeException($message); }
	$checks++;
}
try {
	$form = new Form($db);
	foreach (array(false, true) as $locks) {
		$fields = takeposguardListFields($locks);
		$context = $locks ? 'takeposguard_maintenance' : 'takeposguard_audit';
		$pref = 'MAIN_SELECTEDFIELDS_'.$context;
		$user->conf->$pref = 'invoice,status';
		$filters = array_fill_keys(array_keys($fields), '');
		$filters['operation_token'] = '" onmouseover="invalid';
		ob_start();
		print_barre_liste($langs->trans('TakeposguardAudit'), 0, $_SERVER['PHP_SELF'], '', 'datec', 'DESC', '', 51, '', 'shield-alt', 0, '', '', 50);
		$selector = $form->multiSelectArrayWithCheckbox('selectedfields', $fields, $context);
		takeposguardListHead($form, $fields, $filters, $selector, '', 'datec', 'DESC', $locks, 42);
		print '</table></div>';
		$html = ob_get_clean();
		uiCheck(!empty($fields['invoice']['checked']) && empty($fields['datec']['checked']), 'Native user column preference applied');
		uiCheck(strpos($html, 'multiselectcheckboxselectedfields') !== false && strpos($html, 'liste_titre_filter') !== false, 'Native column selector/filter row rendered');
		uiCheck(strpos($html, 'name="search_operation_token"') !== false && strpos($html, ' onmouseover="invalid') === false, 'Hidden filters remain escaped');
		uiCheck(strpos($html, 'button_removefilter_x') !== false && strpos($html, 'button_search_x') !== false, 'Native filter buttons available');
	}
	$conf->use_javascript_ajax = 1;
	$toggle = ajax_constantonoff('TAKEPOSGUARD_ENABLE', array(), 1, 0, 0, 0, 2, 0, 1);
	uiCheck(strpos($toggle, 'constantonoff.php') !== false && strpos($toggle, 'entity') !== false, 'Native entity-aware switch rendered');
	uiCheck(strpos($form->selectarray('policy', array('reject' => 'Reject'), 'reject'), 'value="reject"') !== false, 'Native selectarray policy rendered');
	echo $checks." native UI component checks passed (CLI render, no authenticated browser).\n";
} catch (Throwable $error) {
	fwrite(STDERR, 'FAILED: '.$error->getMessage()."\n"); exit(1);
} finally {
	$db->close();
}
