<?php

declare(strict_types=1);

$module = file_get_contents(dirname(__DIR__) . '/ProcessDbBackup.module.php');

if ($module === false) {
	fwrite(STDERR, "Unable to read module contract.\n");
	exit(1);
}

$expectations = [
	"'version'  => 215",
	"processDbBackupPath",
	"protected function backupDir(): string",
	"protected function backupPath(string \$relative = ''): string",
	"processDbBackupPath must be an absolute path.",
	"\$this->backupPath('.meta.json')",
	"\$this->backupPath('.chunks/')",
];

foreach ($expectations as $expectation) {
	if (strpos($module, $expectation) === false) {
		fwrite(STDERR, "Missing private backup path contract: {$expectation}\n");
		exit(1);
	}
}

preg_match_all("/paths->assets\\s*\\.\\s*self::BACKUP_DIR/", $module, $matches);
if (count($matches[0]) !== 2) {
	fwrite(STDERR, "Unexpected legacy backup path usage outside the resolver/install guard.\n");
	exit(1);
}

echo "ProcessDbBackup private path contract passed.\n";
