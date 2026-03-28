<?php namespace ProcessWire;

/**
 * ProcessDbBackup
 *
 * Database backup and restore module for ProcessWire.
 * Supports local storage and Backblaze B2, manual and scheduled backups via LazyCron.
 *
 * @author Maxim Semenov <maxim@smnv.org>
 * @version 1.0.0
 * @license MIT
 */
class ProcessDbBackup extends Process implements Module, ConfigurableModule {

	public static function getModuleInfo(): array {
		return [
			'title'    => 'DB Backup',
			'summary'  => 'Database backup and restore with local and Backblaze B2 storage.',
			'version'  => '1.0.0',
			'author'   => 'Maxim Semenov',
			'icon'     => 'database',
			'requires' => ['ProcessWire>=3.0.0', 'PHP>=8.0.0'],
			'installs' => [],
			'page'     => [
				'name'   => 'db-backup',
				'parent' => 'admin',
				'title'  => 'DB Backup',
			],
			'permission'  => 'db-backup',
			'permissions' => [
				'db-backup' => 'Manage database backups',
			],
			'autoload' => true,
			'singular' => true,
		];
	}

	// ── Constants ─────────────────────────────────────────────────────────────

	const BACKUP_DIR     = 'backups/db/';
	const BACKUP_EXT     = '.sql.gz';
	const META_FILE      = 'backups/db/.meta.json';
	const LOG_NAME       = 'db-backup';
	const B2_API_AUTH    = 'https://api.backblazeb2.com/b2api/v3/b2_authorize_account';

	// ── Init ──────────────────────────────────────────────────────────────────

	public function init(): void {
		parent::init();

		// Register LazyCron hook if schedule is configured
		if ($this->cron_interval && $this->cron_interval !== 'never') {
			$this->addHook('LazyCron::' . $this->cron_interval, $this, 'cronBackup');
		}
	}

	// ── Admin UI entry point ──────────────────────────────────────────────────

	public function ___execute(): string {
		$this->headline('DB Backup');
		$this->browserTitle('DB Backup');

		// Handle POST actions
		if ($this->input->post('action')) {
			return $this->handleAction();
		}

		// Handle GET actions
		$action = $this->input->get('action');

		if ($action === 'download') {
			$this->doDownload($this->input->get('file'));
			return '';
		}

		return $this->renderDashboard();
	}

	// ── Action dispatcher ─────────────────────────────────────────────────────

	protected function handleAction(): string {
		$action = $this->input->post('action');
		$this->session->CSRF->validate() or $this->error('CSRF validation failed.');

		switch ($action) {
			case 'create':
				$result = $this->createBackup();
				if ($result['success']) {
					$this->message('Backup created: ' . $result['filename']);
				} else {
					$this->error('Backup failed: ' . $result['error']);
				}
				break;

			case 'restore':
				$file = $this->sanitizer->filename($this->input->post('file'));
				$result = $this->restoreBackup($file);
				if ($result['success']) {
					$this->message('Database restored from: ' . $file);
				} else {
					$this->error('Restore failed: ' . $result['error']);
				}
				break;

			case 'delete':
				$file = $this->sanitizer->filename($this->input->post('file'));
				$result = $this->deleteBackup($file);
				if ($result['success']) {
					$this->message('Backup deleted: ' . $file);
				} else {
					$this->error('Delete failed: ' . $result['error']);
				}
				break;
		}

		$this->session->redirect($this->page->url);
		return '';
	}

	// ── Dashboard render ──────────────────────────────────────────────────────

