<?php
/** php test/run.php [--mysql]: repeatable local acceptance without business-data writes. */
if (PHP_SAPI !== 'cli') { exit(2); }
if (array_diff(array_slice($argv, 1), array('--mysql'))) {
	fwrite(STDERR, "Usage: php test/run.php [--mysql]\n"); exit(2);
}
function acceptanceCommand($arguments)
{
	$command = implode(' ', array_map('escapeshellarg', $arguments));
	$process = proc_open($command, array(0 => array('pipe', 'r'), 1 => STDOUT, 2 => STDERR), $pipes,
		__DIR__.'/..', null, array('bypass_shell' => true));
	if (!is_resource($process)) { throw new RuntimeException('Cannot start verification command'); }
	fclose($pipes[0]);
	$status = proc_close($process);
	if ($status !== 0) { throw new RuntimeException('Verification failed: '.basename($arguments[0]).' '.basename(end($arguments))); }
}
try {
	$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__.'/..', FilesystemIterator::SKIP_DOTS));
	$count = 0;
	foreach ($files as $file) {
		if ($file->isFile() && strtolower($file->getExtension()) === 'php'
			&& strpos($file->getPathname(), DIRECTORY_SEPARATOR.'.git'.DIRECTORY_SEPARATOR) === false) {
			acceptanceCommand(array(PHP_BINARY, '-l', $file->getPathname()));
			$count++;
		}
	}
	echo $count." PHP files linted.\n";
	foreach (array('configuration', 'audit', 'interception', 'finalization', 'partial_stock', 'lock_dialects', 'sql_portability', 'compatibility') as $test) {
		acceptanceCommand(array(PHP_BINARY, 'test/'.$test.'.php'));
	}
	acceptanceCommand(array('node', '--check', 'js/takeposguard.js'));
	acceptanceCommand(array('node', 'test/javascript.cjs'));
	if (in_array('--mysql', $argv, true)) {
		foreach (array('storage', 'locks', 'native_acceptance', 'ui') as $test) {
			acceptanceCommand(array(PHP_BINARY, 'test/'.$test.'.php', '--mysql'));
		}
	} else {
		echo "MariaDB/native effects NOT TESTED: rerun with --mysql on a test instance.\n";
	}
	echo "Requested local checks passed. HTTP/browser/providers/PostgreSQL server remain separate acceptance gates.\n";
} catch (Throwable $error) {
	fwrite(STDERR, $error->getMessage()."\n"); exit(1);
}
