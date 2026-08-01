<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
	http_response_code(404);
	exit(1);
}

$options = getopt('', ['type:', 'root:', 'check', 'help']);
if (isset($options['help'])) {
	echo <<<TXT
ProcessDbBackup CLI

Create a backup without blocking a frontend request:
  php bin/process-db-backup.php --type=regular --root=/path/to/processwire

Options:
  --type=regular|weekly|monthly  Backup retention class (default: regular)
  --root=/path/to/processwire   ProcessWire document root
  --check                       Validate the installation without creating a backup
  --help                        Show this help

TXT;
	exit(0);
}

$type = strtolower((string)($options['type'] ?? 'regular'));
if (!in_array($type, ['regular', 'weekly', 'monthly'], true)) {
	fwrite(STDERR, "Invalid backup type: {$type}\n");
	exit(2);
}

$root = rtrim((string)($options['root'] ?? dirname(__DIR__, 4)), '/');
$index = $root . '/index.php';
if (!is_file($index)) {
	fwrite(STDERR, "ProcessWire index.php not found at {$index}\n");
	exit(2);
}

set_time_limit(0);
chdir($root);
ob_start();
require $index;
ob_end_clean();

if (!function_exists('ProcessWire\\wire') || !ProcessWire\wire('modules')->isInstalled('ProcessDbBackup')) {
	fwrite(STDERR, "ProcessDbBackup is not installed.\n");
	exit(3);
}

/** @var ProcessWire\ProcessDbBackup $module */
$module = ProcessWire\wire('modules')->getModule('ProcessDbBackup', ['noPermissionCheck' => true]);
if (isset($options['check'])) {
	echo json_encode([
		'ok' => true,
		'module' => 'ProcessDbBackup',
		'version' => ProcessWire\wire('modules')->getModuleInfoVerbose('ProcessDbBackup')['versionStr'] ?? 'unknown',
		'type' => $type,
	], JSON_UNESCAPED_SLASHES) . PHP_EOL;
	exit(0);
}

$result = $module->createBackup($type);
echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
exit(!empty($result['success']) ? 0 : 1);