	protected function renderDashboard(): string {
		$backups  = $this->getBackupList();
		$diskUsed = $this->getDiskUsage();
		$csrf     = $this->session->CSRF->renderInput();
		$pageUrl  = $this->page->url;

		$count     = count($backups);
		$cronLabels = [
			'never'          => 'manual only',
			'every30Seconds' => 'Every 30s',
			'everyMinute'    => 'Every 1m',
			'every2Minutes'  => 'Every 2m',
			'every3Minutes'  => 'Every 3m',
			'every4Minutes'  => 'Every 4m',
			'every5Minutes'  => 'Every 5m',
			'every10Minutes' => 'Every 10m',
			'every15Minutes' => 'Every 15m',
			'every30Minutes' => 'Every 30m',
			'every45Minutes' => 'Every 45m',
			'everyHour'      => 'Every 1h',
			'every2Hours'    => 'Every 2h',
			'every4Hours'    => 'Every 4h',
			'every6Hours'    => 'Every 6h',
			'every12Hours'   => 'Every 12h',
			'everyDay'       => 'Every day',
			'every2Days'     => 'Every 2d',
			'every4Days'     => 'Every 4d',
			'everyWeek'      => 'Every week',
			'every2Weeks'    => 'Every 2w',
			'every4Weeks'    => 'Every 4w',
		];
		$cronLabel = $cronLabels[$this->cron_interval] ?? 'manual only';
		$retention = $this->retention_count > 0 ? (int)$this->retention_count : '&infin;';
		$b2Tag     = $this->b2_enabled ? "<span class=\'uk-badge uk-margin-small-left\'>B2</span>" : '';

		// ── Stats ─────────────────────────────────────────────────────────────
		$html  = "
		<div class='uk-grid-small uk-child-width-auto uk-margin-medium-bottom' uk-grid>
			<div>
				<div class='uk-card uk-card-default uk-card-small uk-card-body'>
					<div class='uk-text-lead uk-text-bold'>{$count}</div>
					<div class='uk-text-small uk-text-muted uk-text-uppercase'>Backups</div>
				</div>
			</div>
			<div>
				<div class='uk-card uk-card-default uk-card-small uk-card-body'>
					<div class='uk-text-lead uk-text-bold'>{$diskUsed}</div>
					<div class='uk-text-small uk-text-muted uk-text-uppercase'>Disk used</div>
				</div>
			</div>
			<div>
				<div class='uk-card uk-card-default uk-card-small uk-card-body'>
					<div class='uk-text-lead uk-text-bold'>{$retention}</div>
					<div class='uk-text-small uk-text-muted uk-text-uppercase'>Retention</div>
				</div>
			</div>
			<div>
				<div class='uk-card uk-card-default uk-card-small uk-card-body'>
					<div class='uk-text-lead uk-text-bold'>{$cronLabel} {$b2Tag}</div>
					<div class='uk-text-small uk-text-muted uk-text-uppercase'>Schedule</div>
				</div>
			</div>
		</div>

		<form method='post' action='{$pageUrl}' class='uk-margin-medium-bottom'>
			{$csrf}
			<input type='hidden' name='action' value='create'>
			<button type='submit' class='uk-button uk-button-primary'>
				<span uk-icon='icon: plus; ratio:.8'></span>&nbsp; Create Backup Now
			</button>
		</form>
		";

		// ── Table ─────────────────────────────────────────────────────────────
		if (empty($backups)) {
			$html .= "
			<p class='uk-text-muted'>
				<span uk-icon='icon: info'></span>
				No backups yet. Click <strong>Create Backup Now</strong> to create your first one.
			</p>";
		} else {
			$html .= "
			<div class='uk-overflow-auto'>
			<table class='uk-table uk-table-small uk-table-divider uk-table-hover uk-table-striped'>
				<thead>
					<tr>
						<th>Filename</th>
						<th class='uk-text-nowrap'>Date</th>
						<th>Size</th>
						<th>Storage</th>
						<th class='uk-text-right'>Actions</th>
					</tr>
				</thead>
				<tbody>";

			foreach ($backups as $b) {
				$filename = htmlspecialchars($b['filename']);
				$date     = htmlspecialchars($b['date']);
				$size     = htmlspecialchars($b['size']);
				$hasLocal = !empty($b['local']);
				$hasB2    = !empty($b['b2']);

				if ($hasLocal && $hasB2) {
					$storageBadge = '<span class="uk-label uk-label-success">Local + B2</span>';
				} elseif ($hasB2) {
					$storageBadge = '<span class="uk-label uk-label-warning">B2 only</span>';
				} else {
					$storageBadge = '<span class="uk-label">Local</span>';
				}

				$confirmRestore = "Restore from {$filename}?\nThe current database will be overwritten.";
				$confirmDelete  = "Delete backup {$filename}?";

				$dlBtn = $hasLocal
					? "<a href=\"{$pageUrl}?action=download&file={$filename}\" class=\"uk-icon-button\" uk-icon=\"cloud-download\" title=\"Download\"></a>"
					: '<span class="uk-icon-button" uk-icon="cloud-download" uk-tooltip="File stored on B2 only" style="opacity:.3;cursor:default"></span>';

				if ($hasLocal) {
					$restoreBtn = "<form method=\"post\" action=\"{$pageUrl}\" class=\"uk-display-inline\">"
						. $csrf
						. '<input type="hidden" name="action" value="restore">'
						. "<input type=\"hidden\" name=\"file\" value=\"{$filename}\">"
						. "<button type=\"submit\" class=\"uk-icon-button\" uk-icon=\"refresh\" uk-tooltip=\"Restore\""
						. " onclick=\"return confirm('" . addslashes($confirmRestore) . "')\"></button>"
						. '</form>';
				} else {
					$restoreBtn = '<span class="uk-icon-button" uk-icon="refresh" uk-tooltip="Download from B2 to restore" style="opacity:.3;cursor:default"></span>';
				}

				$deleteBtn = "<form method=\"post\" action=\"{$pageUrl}\" class=\"uk-display-inline\">"
					. $csrf
					. '<input type="hidden" name="action" value="delete">'
					. "<input type=\"hidden\" name=\"file\" value=\"{$filename}\">"
					. "<button type=\"submit\" class=\"uk-icon-button\" uk-icon=\"trash\" uk-tooltip=\"Delete\""
					. " onclick=\"return confirm('" . addslashes($confirmDelete) . "')\"></button>"
					. '</form>';

				$html .= "<tr>"
					. "<td class=\"uk-text-small uk-text-nowrap\"><span uk-icon=\"icon: file-text; ratio:.8\" class=\"uk-margin-small-right\"></span>{$filename}</td>"
					. "<td class=\"uk-text-small uk-text-muted uk-text-nowrap\">{$date}</td>"
					. "<td class=\"uk-text-small uk-text-nowrap\">{$size}</td>"
					. "<td>{$storageBadge}</td>"
					. "<td class=\"uk-text-right uk-text-nowrap\">{$dlBtn} {$restoreBtn} {$deleteBtn}</td>"
					. "</tr>";
			}
			$html .= "
				</tbody>
			</table>
			</div>";
		}

		return $html;
	}

