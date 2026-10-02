<?php
/** Offline verification of Dolibarr's PostgreSQL SQL conversion, not a PG integration test. */
if (PHP_SAPI !== 'cli') {
	exit(2);
}
define('DOL_DOCUMENT_ROOT', realpath(__DIR__.'/../../..'));
require_once DOL_DOCUMENT_ROOT.'/core/lib/functions.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/db/pgsql.class.php';
$conf = new stdClass();
$conf->global = new stdClass();
$driver = (new ReflectionClass('DoliDBPgsql'))->newInstanceWithoutConstructor();
$checks = 0;
foreach (glob(__DIR__.'/../sql/llx_takeposguard*.sql') as $file) {
	$sql = preg_replace('/^--.*$/m', '', file_get_contents($file));
	foreach (explode(';', $sql) as $statement) {
		$statement = trim($statement);
		if ($statement === '') {
			continue;
		}
		$converted = $driver->convertSQLFromMysql($statement.';');
		$converted = preg_replace('/^--.*$/m', '', $converted);
		if (preg_match('/AUTO_INCREMENT|ENGINE\s*=|ON UPDATE CURRENT_TIMESTAMP|ADD (UNIQUE )?INDEX/i', $converted)) {
			fwrite(STDERR, 'Unconverted MySQL syntax in '.basename($file).PHP_EOL);
			exit(1);
		}
		if (strpos($statement, 'CREATE TABLE') === 0 && strpos($converted, 'SERIAL PRIMARY KEY') === false) {
			fwrite(STDERR, 'Missing PostgreSQL primary key in '.basename($file).PHP_EOL);
			exit(1);
		}
		$checks++;
	}
}
echo $checks.' SQL statements converted by the native PostgreSQL driver (no PG database execution).'.PHP_EOL;
