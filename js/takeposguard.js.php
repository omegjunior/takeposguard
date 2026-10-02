<?php
/* Copyright (C) 2026 Fred Omega Junior
 * SPDX-License-Identifier: GPL-3.0-or-later
 */
// Authenticated, entity-specific asset. Never renew the native CSRF token here.
foreach (array('NOREQUIRESOC', 'NOREQUIREMENU', 'NOREQUIREHTML', 'NOREQUIREAJAX', 'NOTOKENRENEWAL') as $flag) {
	if (!defined($flag)) {
		define($flag, 1);
	}
}
require __DIR__.'/../../../main.inc.php';
header('Content-Type: application/javascript; charset=UTF-8');
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
if (!isModEnabled('takeposguard') || !getDolGlobalInt('TAKEPOSGUARD_ENABLE') || !$user->hasRight('takepos', 'run')) {
	print "/* TakePOS Payment Guard disabled. */\n";
	exit;
}
$langs->load('takeposguard@takeposguard');
$configuration = array(
	'enabled' => true,
	'basePath' => DOL_URL_ROOT.'/takepos/',
	'statusUrl' => dol_buildpath('/takeposguard/ajax/attempt.php', 1),
	'csrfToken' => currentToken(),
	'scope' => (int) $conf->entity.':'.(isset($_SESSION['takeposterminal']) ? (string) $_SESSION['takeposterminal'] : ''),
	'waitSeconds' => max(10, min(3600, getDolGlobalInt('TAKEPOSGUARD_LOCK_TIMEOUT', 120))),
	'messages' => array(
		'pending' => $langs->transnoentitiesnoconv('TakeposguardJavascriptPending'),
		'uncertain' => $langs->transnoentitiesnoconv('TakeposguardJavascriptUncertain'),
		'crypto' => $langs->transnoentitiesnoconv('TakeposguardJavascriptCryptoRequired'),
		'retry' => $langs->transnoentitiesnoconv('TakeposguardJavascriptRetry'),
		'check' => $langs->transnoentitiesnoconv('TakeposguardJavascriptCheck'),
	),
);
print 'window.TakeposguardConfig = '.json_encode($configuration, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT).";\n";
readfile(__DIR__.'/takeposguard.js');