	// ── Create backup ─────────────────────────────────────────────────────────

	public function createBackup(): array {
		$dir = $this->wire('config')->paths->assets . self::BACKUP_DIR;
		if (!is_dir($dir) && !wireMkdir($dir, true)) {
			return ['success' => false, 'error' => 'Cannot create backup directory.'];
		}

		$filename = 'db-' . date('Y-m-d_His') . self::BACKUP_EXT;
		$filepath = $dir . $filename;

		// Try mysqldump first, fall back to PHP PDO
		$result = $this->dumpViaMysqldump($filepath);
		if (!$result['success']) {
			$result = $this->dumpViaPdo($filepath);
		}

		if (!$result['success']) {
			return $result;
		}

		// Capture size before any potential deletion
		$fileSize = filesize($filepath);

		// Upload to B2 if configured
		$b2Uploaded = false;
		if ($this->b2_enabled) {
			$b2Result = $this->uploadToB2($filepath, $filename);
			$b2Uploaded = $b2Result['success'];
			if ($b2Uploaded) {
				// Delete local file only if "keep local" is NOT checked
				if (!$this->b2_keep_local && file_exists($filepath)) {
					unlink($filepath);
				}
			} else {
				$this->log()->save(self::LOG_NAME, 'B2 upload failed: ' . $b2Result['error']);
			}
		}

		// Save meta — always, even if local file was deleted
		$this->saveMeta($filename, [
			'filename'    => $filename,
			'date'        => date('Y-m-d H:i:s'),
			'size'        => $fileSize,
			'b2'          => $b2Uploaded,
			'local'       => !$this->b2_enabled || (bool)$this->b2_keep_local,
			'method'      => $result['method'],
		]);

		// Enforce retention
		$this->enforceRetention();

		$this->log()->save(self::LOG_NAME, "Backup created: {$filename} (method: {$result['method']}, b2: " . ($b2Uploaded ? 'yes' : 'no') . ')');

		return ['success' => true, 'filename' => $filename];
	}

