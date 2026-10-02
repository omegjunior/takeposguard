<?php
/* Copyright (C) 2026 Fred Omega Junior
 * SPDX-License-Identifier: GPL-3.0-or-later
 */
// Keep authentication and native CSRF checks enabled, including for recovery POSTs.
foreach (array('NOREQUIRESOC', 'NOREQUIREMENU', 'NOREQUIREHTML', 'NOREQUIREAJAX', 'NOTOKENRENEWAL') as $flag) {
	if (!defined($flag)) {
		define($flag, 1);
	}
}
require __DIR__.'/../../../main.inc.php';
require_once __DIR__.'/../class/takeposguardattemptservice.class.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');

/* Actions */
if (!isModEnabled('takeposguard') || !getDolGlobalInt('TAKEPOSGUARD_ENABLE') || empty($user->id)
	|| !empty($user->socid) || !$user->hasRight('takepos', 'run') || !$user->hasRight('facture', 'creer')) {
	http_response_code(403);
	exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || GETPOST('action', 'aZ09') !== 'recover') {
	http_response_code(405);
	header('Allow: POST');
	exit;
}
$token = TakeposguardStorage::normalizeToken(GETPOST('takeposguard_token', 'none'));
if (!$token) {
	http_response_code(400);
	exit;
}
$service = new TakeposguardAttemptService($db, new TakeposguardStorage($db));
$status = $service->resolve($token, (int) $user->id, !empty($user->admin) || $user->hasRight('takeposguard', 'maintenance', 'write'));

/* Views */
print json_encode(array('operation_token' => $token, 'status' => $status));
