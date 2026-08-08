<?php

declare(strict_types=1);

$module = file_get_contents(dirname(__DIR__) . '/ProcessDbBackup.module.php');
$cli = file_get_contents(dirname(__DIR__) . '/bin/process-db-backup.php');

if ($module === false || $cli === false) {
	fwrite(STDERR, "Unable to read module contracts.\n");
	exit(1);
}

$expectations = [
	[$module, "'version'  => 215"],
	[$module, 'shouldRegisterLazyCronHooks()'],
	[$module, "array_key_exists('allow_web_lazycron', \$config)"],
	[$module, "PHP_SAPI === 'cli'"],
	[$cli, "--type=regular|weekly|monthly"],
	[$cli, "set_time_limit(0)"],
];

foreach ($expectations as [$source, $expectation]) {
	if (strpos($source, $expectation) === false) {
		fwrite(STDERR, "Missing backup safety contract: {$expectation}\n");
		exit(1);
	}
}

echo "ProcessDbBackup web/CLI safety contract passed.\n";