	// ── mysqldump ─────────────────────────────────────────────────────────────

	protected function dumpViaMysqldump(string $filepath): array {
		$binary = trim(shell_exec('which mysqldump 2>/dev/null') ?? '');
		if (!$binary) return ['success' => false, 'error' => 'mysqldump not found'];

		$cfg  = $this->wire('config');
		$host = escapeshellarg($cfg->dbHost ?: 'localhost');
		$port = $cfg->dbPort ? '-P ' . (int)$cfg->dbPort : '';
		$user = escapeshellarg($cfg->dbUser);
		$pass = $cfg->dbPass ? '-p' . escapeshellarg($cfg->dbPass) : '';
		$name = escapeshellarg($cfg->dbName);
		$out  = escapeshellarg($filepath);

		$cmd = "{$binary} -h {$host} {$port} -u {$user} {$pass} "
			 . "--single-transaction --quick --lock-tables=false "
			 . "--add-drop-table --routines --triggers {$name} | gzip > {$out} 2>&1";

		exec($cmd, $output, $exitCode);

		if ($exitCode !== 0 || !file_exists($filepath) || filesize($filepath) < 100) {
			return ['success' => false, 'error' => 'mysqldump error: ' . implode(' ', $output)];
		}

		return ['success' => true, 'method' => 'mysqldump'];
	}

	// ── PHP PDO fallback dump ─────────────────────────────────────────────────

