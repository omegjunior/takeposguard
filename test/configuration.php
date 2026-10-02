<?php
/** Standalone checks: php test/configuration.php */
require_once __DIR__.'/../lib/takeposguard.lib.php';
$cases = array(
	array('boolean', '0', true), array('boolean', '1', true), array('boolean', '2', false),
	array('seconds', '10', true), array('seconds', '3600', true),
	array('seconds', '9', false), array('seconds', '3601', false),
	array('seconds', '-120', false), array('seconds', '120.5', false),
	array('seconds', '1e2', false), array('seconds', '', false),
	array('days', '1', true), array('days', '3650', true),
	array('days', '0', false), array('days', '3651', false),
	array('policy', 'reject', true), array('policy', 'allow', false),
	array('unknown', '120', false),
);
foreach ($cases as $case) {
	if (takeposguardValidateSetting($case[0], $case[1]) !== $case[2]) {
		fwrite(STDERR, 'Validation failed: '.json_encode($case).PHP_EOL);
		exit(1);
	}
}
define('DOL_DOCUMENT_ROOT', realpath(__DIR__.'/../../..'));
require_once __DIR__.'/../core/modules/modTakeposguard.class.php';
$module = new modTakeposguard(null);
if ($module->numero !== 501117 || $module->depends !== array('modTakePos') || count($module->rights) !== 2) {
	fwrite(STDERR, 'Descriptor identity/dependencies/rights failed'.PHP_EOL);
	exit(1);
}
foreach ($module->const as $constant) {
	if ($constant[5] !== 'current' || $constant[6] !== 0) {
		fwrite(STDERR, 'Configuration entity/persistence failed'.PHP_EOL);
		exit(1);
	}
}
echo count($cases).' validation cases and descriptor checks passed'.PHP_EOL;