	protected function dumpViaPdo(string $filepath): array {
		try {
			$cfg  = $this->wire('config');
			$dsn  = "mysql:host={$cfg->dbHost};dbname={$cfg->dbName};charset={$cfg->dbCharset}";
			$pdo  = new \PDO($dsn, $cfg->dbUser, $cfg->dbPass, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
			$fh   = gzopen($filepath, 'wb9');

			if (!$fh) return ['success' => false, 'error' => 'Cannot open output file.'];

			gzwrite($fh, "-- ProcessWire DB Backup\n-- Generated: " . date('Y-m-d H:i:s') . "\n");
			gzwrite($fh, "SET FOREIGN_KEY_CHECKS=0;\n\n");

			// Get tables
			$tables = $pdo->query("SHOW TABLES")->fetchAll(\PDO::FETCH_COLUMN);

			foreach ($tables as $table) {
				$escapedTable = '`' . $table . '`';

				// DROP + CREATE
				gzwrite($fh, "DROP TABLE IF EXISTS {$escapedTable};\n");
				$createRow = $pdo->query("SHOW CREATE TABLE {$escapedTable}")->fetch(\PDO::FETCH_NUM);
				gzwrite($fh, $createRow[1] . ";\n\n");

				// Data
				$rows = $pdo->query("SELECT * FROM {$escapedTable}")->fetchAll(\PDO::FETCH_ASSOC);
				if ($rows) {
					$columns = '`' . implode('`, `', array_keys($rows[0])) . '`';
					gzwrite($fh, "INSERT INTO {$escapedTable} ({$columns}) VALUES\n");
					$chunks = array_chunk($rows, 100);
					foreach ($chunks as $ci => $chunk) {
						$vals = array_map(function($row) use ($pdo) {
							return '(' . implode(', ', array_map(
								fn($v) => is_null($v) ? 'NULL' : $pdo->quote($v),
								array_values($row)
							)) . ')';
						}, $chunk);
						gzwrite($fh, implode(",\n", $vals));
						gzwrite($fh, ($ci < count($chunks) - 1) ? ",\n" : ";\n\n");
					}
				}
			}

			gzwrite($fh, "SET FOREIGN_KEY_CHECKS=1;\n");
			gzclose($fh);

			return ['success' => true, 'method' => 'pdo'];

		} catch (\Exception $e) {
			return ['success' => false, 'error' => 'PDO dump error: ' . $e->getMessage()];
		}
	}

	// ── Restore ───────────────────────────────────────────────────────────────

	public function restoreBackup(string $filename): array {
		$filepath = $this->wire('config')->paths->assets . self::BACKUP_DIR . $filename;

		if (!file_exists($filepath)) {
			return ['success' => false, 'error' => 'Backup file not found.'];
		}

		if (!preg_match('/^db-[\d_\-]+\.sql\.gz$/', $filename)) {
			return ['success' => false, 'error' => 'Invalid filename.'];
		}

		// Try mysql CLI first
		$result = $this->restoreViaMysql($filepath);
		if (!$result['success']) {
			$result = $this->restoreViaPdo($filepath);
		}

		if ($result['success']) {
			$this->log()->save(self::LOG_NAME, "Database restored from: {$filename} (method: {$result['method']})");
		}

		return $result;
	}

	protected function restoreViaMysql(string $filepath): array {
		$binary = trim(shell_exec('which mysql 2>/dev/null') ?? '');
		if (!$binary) return ['success' => false, 'error' => 'mysql CLI not found'];

		$cfg  = $this->wire('config');
		$host = escapeshellarg($cfg->dbHost ?: 'localhost');
		$port = $cfg->dbPort ? '-P ' . (int)$cfg->dbPort : '';
		$user = escapeshellarg($cfg->dbUser);
		$pass = $cfg->dbPass ? '-p' . escapeshellarg($cfg->dbPass) : '';
		$name = escapeshellarg($cfg->dbName);
		$src  = escapeshellarg($filepath);

		$cmd = "zcat {$src} | {$binary} -h {$host} {$port} -u {$user} {$pass} {$name} 2>&1";
		exec($cmd, $output, $exitCode);

		if ($exitCode !== 0) {
			return ['success' => false, 'error' => 'mysql CLI error: ' . implode(' ', $output)];
		}

		return ['success' => true, 'method' => 'mysql-cli'];
	}

	protected function restoreViaPdo(string $filepath): array {
		try {
			$cfg = $this->wire('config');
			$dsn = "mysql:host={$cfg->dbHost};dbname={$cfg->dbName};charset={$cfg->dbCharset}";
			$pdo = new \PDO($dsn, $cfg->dbUser, $cfg->dbPass, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);

			$sql  = '';
			$fh   = gzopen($filepath, 'rb');
			if (!$fh) return ['success' => false, 'error' => 'Cannot open backup file.'];

			while (!gzeof($fh)) {
				$sql .= gzread($fh, 65536);
			}
			gzclose($fh);

			$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
			// Split on statement delimiter
			$statements = preg_split('/;\s*\n/', $sql);
			foreach ($statements as $stmt) {
				$stmt = trim($stmt);
				if ($stmt && !str_starts_with($stmt, '--')) {
					$pdo->exec($stmt);
				}
			}
			$pdo->exec('SET FOREIGN_KEY_CHECKS=1');

			return ['success' => true, 'method' => 'pdo'];

		} catch (\Exception $e) {
			return ['success' => false, 'error' => 'PDO restore error: ' . $e->getMessage()];
		}
	}

	// ── Delete backup ─────────────────────────────────────────────────────────

	public function deleteBackup(string $filename): array {
		if (!preg_match('/^db-[\d_\-]+\.sql\.gz$/', $filename)) {
			return ['success' => false, 'error' => 'Invalid filename.'];
		}

		$filepath = $this->wire('config')->paths->assets . self::BACKUP_DIR . $filename;

		if (file_exists($filepath)) {
			unlink($filepath);
		}

		// Delete from B2 if applicable
		$meta = $this->getMeta();
		if (isset($meta[$filename]['b2']) && $meta[$filename]['b2']) {
			$this->deleteFromB2($filename);
		}

		$this->removeMeta($filename);

		return ['success' => true];
	}

	// ── Download ──────────────────────────────────────────────────────────────

	protected function doDownload(string $filename): void {
		$filename = $this->sanitizer->filename($filename);

		if (!preg_match('/^db-[\d_\-]+\.sql\.gz$/', $filename)) {
			$this->error('Invalid filename.');
			return;
		}

		$filepath = $this->wire('config')->paths->assets . self::BACKUP_DIR . $filename;

		if (!file_exists($filepath)) {
			$this->error('File not found.');
			return;
		}

		header('Content-Type: application/gzip');
		header('Content-Disposition: attachment; filename="' . $filename . '"');
		header('Content-Length: ' . filesize($filepath));
		header('Cache-Control: no-cache');
		readfile($filepath);
		exit;
	}

	// ── Retention ─────────────────────────────────────────────────────────────

	protected function enforceRetention(): void {
		$max = (int)$this->retention_count;
		if ($max <= 0) return;

		$backups = $this->getBackupList();

		if (count($backups) > $max) {
			$toDelete = array_slice($backups, $max);
			foreach ($toDelete as $b) {
				$this->deleteBackup($b['filename']);
			}
		}
	}

	// ── Cron hook ─────────────────────────────────────────────────────────────

	public function cronBackup(HookEvent $event): void {
		$result = $this->createBackup();
		if (!$result['success']) {
			$this->log()->save(self::LOG_NAME, 'Cron backup failed: ' . $result['error']);
		}
	}

	// ── Backup list ───────────────────────────────────────────────────────────

	protected function getBackupList(): array {
		$meta = $this->getMeta();
		$dir  = $this->wire('config')->paths->assets . self::BACKUP_DIR;
		$list = [];

		foreach ($meta as $filename => $m) {
			$localExists = file_exists($dir . $filename);
			// Show entry if file exists locally OR was uploaded to B2
			if (!$localExists && empty($m['b2'])) continue;

			$list[] = [
				'filename' => $filename,
				'date'     => $m['date'] ?? '',
				'size'     => $this->formatBytes((int)($m['size'] ?? 0)),
				'b2'       => !empty($m['b2']),
				'local'    => $localExists,
			];
		}

		// Newest first
		usort($list, fn($a, $b) => strcmp($b['date'], $a['date']));

		return $list;
	}

	protected function getDiskUsage(): string {
		$dir  = $this->wire('config')->paths->assets . self::BACKUP_DIR;
		if (!is_dir($dir)) return '0 B';
		$files = glob($dir . 'db-*' . self::BACKUP_EXT) ?: [];
		$total = array_sum(array_map('filesize', $files));
		return $this->formatBytes((int)$total);
	}

	protected function formatBytes(int $bytes): string {
		if ($bytes >= 1073741824) return round($bytes / 1073741824, 2) . ' GB';
		if ($bytes >= 1048576)    return round($bytes / 1048576, 2) . ' MB';
		if ($bytes >= 1024)       return round($bytes / 1024, 2) . ' KB';
		return $bytes . ' B';
	}

	// ── Meta store ────────────────────────────────────────────────────────────

	protected function getMeta(): array {
		$path = $this->wire('config')->paths->assets . self::META_FILE;
		if (!file_exists($path)) return [];
		$json = json_decode(file_get_contents($path), true);
		return is_array($json) ? $json : [];
	}

	protected function saveMeta(string $filename, array $data): void {
		$meta = $this->getMeta();
		$meta[$filename] = $data;
		$path = $this->wire('config')->paths->assets . self::META_FILE;
		file_put_contents($path, json_encode($meta, JSON_PRETTY_PRINT));
	}

	protected function removeMeta(string $filename): void {
		$meta = $this->getMeta();
		unset($meta[$filename]);
		$path = $this->wire('config')->paths->assets . self::META_FILE;
		file_put_contents($path, json_encode($meta, JSON_PRETTY_PRINT));
	}

	// ── Backblaze B2 ─────────────────────────────────────────────────────────

	protected function getB2Token(): array|false {
		$keyId  = $this->b2_key_id;
		$appKey = $this->b2_app_key;

		if (!$keyId || !$appKey) return false;

		$ch = curl_init(self::B2_API_AUTH);
		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_USERPWD        => "{$keyId}:{$appKey}",
		]);
		$resp = curl_exec($ch);
		$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		if ($code !== 200) return false;

		return json_decode($resp, true);
	}

	protected function uploadToB2(string $filepath, string $filename): array {
		$auth = $this->getB2Token();
		if (!$auth) return ['success' => false, 'error' => 'B2 auth failed'];

		$apiUrl   = $auth['apiInfo']['storageApi']['apiUrl'];
		$authToken = $auth['authorizationToken'];
		$bucketId = $this->b2_bucket_id;

		// Get upload URL
		$ch = curl_init("{$apiUrl}/b2api/v3/b2_get_upload_url");
		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_POST           => true,
			CURLOPT_POSTFIELDS     => json_encode(['bucketId' => $bucketId]),
			CURLOPT_HTTPHEADER     => ["Authorization: {$authToken}", "Content-Type: application/json"],
		]);
		$resp = json_decode(curl_exec($ch), true);
		curl_close($ch);

		if (empty($resp['uploadUrl'])) {
			return ['success' => false, 'error' => 'Could not get B2 upload URL'];
		}

		$uploadUrl   = $resp['uploadUrl'];
		$uploadToken = $resp['authorizationToken'];
		$fileData    = file_get_contents($filepath);
		$sha1        = sha1($fileData);
		$prefix      = $this->b2_prefix ? rtrim($this->b2_prefix, '/') . '/' : '';

		$ch = curl_init($uploadUrl);
		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_POST           => true,
			CURLOPT_POSTFIELDS     => $fileData,
			CURLOPT_HTTPHEADER     => [
				"Authorization: {$uploadToken}",
				"X-Bz-File-Name: " . urlencode($prefix . $filename),
				"Content-Type: application/gzip",
				"Content-Length: " . strlen($fileData),
				"X-Bz-Content-Sha1: {$sha1}",
			],
		]);
		$result = json_decode(curl_exec($ch), true);
		$code   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		if ($code !== 200) {
			return ['success' => false, 'error' => $result['message'] ?? 'B2 upload error'];
		}

		return ['success' => true, 'fileId' => $result['fileId']];
	}

	protected function deleteFromB2(string $filename): void {
		$auth = $this->getB2Token();
		if (!$auth) return;

		$apiUrl    = $auth['apiInfo']['storageApi']['apiUrl'];
		$authToken = $auth['authorizationToken'];
		$bucketId  = $this->b2_bucket_id;
		$prefix    = $this->b2_prefix ? rtrim($this->b2_prefix, '/') . '/' : '';
		$b2Name    = $prefix . $filename;

		// Search for file
		$ch = curl_init("{$apiUrl}/b2api/v3/b2_list_file_names");
		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_POST           => true,
			CURLOPT_POSTFIELDS     => json_encode([
				'bucketId'  => $bucketId,
				'prefix'    => $b2Name,
				'maxFileCount' => 1,
			]),
			CURLOPT_HTTPHEADER => ["Authorization: {$authToken}", "Content-Type: application/json"],
		]);
		$resp = json_decode(curl_exec($ch), true);
		curl_close($ch);

		if (empty($resp['files'][0])) return;

		$fileId = $resp['files'][0]['fileId'];

		// Delete
		$ch = curl_init("{$apiUrl}/b2api/v3/b2_delete_file_version");
		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_POST           => true,
			CURLOPT_POSTFIELDS     => json_encode(['fileId' => $fileId, 'fileName' => $b2Name]),
			CURLOPT_HTTPHEADER     => ["Authorization: {$authToken}", "Content-Type: application/json"],
		]);
		curl_exec($ch);
		curl_close($ch);
	}

	// ── CSS ───────────────────────────────────────────────────────────────────

	protected function renderStyles(): string {
		// All styling delegated to UIkit — no custom CSS needed
		return '';
	}

	// ── Module config (static) ────────────────────────────────────────────────

	public static function getModuleConfigInputfields(array $data): InputfieldWrapper {
		$modules  = wire('modules');
		$wrapper  = new InputfieldWrapper();

		// ── Retention ──────────────────────────────────────────────────────────
		$f = $modules->get('InputfieldInteger');
		$f->attr('name', 'retention_count');
		$f->label = 'Max backups to keep (retention)';
		$f->description = 'Oldest backups are deleted automatically. 0 = unlimited.';
		$f->attr('value', $data['retention_count'] ?? 10);
		$f->min = 0;
		$f->columnWidth = 50;
		$wrapper->add($f);

		// ── Cron interval ──────────────────────────────────────────────────────
		$f = $modules->get('InputfieldSelect');
		$f->attr('name', 'cron_interval');
		$f->label = 'Auto-backup schedule (LazyCron)';
		$f->addOptions([
			'never'          => 'Never (manual only)',
			'every30Seconds' => 'Every 30 seconds',
			'everyMinute'    => 'Every minute',
			'every2Minutes'  => 'Every 2 minutes',
			'every3Minutes'  => 'Every 3 minutes',
			'every4Minutes'  => 'Every 4 minutes',
			'every5Minutes'  => 'Every 5 minutes',
			'every10Minutes' => 'Every 10 minutes',
			'every15Minutes' => 'Every 15 minutes',
			'every30Minutes' => 'Every 30 minutes',
			'every45Minutes' => 'Every 45 minutes',
			'everyHour'      => 'Every hour',
			'every2Hours'    => 'Every 2 hours',
			'every4Hours'    => 'Every 4 hours',
			'every6Hours'    => 'Every 6 hours',
			'every12Hours'   => 'Every 12 hours',
			'everyDay'       => 'Every day',
			'every2Days'     => 'Every 2 days',
			'every4Days'     => 'Every 4 days',
			'everyWeek'      => 'Every week',
			'every2Weeks'    => 'Every 2 weeks',
			'every4Weeks'    => 'Every 4 weeks',
		]);
		$f->attr('value', $data['cron_interval'] ?? 'never');
		$f->columnWidth = 50;
		$wrapper->add($f);

		// ── B2 fieldset ────────────────────────────────────────────────────────
		$fs = $modules->get('InputfieldFieldset');
		$fs->label = 'Backblaze B2';
		$fs->collapsed = Inputfield::collapsedNo;

		$f = $modules->get('InputfieldCheckbox');
		$f->attr('name', 'b2_enabled');
		$f->label = 'Enable Backblaze B2 upload';
		$f->attr('checked', !empty($data['b2_enabled']));
		$f->attr('value', 1);
		$f->columnWidth = 50;
		$fs->add($f);

		$f = $modules->get('InputfieldCheckbox');
		$f->attr('name', 'b2_keep_local');
		$f->label = 'Keep local copy when B2 is enabled';
		$f->description = 'If unchecked, local file is deleted after successful B2 upload.';
		$f->attr('checked', !empty($data['b2_keep_local']));
		$f->attr('value', 1);
		$f->columnWidth = 50;
		$f->showIf = 'b2_enabled=1';
		$fs->add($f);

		$f = $modules->get('InputfieldText');
		$f->attr('name', 'b2_key_id');
		$f->label = 'Application Key ID';
		$f->attr('value', $data['b2_key_id'] ?? '');
		$f->columnWidth = 50;
		$fs->add($f);

		$f = $modules->get('InputfieldText');
		$f->attr('name', 'b2_app_key');
		$f->label = 'Application Key (secret)';
		$f->attr('type', 'password');
		$f->attr('value', $data['b2_app_key'] ?? '');
		$f->columnWidth = 50;
		$fs->add($f);

		$f = $modules->get('InputfieldText');
		$f->attr('name', 'b2_bucket_id');
		$f->label = 'Bucket ID';
		$f->attr('value', $data['b2_bucket_id'] ?? '');
		$f->columnWidth = 50;
		$fs->add($f);

		$f = $modules->get('InputfieldText');
		$f->attr('name', 'b2_prefix');
		$f->label = 'Path prefix in bucket (optional)';
		$f->placeholder = 'e.g. mysite/db-backups';
		$f->attr('value', $data['b2_prefix'] ?? '');
		$f->columnWidth = 50;
		$fs->add($f);

		$wrapper->add($fs);

		return $wrapper;
	}

	// ── Install / Uninstall ───────────────────────────────────────────────────

	public function ___install(): void {
		parent::___install();

		$dir = $this->wire('config')->paths->assets . self::BACKUP_DIR;
		if (!is_dir($dir)) wireMkdir($dir, true);

		// .htaccess protection for local backups
		$htaccess = dirname($dir) . '/.htaccess';
		if (!file_exists($htaccess)) {
			file_put_contents(
				$this->wire('config')->paths->assets . 'backups/.htaccess',
				"Order Deny,Allow\nDeny from all\n"
			);
		}
	}

	public function ___uninstall(): void {
		parent::___uninstall();
	}
}
