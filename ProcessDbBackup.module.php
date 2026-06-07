<?php namespace ProcessWire;

/**
 * ProcessDbBackup
 *
 * Database backup and restore module for ProcessWire.
 * Supports local storage and Backblaze B2, manual and scheduled backups via LazyCron.
 *
 * @author Maxim Semenov <maxim@smnv.org>
 * @version 2.1.1
 * @license MIT
 */
class ProcessDbBackup extends Process implements Module, ConfigurableModule {

	public static function getModuleInfo(): array {
		return [
			'title'    => 'DB Backup',
			'summary'  => 'Database backup and restore with local and Backblaze B2 storage, backup types (regular/weekly/monthly), chunked upload, streaming restore.',
			'version'  => 211,
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
	const LOCK_FILE      = 'backups/db/.lock'; // base, type appended at runtime
	const CHUNK_DIR      = 'backups/db/.chunks/';
	const LOG_NAME       = 'db-backup';
	const B2_API_AUTH    = 'https://api.backblazeb2.com/b2api/v3/b2_authorize_account';

	// ── Init ──────────────────────────────────────────────────────────────────

	public function init(): void {
		parent::init();

		// Regular schedule
		if ($this->cron_interval && $this->cron_interval !== 'never') {
			$this->addHook('LazyCron::' . $this->cron_interval, $this, 'cronBackup');
		}
		// Weekly schedule
		if ($this->cron_weekly && $this->cron_weekly !== 'never') {
			$this->addHook('LazyCron::' . $this->cron_weekly, $this, 'cronBackupWeekly');
		}
		// Monthly schedule
		if ($this->cron_monthly && $this->cron_monthly !== 'never') {
			$this->addHook('LazyCron::' . $this->cron_monthly, $this, 'cronBackupMonthly');
		}

		// Dashboard widget on PW admin home
		$this->addHookAfter('ProcessHome::execute', $this, 'renderWidget');

		// Migrate legacy meta entries — only in admin context, not on every frontend request
		if ($this->wire('page') && $this->wire('page')->template == 'admin') {
			$this->migrateMeta();
		}
	}

	// ── Admin UI entry point ──────────────────────────────────────────────────

	public function ___execute(): string {
		$this->headline('DB Backup');
		$this->browserTitle('DB Backup');

		// AJAX: chunk upload
		if ($this->input->get('pdb_ajax') === 'chunk') {
			$this->handleChunkUpload();
			return '';
		}

		// AJAX: create backup with progress
		if ($this->input->get('pdb_ajax') === 'create') {
			$this->handleAjaxCreate();
			return '';
		}

		// AJAX: save label
		if ($this->input->get('pdb_ajax') === 'label') {
			$this->handleAjaxLabel();
			return '';
		}

		// Handle POST actions
		if ($this->input->post('action') || !empty($_POST['action'])) {
			return $this->handleAction();
		}

		// Handle GET actions
		$action = $this->input->get('action');

		if ($action === 'download') {
			$this->doDownload($this->input->get('file'));
			return '';
		}

		if ($action === 'verify') {
			return $this->renderVerify($this->input->get('file'));
		}

		if ($action === 'partial') {
			return $this->renderPartialRestore($this->input->get('file'));
		}

		return $this->renderDashboard();
	}

	// ── Action dispatcher ─────────────────────────────────────────────────────

	protected function handleAction(): string {
		// input->post may not populate for multipart/form-data — fall back to $_POST
		$action = $this->input->post('action') ?: ($_POST['action'] ?? '');
		if (!$this->session->CSRF->validate()) {
			$this->error('CSRF validation failed.');
			$this->session->redirect($this->page->url);
			return '';
		}

		switch ($action) {
			case 'create':
				$result = $this->createBackup('regular');
				if ($result['success']) {
					$this->message('Backup created: ' . $result['filename']);
				} else {
					$this->error('Backup failed: ' . $result['error']);
				}
				break;

			case 'create_typed':
				$btype  = $this->input->post('backup_type') ?? 'regular';
				$btype  = in_array($btype, ['regular', 'weekly', 'monthly']) ? $btype : 'regular';
				$result = $this->createBackup($btype);
				if ($result['success']) {
					$this->message(ucfirst($btype) . ' backup created: ' . $result['filename']);
				} else {
					$this->error(ucfirst($btype) . ' backup failed: ' . $result['error']);
				}
				break;

			case 'restore':
				$file = basename((string)($this->input->post('file') ?? ''));
				$result = $this->restoreBackup($file);
				if ($result['success']) {
					$this->message('Database restored from: ' . $file);
					if (!empty($result['pre_backup'])) {
						$this->message('Pre-restore backup saved: ' . $result['pre_backup']);
					}
				} else {
					$this->error('Restore failed: ' . $result['error']);
				}
				break;

			case 'delete':
				$file = basename((string)($this->input->post('file') ?? ''));
				$result = $this->deleteBackup($file);
				if ($result['success']) {
					$this->message('Backup deleted: ' . $file);
				} else {
					$this->error('Delete failed: ' . $result['error']);
				}
				break;


			case 'partial_restore':
				$file   = basename((string)($this->input->post('file') ?? ''));
				$tables = $this->input->post('tables');
				$tables = is_array($tables) ? array_map([$this->sanitizer, 'name'], $tables) : [];
				if (empty($tables)) {
					$this->error('No tables selected for partial restore.');
					break;
				}
				$result = $this->partialRestoreBackup($file, $tables);
				if ($result['success']) {
					$this->message('Partial restore complete: ' . implode(', ', $result['restored_tables']));
					if (!empty($result['pre_backup'])) {
						$this->message('Pre-restore backup: ' . $result['pre_backup']);
					}
				} else {
					$this->error('Partial restore failed: ' . $result['error']);
				}
				break;

			case 'set_label':
				$file  = basename((string)($this->input->post('file') ?? ''));
				$label = $this->sanitizer->text($this->input->post('label'));
				$meta  = $this->getMeta();
				if (isset($meta[$file])) {
					$meta[$file]['label'] = $label;
					$metaPath = $this->wire('config')->paths->assets . self::META_FILE;
					file_put_contents($metaPath, json_encode($meta, JSON_PRETTY_PRINT), LOCK_EX);
					$this->message('Label saved.');
				}
				break;
		}

		$this->session->redirect($this->page->url);
		return '';
	}

	// ── Dashboard render ──────────────────────────────────────────────────────

	protected function renderDashboard(): string {
		$allBackups = $this->getBackupList();
		$diskUsed   = $this->getDiskUsage();
		$csrf       = $this->session->CSRF->renderInput();
		$pageUrl    = $this->page->url;

		// Active type filter
		$allowedTypes = ['all', 'regular', 'weekly', 'monthly', 'uploaded'];
		$filterType   = in_array($this->input->get('type'), $allowedTypes) ? $this->input->get('type') : 'all';
		$backups = $filterType === 'all'
			? $allBackups
			: array_values(array_filter($allBackups, fn($b) => ($b['type'] ?? 'regular') === $filterType));

		// Sorting
		$allowedSortFields = ['filename', 'date', 'size', 'type', 'label'];
		$sortBy  = in_array($this->input->get('sort'), $allowedSortFields) ? $this->input->get('sort') : 'date';
		$sortDir = $this->input->get('dir') === 'asc' ? 'asc' : 'desc';
		usort($backups, function($a, $b) use ($sortBy, $sortDir) {
			// Use raw bytes for size sort to avoid lexicographic ordering
			$key = $sortBy === 'size' ? 'size_raw' : $sortBy;
			$va  = $a[$key] ?? '';
			$vb  = $b[$key] ?? '';
			$cmp = is_int($va) ? ($va <=> $vb) : strcmp((string)$va, (string)$vb);
			return $sortDir === 'asc' ? $cmp : -$cmp;
		});

		$count = count($allBackups);

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
		$cronLabel  = $cronLabels[$this->cron_interval] ?? 'manual only';
		$retention  = $this->retention_count > 0 ? (int)$this->retention_count : '&infin;';
		$b2Tag      = $this->b2_enabled ? "<span class=\"uk-badge uk-margin-small-left\">B2</span>" : '';

		// Type counts for tabs
		$typeCounts = ['all' => count($allBackups), 'regular' => 0, 'weekly' => 0, 'monthly' => 0, 'uploaded' => 0];
		foreach ($allBackups as $b) $typeCounts[$b['type'] ?? 'regular'] = ($typeCounts[$b['type'] ?? 'regular'] ?? 0) + 1;

		// ── Stats cards ───────────────────────────────────────────────────────
		$html = "
		<div class=\"uk-grid-small uk-child-width-auto uk-margin-medium-bottom\" uk-grid>
			<div><div class=\"uk-card uk-card-default uk-card-small uk-card-body\">
				<div class=\"uk-text-lead uk-text-bold\">{$count}</div>
				<div class=\"uk-text-small uk-text-muted uk-text-uppercase\">Backups</div>
			</div></div>
			<div><div class=\"uk-card uk-card-default uk-card-small uk-card-body\">
				<div class=\"uk-text-lead uk-text-bold\">{$diskUsed}</div>
				<div class=\"uk-text-small uk-text-muted uk-text-uppercase\">Disk used</div>
			</div></div>
			<div><div class=\"uk-card uk-card-default uk-card-small uk-card-body\">
				<div class=\"uk-text-lead uk-text-bold\">{$retention}</div>
				<div class=\"uk-text-small uk-text-muted uk-text-uppercase\">Retention</div>
			</div></div>
			<div><div class=\"uk-card uk-card-default uk-card-small uk-card-body\">
				<div class=\"uk-text-lead uk-text-bold\">{$cronLabel} {$b2Tag}</div>
				<div class=\"uk-text-small uk-text-muted uk-text-uppercase\">Schedule</div>
			</div></div>
		</div>";

		// ── Create buttons ────────────────────────────────────────────────────
		$weeklyEnabled  = $this->cron_weekly  && $this->cron_weekly  !== 'never';
		$monthlyEnabled = $this->cron_monthly && $this->cron_monthly !== 'never';

		$html .= '
		<div class="uk-margin-medium-bottom">
			<div class="uk-flex uk-flex-middle uk-flex-wrap" style="gap:8px">
				<button id="pdb-create-btn" class="uk-button uk-button-primary" onclick="pdbCreateBackup(this, \'regular\')">
					<span uk-icon="icon: plus; ratio:.8"></span>&nbsp; Create Regular
				</button>';

		if ($weeklyEnabled) {
			$html .= '
				<form method="post" action="' . $pageUrl . '" class="uk-display-inline">
					' . $csrf . '
					<input type="hidden" name="action" value="create_typed">
					<input type="hidden" name="backup_type" value="weekly">
					<button type="submit" class="uk-button uk-button-default">
						<span uk-icon="icon: calendar; ratio:.8"></span>&nbsp; Create Weekly
					</button>
				</form>';
		}

		if ($monthlyEnabled) {
			$html .= '
				<form method="post" action="' . $pageUrl . '" class="uk-display-inline">
					' . $csrf . '
					<input type="hidden" name="action" value="create_typed">
					<input type="hidden" name="backup_type" value="monthly">
					<button type="submit" class="uk-button uk-button-default">
						<span uk-icon="icon: star; ratio:.8"></span>&nbsp; Create Monthly
					</button>
				</form>';
		}

		$html .= '
				<div id="pdb-progress" class="uk-hidden" style="flex:1;min-width:200px;max-width:300px">
					<progress class="uk-progress" id="pdb-progress-bar" value="0" max="100" style="margin:0"></progress>
				</div>
				<span id="pdb-progress-msg" class="uk-text-small uk-text-muted"></span>
			</div>
		</div>';

		// ── Compute next backup estimates ────────────────────────────────────────
		$intervalSeconds = [
			'every30Seconds' => 30,        'everyMinute'    => 60,
			'every2Minutes'  => 120,       'every3Minutes'  => 180,
			'every4Minutes'  => 240,       'every5Minutes'  => 300,
			'every10Minutes' => 600,       'every15Minutes' => 900,
			'every30Minutes' => 1800,      'every45Minutes' => 2700,
			'everyHour'      => 3600,      'every2Hours'    => 7200,
			'every4Hours'    => 14400,     'every6Hours'    => 21600,
			'every12Hours'   => 43200,     'everyDay'       => 86400,
			'every2Days'     => 172800,    'every4Days'     => 345600,
			'everyWeek'      => 604800,    'every2Weeks'    => 1209600,
			'every4Weeks'    => 2419200,
		];

		$estimateLabel = function(string $cronKey, string $type) use ($allBackups, $intervalSeconds): string {
			if (!$cronKey || $cronKey === 'never') return '';
			$interval = $intervalSeconds[$cronKey] ?? 0;
			if (!$interval) return '';

			// Find latest backup of this type
			$latest = null;
			foreach ($allBackups as $b) {
				if (($b['type'] ?? 'regular') === $type) { $latest = $b; break; }
			}

			if (!$latest) {
				return '<span class="uk-text-muted uk-text-small"> · Next: now</span>';
			}

			$lastTs  = strtotime($latest['date']);
			$nextTs  = $lastTs + $interval;
			$diff    = $nextTs - time();

			if ($diff <= 0) {
				return '<span class="uk-text-muted uk-text-small"> · Next: on next page load</span>';
			}

			if ($diff < 3600)       $eta = round($diff / 60) . 'm';
			elseif ($diff < 86400)  $eta = round($diff / 3600, 1) . 'h';
			elseif ($diff < 604800) $eta = round($diff / 86400, 1) . 'd';
			else                    $eta = round($diff / 604800, 1) . 'w';

			return '<span class="uk-text-muted uk-text-small"> · Next: ~' . $eta . '</span>';
		};

		// ── Type filter tabs ───────────────────────────────────────────────────
		$tabDefs = [
			'all'      => ['label' => 'All',      'estimate' => ''],
			'regular'  => ['label' => 'Regular',  'estimate' => ''],
			'weekly'   => ['label' => 'Weekly',   'estimate' => $estimateLabel($this->cron_weekly ?? 'never', 'weekly')],
			'monthly'  => ['label' => 'Monthly',  'estimate' => $estimateLabel($this->cron_monthly ?? 'never', 'monthly')],
			'uploaded' => ['label' => 'Uploaded', 'estimate' => ''],
		];
		$tabHtml = '<ul class="uk-tab uk-margin-small-bottom">';
		foreach ($tabDefs as $tab => $def) {
			$cnt    = $typeCounts[$tab] ?? 0;
			$active = $filterType === $tab ? ' class="uk-active"' : '';
			$url    = $pageUrl . '?type=' . $tab;
			$tabHtml .= "<li{$active}><a href=\"{$url}\">{$def['label']} <span class=\"uk-badge\">{$cnt}</span>{$def['estimate']}</a></li>";
		}
		$tabHtml .= '</ul>';
		$html .= $tabHtml;

		// ── Sort helper ────────────────────────────────────────────────────────
		$sortIcon = fn($col) => $sortBy === $col
			? '<span uk-icon="icon: ' . ($sortDir === 'asc' ? 'arrow-up' : 'arrow-down') . '; ratio:.7"></span>'
			: '';
		$sortUrl = fn($col) => $pageUrl . '?type=' . $filterType . '&sort=' . $col . '&dir=' . ($sortBy === $col && $sortDir === 'asc' ? 'desc' : 'asc');

		// ── Backup table ───────────────────────────────────────────────────────
		if (empty($backups)) {
			$html .= '<p class="uk-text-muted"><span uk-icon="icon: info"></span> No backups found.</p>';
		} else {
			$html .= '
			<div class="uk-overflow-auto">
			<table class="uk-table uk-table-small uk-table-divider uk-table-hover uk-table-striped">
				<thead><tr>
					<th><a href="' . $sortUrl('filename') . '">Filename ' . $sortIcon('filename') . '</a></th>
					<th>Label</th>
					<th>Type</th>
					<th><a href="' . $sortUrl('date') . '">Date ' . $sortIcon('date') . '</a></th>
					<th><a href="' . $sortUrl('size') . '">Size ' . $sortIcon('size') . '</a></th>
					<th>Storage</th>
					<th class="uk-text-right">Actions</th>
				</tr></thead>
				<tbody>';

			foreach ($backups as $b) {
				$filename = htmlspecialchars($b['filename']);
				$date     = htmlspecialchars($b['date']);
				$size     = htmlspecialchars($b['size']);
				$label    = htmlspecialchars($b['label'] ?? '');
				$btype    = $b['type'] ?? 'regular';
				$hasLocal = !empty($b['local']);
				$hasB2    = !empty($b['b2']);

				// Type badge
				$typeBadge = match($btype) {
					'weekly'   => '<span class="uk-label uk-label-success">weekly</span>',
					'monthly'  => '<span class="uk-label" style="background:#8e44ad">monthly</span>',
					'uploaded' => '<span class="uk-label uk-label-warning">uploaded</span>',
					default    => '<span class="uk-label">regular</span>',
				};

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

				$verifyBtn = $hasLocal
					? "<a href=\"{$pageUrl}?action=verify&file={$filename}\" class=\"uk-icon-button\" uk-icon=\"check\" uk-tooltip=\"Verify integrity\"></a>"
					: '';

				$partialBtn = $hasLocal
					? "<a href=\"{$pageUrl}?action=partial&file={$filename}\" class=\"uk-icon-button\" uk-icon=\"table\" uk-tooltip=\"Partial restore\"></a>"
					: '';

				if ($hasLocal) {
					$restoreBtn = "<form method=\"post\" action=\"{$pageUrl}\" class=\"uk-display-inline\">"
						. $csrf
						. '<input type="hidden" name="action" value="restore">'
						. "<input type=\"hidden\" name=\"file\" value=\"{$filename}\">"
						. "<button type=\"submit\" class=\"uk-icon-button\" uk-icon=\"refresh\" uk-tooltip=\"Restore\""
						. " onclick=\"return confirm('" . addslashes($confirmRestore) . "')\" ></button>"
						. '</form>';
				} else {
					$restoreBtn = '<span class="uk-icon-button" uk-icon="refresh" uk-tooltip="Download from B2 to restore" style="opacity:.3;cursor:default"></span>';
				}

				$deleteBtn = "<form method=\"post\" action=\"{$pageUrl}\" class=\"uk-display-inline\">"
					. $csrf
					. '<input type="hidden" name="action" value="delete">'
					. "<input type=\"hidden\" name=\"file\" value=\"{$filename}\">"
					. "<button type=\"submit\" class=\"uk-icon-button\" uk-icon=\"trash\" uk-tooltip=\"Delete\""
					. " onclick=\"return confirm('" . addslashes($confirmDelete) . "')\" ></button>"
					. '</form>';

				// Inline label editor
				$labelCell = "<span class=\"pdb-label\" data-file=\"{$filename}\" uk-tooltip=\"Click to edit\""
					. " style=\"cursor:pointer;color:" . ($label ? 'inherit' : '#aaa') . "\">"
					. ($label ?: '<em>add label</em>') . "</span>";

				$html .= "<tr>"
					. "<td class=\"uk-text-small uk-text-nowrap\"><span uk-icon=\"icon: file-text; ratio:.8\" class=\"uk-margin-small-right\"></span>{$filename}</td>"
					. "<td class=\"uk-text-small\">{$labelCell}</td>"
					. "<td>{$typeBadge}</td>"
					. "<td class=\"uk-text-small uk-text-muted uk-text-nowrap\">{$date}</td>"
					. "<td class=\"uk-text-small uk-text-nowrap\">{$size}</td>"
					. "<td>{$storageBadge}</td>"
					. "<td class=\"uk-text-right uk-text-nowrap\">{$dlBtn} {$verifyBtn} {$partialBtn} {$restoreBtn} {$deleteBtn}</td>"
					. "</tr>";
			}

			$html .= "</tbody></table></div>";
		}

		// ── Database table sizes ───────────────────────────────────────────────
		$tableSizes     = $this->getTableSizeList();
		$excludedTables = array_flip($this->getExcludedTables());
		$totalDbBytes   = array_sum(array_map(fn($t) => (int)($t['total_length'] ?? 0), $tableSizes));
		$totalDbSize    = $this->formatBytes((int)$totalDbBytes);

		$html .= '
		<hr class="uk-margin-medium">
		<h3 class="uk-heading-divider uk-text-small uk-text-uppercase uk-text-muted">Database Table Sizes</h3>';

		if (empty($tableSizes)) {
			$html .= '<p class="uk-text-small uk-text-muted">Table size information is not available for this database user.</p>';
		} else {
			$html .= '
			<div class="uk-flex uk-flex-between uk-flex-middle uk-margin-small-bottom">
				<p class="uk-text-small uk-text-muted uk-margin-remove">All ' . count($tableSizes) . ' tables by storage size. Estimated total: <strong>' . $totalDbSize . '</strong>.</p>
				<a href="' . $this->wire('config')->urls->admin . 'module/edit?name=' . $this->className() . '#Inputfield_exclude_tables" class="uk-button uk-button-default uk-button-small">
					<span uk-icon="icon: settings; ratio:.7"></span>&nbsp; Exclude tables
				</a>
			</div>
			<div class="uk-overflow-auto">
			<table class="uk-table uk-table-small uk-table-divider uk-table-hover uk-table-striped">
				<thead><tr>
					<th>Table</th>
					<th class="uk-text-right">Rows</th>
					<th class="uk-text-right">Data</th>
					<th class="uk-text-right">Index</th>
					<th class="uk-text-right">Total</th>
					<th class="uk-text-right">Backup</th>
				</tr></thead>
				<tbody>';

			foreach ($tableSizes as $table) {
				$name       = (string)($table['table_name'] ?? '');
				$isExcluded = isset($excludedTables[$name]);
				$rowClass   = $isExcluded ? ' class="uk-text-muted"' : '';
				$status     = $isExcluded
					? '<span class="uk-label">Excluded</span>'
					: '<span class="uk-label uk-label-success">Included</span>';

				$html .= '<tr' . $rowClass . '>'
					. '<td class="uk-text-small"><code>' . htmlspecialchars($name) . '</code></td>'
					. '<td class="uk-text-small uk-text-right uk-text-nowrap">' . number_format((int)($table['table_rows'] ?? 0)) . '</td>'
					. '<td class="uk-text-small uk-text-right uk-text-nowrap">' . $this->formatBytes((int)($table['data_length'] ?? 0)) . '</td>'
					. '<td class="uk-text-small uk-text-right uk-text-nowrap">' . $this->formatBytes((int)($table['index_length'] ?? 0)) . '</td>'
					. '<td class="uk-text-small uk-text-right uk-text-nowrap"><strong>' . $this->formatBytes((int)($table['total_length'] ?? 0)) . '</strong></td>'
					. '<td class="uk-text-right">' . $status . '</td>'
					. '</tr>';
			}

			$html .= '</tbody></table></div>';
		}

		// ── Chunked upload form ────────────────────────────────────────────────
		$html .= '
		<hr class="uk-margin-medium">
		<h3 class="uk-heading-divider uk-text-small uk-text-uppercase uk-text-muted">Upload Backup File</h3>
		<p class="uk-text-small uk-text-muted">Upload a <code>.sql.gz</code> file from your computer. Large files are sent in chunks — no <code>upload_max_filesize</code> limit applies.</p>
		<div class="uk-grid-small uk-flex-middle" uk-grid>
			<div class="uk-width-expand">
				<div uk-form-custom="target: true">
					<input type="file" id="pdb-upload-file" accept=".gz">
					<input class="uk-input" type="text" placeholder="Select .sql.gz file..." readonly>
				</div>
			</div>
			<div>
				<label class="uk-text-small">
					<input type="checkbox" id="pdb-restore-after" class="uk-checkbox">
					&nbsp;Restore immediately after upload
				</label>
			</div>
			<div>
				<button class="uk-button uk-button-default" onclick="pdbChunkedUpload(this)">
					<span uk-icon="icon: upload; ratio:.8"></span>&nbsp; Upload
				</button>
			</div>
		</div>
			<div id="pdb-upload-progress" class="uk-hidden uk-margin-small-top">
				<progress class="uk-progress" id="pdb-upload-bar" value="0" max="100" style="margin:0 0 4px"></progress>
				<span id="pdb-upload-msg" class="uk-text-small uk-text-muted"></span>
			</div>
			<div class="uk-margin-large-top uk-text-right">
				<a href="' . $this->wire('config')->urls->admin . 'module/edit?name=' . $this->className() . '" class="uk-button uk-button-default uk-button-small">
					<span uk-icon="icon: settings; ratio:.7"></span>&nbsp; Module settings
				</a>
			</div>';

		$html .= $this->renderScripts($pageUrl);

		return $html;
	}
	// ── Create backup ─────────────────────────────────────────────────────────

	public function createBackup(string $type = 'regular'): array {
		$dir = $this->wire('config')->paths->assets . self::BACKUP_DIR;
		if (!is_dir($dir) && !wireMkdir($dir, true)) {
			return ['success' => false, 'error' => 'Cannot create backup directory.'];
		}

		// Lock file — prevent concurrent backups
		$lockFile = $this->wire('config')->paths->assets . dirname(self::LOCK_FILE) . '/db-' . $type . '.lock';
		if (file_exists($lockFile)) {
			$lockAge = time() - (int)file_get_contents($lockFile);
			if ($lockAge < 3600) {
				return ['success' => false, 'error' => 'Another backup is already running (lock age: ' . $lockAge . 's).'];
			}
			// Stale lock — remove it
			unlink($lockFile);
		}
		file_put_contents($lockFile, time());

		$typePrefix = match($type) {
			'weekly'  => 'db-weekly-',
			'monthly' => 'db-monthly-',
			default   => 'db-',
		};
		$filename = $typePrefix . date('Y-m-d_His') . self::BACKUP_EXT;
		$filepath = $dir . $filename;

		// Try mysqldump first, fall back to PHP PDO
		$result = $this->dumpViaMysqldump($filepath);
		if (!$result['success']) {
			$result = $this->dumpViaPdo($filepath);
		}

		if (!$result['success']) {
			// Release lock before returning failure
			if (file_exists($lockFile)) unlink($lockFile);
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
			'local'       => !$this->b2_enabled || !$b2Uploaded || (bool)$this->b2_keep_local,
			'method'      => $result['method'],
			'type'        => $type,
			'label'       => match($type) {
				'weekly'  => 'Week ' . date('W') . ', ' . date('Y'),
				'monthly' => date('F Y'),
				default   => '',
			},
		]);

		// Release lock BEFORE retention (retention may call B2 delete = slow network)
		if (file_exists($lockFile)) unlink($lockFile);

		// Enforce retention per type
		$this->enforceRetention($type);

		$this->log()->save(self::LOG_NAME, "Backup created: {$filename} (type: {$type}, method: {$result['method']}, b2: " . ($b2Uploaded ? 'yes' : 'no') . ')');

		return ['success' => true, 'filename' => $filename];
	}

	// ── mysqldump ─────────────────────────────────────────────────────────────

	protected function dumpViaMysqldump(string $filepath): array {
		$binary = $this->findCliBinary('mysqldump');
		if (!$binary) return ['success' => false, 'error' => 'mysqldump not found'];

		$cfg  = $this->wire('config');
		$host = escapeshellarg($cfg->dbHost ?: 'localhost');
		$port = $cfg->dbPort ? '-P ' . (int)$cfg->dbPort : '';
		$user = escapeshellarg($cfg->dbUser);
		// Write credentials to temp file — avoids password visible in 'ps aux'
		$cnfFile = tempnam(sys_get_temp_dir(), 'pwdb_');
		file_put_contents($cnfFile, '[client]' . PHP_EOL . 'password=' . $cfg->dbPass . PHP_EOL, LOCK_EX);
		chmod($cnfFile, 0600);
		$passCnf = $cfg->dbPass ? '--defaults-extra-file=' . escapeshellarg($cnfFile) : '';
		$pass    = ''; // password now in cnf file
		$name    = escapeshellarg($cfg->dbName);
		$out     = escapeshellarg($filepath);

		// Build --ignore-table args for excluded tables
		$excludeArgs = '';
		if ($this->exclude_tables) {
			foreach (array_filter(array_map('trim', explode("\n", $this->exclude_tables))) as $tbl) {
				$tbl = preg_replace('/[^a-zA-Z0-9_]/', '', $tbl);
				if ($tbl) $excludeArgs .= ' --ignore-table=' . escapeshellarg($cfg->dbName . '.' . $tbl);
			}
		}

		$gzip = $this->findCliBinary('gzip');
		if (!$gzip) {
			if (isset($cnfFile) && file_exists($cnfFile)) unlink($cnfFile);
			return ['success' => false, 'error' => 'gzip not found'];
		}

		$cmd = escapeshellarg($binary) . " {$passCnf} -h {$host} {$port} -u {$user} "
			 . "--single-transaction --quick --lock-tables=false "
			 . "--add-drop-table --routines --triggers{$excludeArgs} {$name} | "
			 . escapeshellarg($gzip) . " -c > {$out}";

		$run = $this->runShellCommand($cmd);
		if (isset($cnfFile) && file_exists($cnfFile)) unlink($cnfFile);

		if ($run['exitCode'] !== 0 || !file_exists($filepath) || filesize($filepath) < 100) {
			if (file_exists($filepath)) unlink($filepath);
			return ['success' => false, 'error' => 'mysqldump error: ' . $run['output']];
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

			// Get tables, minus excluded ones
			$allTables = $pdo->query("SHOW TABLES")->fetchAll(\PDO::FETCH_COLUMN);
			$excluded  = [];
			if ($this->exclude_tables) {
				$excluded = array_filter(array_map('trim', explode("\n", $this->exclude_tables)));
			}
			$tables = array_values(array_diff($allTables, $excluded));

			foreach ($tables as $table) {
				$escapedTable = '`' . str_replace('`', '``', $table) . '`';

				// DROP + CREATE
				gzwrite($fh, "DROP TABLE IF EXISTS {$escapedTable};\n");
				$createRow = $pdo->query("SHOW CREATE TABLE {$escapedTable}")->fetch(\PDO::FETCH_NUM);
				gzwrite($fh, $createRow[1] . ";\n\n");

				// Data — unbuffered row-by-row to avoid loading entire table into memory
				if (defined('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY')) {
					$pdo->setAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
				}
				$stmt = $pdo->query("SELECT * FROM {$escapedTable}");
				if (defined('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY')) {
					$pdo->setAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
				}
				$firstRow  = true;
				$batchVals = [];
				$batchSize = 100;
				$columns   = null;
				while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
					if ($columns === null) {
						$columns = '`' . implode('`, `', array_keys($row)) . '`';
					}
					$batchVals[] = '(' . implode(', ', array_map(
						fn($v) => is_null($v) ? 'NULL' : $pdo->quote($v),
						array_values($row)
					)) . ')';
					if (count($batchVals) >= $batchSize) {
						if ($firstRow) { gzwrite($fh, "INSERT INTO {$escapedTable} ({$columns}) VALUES\n"); $firstRow = false; }
						gzwrite($fh, implode(",\n", $batchVals) . ";\n");
						$batchVals = [];
					}
				}
				if ($batchVals && $columns) {
					if ($firstRow) gzwrite($fh, "INSERT INTO {$escapedTable} ({$columns}) VALUES\n");
					gzwrite($fh, implode(",\n", $batchVals) . ";\n\n");
				} elseif (!$firstRow) {
					gzwrite($fh, "\n");
				}
			}

			gzwrite($fh, "SET FOREIGN_KEY_CHECKS=1;\n");
			gzclose($fh);

			return ['success' => true, 'method' => 'pdo'];

		} catch (\Exception $e) {
			// Re-enable buffered queries if exception during unbuffered fetch
			try {
				if (defined('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY') && isset($pdo)) {
					$pdo->setAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
				}
				if (isset($stmt)) $stmt->closeCursor();
			} catch (\Throwable $ignored) {}
			if (isset($fh) && $fh) gzclose($fh);
			return ['success' => false, 'error' => 'PDO dump error: ' . $e->getMessage()];
		}
	}

	// ── Restore ───────────────────────────────────────────────────────────────

	public function restoreBackup(string $filename): array {
		$filepath = $this->wire('config')->paths->assets . self::BACKUP_DIR . $filename;

		if (!file_exists($filepath)) {
			return ['success' => false, 'error' => 'Backup file not found.'];
		}

		if (!preg_match('/^db-[a-z\-]*[\d_\-]+\.sql\.gz$/', $filename)) {
			return ['success' => false, 'error' => 'Invalid filename.'];
		}

		// Verify integrity before touching the database
		$verify = $this->verifyBackup($filepath);
		if (!$verify['valid']) {
			return ['success' => false, 'error' => 'Backup verification failed: ' . $verify['error']];
		}

		// Pre-restore backup if configured
		$preBackup = null;
		if ($this->pre_restore_backup) {
			$preResult = $this->createBackup('regular');
			if ($preResult['success']) {
				$preBackup = $preResult['filename'];
			} else {
				$this->log()->save(self::LOG_NAME, 'Pre-restore backup failed: ' . $preResult['error']);
			}
		}

		// Try mysql CLI first
		$result = $this->restoreViaMysql($filepath);
		if (!$result['success']) {
			$result = $this->restoreViaPdo($filepath);
		}

		if ($result['success']) {
			$result['pre_backup'] = $preBackup;
			$this->log()->save(self::LOG_NAME, "Database restored from: {$filename} (method: {$result['method']})");
		}

		return $result;
	}

	protected function restoreViaMysql(string $filepath): array {
		set_time_limit(0); // Restore may take long on large databases
		$binary = $this->findCliBinary('mysql');
		if (!$binary) return ['success' => false, 'error' => 'mysql CLI not found'];

		$cfg  = $this->wire('config');
		$host = escapeshellarg($cfg->dbHost ?: 'localhost');
		$port = $cfg->dbPort ? '-P ' . (int)$cfg->dbPort : '';
		$user = escapeshellarg($cfg->dbUser);
		// Write credentials to temp file — avoids password visible in 'ps aux'
		$cnfFile = tempnam(sys_get_temp_dir(), 'pwdb_');
		file_put_contents($cnfFile, '[client]' . PHP_EOL . 'password=' . $cfg->dbPass . PHP_EOL, LOCK_EX);
		chmod($cnfFile, 0600);
		$passCnf = $cfg->dbPass ? '--defaults-extra-file=' . escapeshellarg($cnfFile) : '';
		$pass    = '';
		$name    = escapeshellarg($cfg->dbName);
		$src     = escapeshellarg($filepath);

		// Use gunzip -c as it's more portable than zcat (available on macOS/Linux/BSD)
		$gunzip = $this->findCliBinary('gunzip');
		$gzip   = $this->findCliBinary('gzip');
		if ($gunzip) {
			$decomp = escapeshellarg($gunzip) . " -c {$src}";
		} elseif ($gzip) {
			$decomp = escapeshellarg($gzip) . " -dc {$src}";
		} else {
			if (isset($cnfFile) && file_exists($cnfFile)) unlink($cnfFile);
			return ['success' => false, 'error' => 'gunzip/gzip not found'];
		}
		$cmd = "{$decomp} | " . escapeshellarg($binary) . " {$passCnf} -h {$host} {$port} -u {$user} {$name}";
		$run = $this->runShellCommand($cmd);
		if (isset($cnfFile) && file_exists($cnfFile)) unlink($cnfFile);

		if ($run['exitCode'] !== 0) {
			return ['success' => false, 'error' => 'mysql CLI error: ' . $run['output']];
		}

		return ['success' => true, 'method' => 'mysql-cli'];
	}

	protected function restoreViaPdo(string $filepath): array {
		set_time_limit(0); // Restore may take long on large databases
		try {
			$cfg = $this->wire('config');
			$dsn = "mysql:host={$cfg->dbHost};dbname={$cfg->dbName};charset={$cfg->dbCharset}";
			$pdo = new \PDO($dsn, $cfg->dbUser, $cfg->dbPass, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);

			$fh = gzopen($filepath, 'rb');
			if (!$fh) return ['success' => false, 'error' => 'Cannot open backup file.'];

			$pdo->exec('SET FOREIGN_KEY_CHECKS=0');

			// Stream line-by-line — avoids loading entire dump into memory
			$stmt    = '';
			while (!gzeof($fh)) {
				$line = gzgets($fh, 1048576); // 1MB max line (handles large INSERT chunks)
				if ($line === false) break;

				$trimmed = ltrim($line);
				// Skip empty lines and pure comments
				if ($trimmed === '' || str_starts_with($trimmed, '-- ') || $trimmed === "--\n") continue;

				$stmt .= $line;

				// Execute when we hit a statement terminator at end of line
				$rtrimmed = rtrim($line);
				if (str_ends_with($rtrimmed, ';')) {
					$execStmt = trim(rtrim($stmt, "\n\r"));
					if ($execStmt !== '' && !str_starts_with(ltrim($execStmt), '--')) {
						$pdo->exec($execStmt);
					}
					$stmt = '';
				}
			}
			// Execute any remaining statement
			if (($execStmt = trim($stmt)) !== '') $pdo->exec($execStmt);

			gzclose($fh);
			$pdo->exec('SET FOREIGN_KEY_CHECKS=1');

			return ['success' => true, 'method' => 'pdo-stream'];

		} catch (\Exception $e) {
			if (isset($fh) && $fh) gzclose($fh);
			try { if (isset($pdo)) $pdo->exec('SET FOREIGN_KEY_CHECKS=1'); } catch (\Throwable $ignored) {}
			return ['success' => false, 'error' => 'PDO restore error: ' . $e->getMessage()];
		}
	}

	protected function findCliBinary(string $name): string {
		if (!preg_match('/^[a-zA-Z0-9._-]+$/', $name)) return '';
		$binary = trim(shell_exec('command -v ' . escapeshellarg($name) . ' 2>/dev/null') ?? '');
		return $binary !== '' && is_executable($binary) ? $binary : '';
	}

	protected function runShellCommand(string $cmd): array {
		$wrapped = '/bin/bash -o pipefail -c ' . escapeshellarg($cmd) . ' 2>&1';
		$output = [];
		$exitCode = 0;
		exec($wrapped, $output, $exitCode);
		return [
			'exitCode' => $exitCode,
			'output'   => trim(implode("\n", $output)),
		];
	}

	// ── Verify backup integrity ─────────────────────────────────────────────────

	public function verifyBackup(string $filepath): array {
		if (!file_exists($filepath)) {
			return ['valid' => false, 'error' => 'File not found.'];
		}

		// Check gzip magic bytes
		$fh    = fopen($filepath, 'rb');
		$magic = fread($fh, 2);
		fclose($fh);
		if ($magic !== "\x1f\x8b") {
			return ['valid' => false, 'error' => 'Not a valid gzip file.'];
		}

		// Test gzip integrity (zcat to /dev/null)
		$src      = escapeshellarg($filepath);
		$gzipBin  = trim(shell_exec('which gzip 2>/dev/null') ?? '');
		if ($gzipBin) {
			exec("{$gzipBin} -t {$src} 2>&1", $out, $code);
			if ($code !== 0) {
				return ['valid' => false, 'error' => 'gzip integrity check failed: ' . implode(' ', $out)];
			}
		}

		// Read first chunk of SQL and check for expected markers
		$fh  = gzopen($filepath, 'rb');
		$sql = gzread($fh, 8192);
		gzclose($fh);

		if (empty($sql)) {
			return ['valid' => false, 'error' => 'Backup file is empty.'];
		}

		// Expect at least one CREATE TABLE or INSERT or DROP TABLE statement
		if (!preg_match('/(CREATE TABLE|INSERT INTO|DROP TABLE)/i', $sql)) {
			return ['valid' => false, 'error' => 'File does not appear to contain valid SQL.'];
		}

		return ['valid' => true];
	}

	// ── List tables inside a backup ───────────────────────────────────────────

	public function getBackupTables(string $filename): array {
		$filepath = $this->wire('config')->paths->assets . self::BACKUP_DIR . $filename;
		if (!file_exists($filepath)) return [];

		$tables = [];
		$fh     = gzopen($filepath, 'rb');
		if (!$fh) return [];

		// Read in chunks looking for CREATE TABLE statements
		$buffer = '';
		while (!gzeof($fh)) {
			$buffer .= gzread($fh, 65536);
			preg_match_all('/CREATE TABLE `([^`]+)`/i', $buffer, $matches);
			foreach ($matches[1] as $tbl) {
				$tables[$tbl] = true;
			}
			// Keep last 8KB to catch CREATE TABLE statements spanning chunk boundaries
			$buffer = substr($buffer, -8192);
		}
		gzclose($fh);

		$result = array_keys($tables);
		sort($result);
		return $result;
	}

	// ── Partial restore ───────────────────────────────────────────────────────

	public function partialRestoreBackup(string $filename, array $tables): array {
		$filepath = $this->wire('config')->paths->assets . self::BACKUP_DIR . $filename;

		if (!file_exists($filepath)) {
			return ['success' => false, 'error' => 'Backup file not found.'];
		}

		// Verify integrity first
		$verify = $this->verifyBackup($filepath);
		if (!$verify['valid']) {
			return ['success' => false, 'error' => 'Verification failed: ' . $verify['error']];
		}

		// Pre-restore backup if configured
		$preBackup = null;
		if ($this->pre_restore_backup) {
			$preResult = $this->createBackup('regular');
			if ($preResult['success']) $preBackup = $preResult['filename'];
		}

		// Read entire SQL from gzip — note: loads full dump into memory
		// Acceptable for partial restore (needs full parse); ensure memory_limit is adequate
		$fh  = gzopen($filepath, 'rb');
		if (!$fh) return ['success' => false, 'error' => 'Cannot open backup file.'];
		$sql = '';
		while (!gzeof($fh)) $sql .= gzread($fh, 65536);
		gzclose($fh);
		if (empty($sql)) return ['success' => false, 'error' => 'Backup file is empty.'];

		// Parse SQL into per-table blocks
		// Each block: DROP TABLE + CREATE TABLE + INSERT statements for that table
		$tableBlocks = [];
		$currentTable = null;
		$currentBlock = '';

		$statements = preg_split('/;[ \t]*\n/', $sql);
		foreach ($statements as $stmt) {
			$stmt = trim($stmt);
			if (empty($stmt) || str_starts_with($stmt, '--')) continue;

			if (preg_match('/^DROP TABLE.*`([^`]+)`/i', $stmt, $m)) {
				$currentTable = $m[1];
				$currentBlock = $stmt . ";\n";
			} elseif (preg_match('/^CREATE TABLE `([^`]+)`/i', $stmt, $m)) {
				$currentTable = $m[1];
				$currentBlock .= $stmt . ";\n";
				// Save immediately — table may be empty (no INSERT follows)
				$tableBlocks[$currentTable] = $currentBlock;
			} elseif (preg_match('/^INSERT INTO `([^`]+)`/i', $stmt, $m)) {
				if ($m[1] === $currentTable) {
					$currentBlock .= $stmt . ";\n";
				}
				if ($currentTable && !isset($tableBlocks[$currentTable])) {
					$tableBlocks[$currentTable] = '';
				}
				$tableBlocks[$currentTable] = $currentBlock;
			} elseif ($currentTable) {
				$currentBlock .= $stmt . ";\n";
				$tableBlocks[$currentTable] = $currentBlock;
			}
		}

		// Execute only requested tables
		try {
			$cfg = $this->wire('config');
			$dsn = "mysql:host={$cfg->dbHost};dbname={$cfg->dbName};charset={$cfg->dbCharset}";
			$pdo = new \PDO($dsn, $cfg->dbUser, $cfg->dbPass, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
			$pdo->exec('SET FOREIGN_KEY_CHECKS=0');

			$restored = [];
			foreach ($tables as $tbl) {
				if (!isset($tableBlocks[$tbl])) continue;
				$stmts = preg_split('/;[ \t]*\n/', $tableBlocks[$tbl]);
				foreach ($stmts as $s) {
					$s = trim($s);
					if ($s && !str_starts_with($s, '--')) $pdo->exec($s);
				}
				$restored[] = $tbl;
			}

			$pdo->exec('SET FOREIGN_KEY_CHECKS=1');

			$this->log()->save(self::LOG_NAME,
				"Partial restore from {$filename}: " . implode(', ', $restored)
			);

			return ['success' => true, 'restored_tables' => $restored, 'pre_backup' => $preBackup];

		} catch (\Exception $e) {
			try { if (isset($pdo)) $pdo->exec('SET FOREIGN_KEY_CHECKS=1'); } catch (\Throwable $ignored) {}
			return ['success' => false, 'error' => 'PDO error: ' . $e->getMessage()];
		}
	}

	// ── Delete backup ─────────────────────────────────────────────────────────

	public function deleteBackup(string $filename): array {
		if (!preg_match('/^db-[a-z\-]*[\d_\-]+\.sql\.gz$/', $filename)) {
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
			// Note: deleteFromB2 logs errors internally but we proceed with meta removal.
			// B2 orphans are preferable to UI entries with no local file and no B2 tracking.
		}

		$this->removeMeta($filename);

		return ['success' => true];
	}

	// ── AJAX: create backup with JSON response ──────────────────────────────────

	protected function ajaxJson(array $data): void {
		// Kill all output buffers (Tracy, PW debug, etc.) before sending JSON
		while (ob_get_level() > 0) ob_end_clean();
		header('Content-Type: application/json');
		header('X-Content-Type-Options: nosniff');
		echo json_encode($data);
		exit;
	}

	protected function handleAjaxCreate(): void {
		if (!$this->wire('user')->isLoggedin() || !$this->wire('user')->hasPermission('db-backup')) {
			$this->ajaxJson(['success' => false, 'error' => 'Unauthorized.']);
		}
		// Validate CSRF token sent as GET param in AJAX request
		$csrfName  = $this->wire('session')->CSRF->getTokenName();
		$csrfValue = $this->wire('session')->CSRF->getTokenValue();
		$sentToken = $this->input->get($csrfName) ?? '';
		if (!$sentToken || $sentToken !== $csrfValue) {
			$this->ajaxJson(['success' => false, 'error' => 'CSRF token invalid.']);
		}

		$type   = $this->input->get('type') ?? 'regular';
		$type   = in_array($type, ['regular', 'weekly', 'monthly']) ? $type : 'regular';
		$result = $this->createBackup($type);
		$this->ajaxJson($result);
	}

	protected function handleAjaxLabel(): void {
		if (!$this->wire('user')->isLoggedin() || !$this->wire('user')->hasPermission('db-backup')) {
			$this->ajaxJson(['success' => false, 'error' => 'Unauthorized.']);
		}
		// Validate CSRF token sent as GET param in AJAX request
		$csrfName  = $this->wire('session')->CSRF->getTokenName();
		$csrfValue = $this->wire('session')->CSRF->getTokenValue();
		$sentToken = $this->input->get($csrfName) ?? '';
		if (!$sentToken || $sentToken !== $csrfValue) {
			$this->ajaxJson(['success' => false, 'error' => 'CSRF token invalid.']);
		}

		$file  = basename((string)($this->input->get('file') ?? ''));
		$label = $this->sanitizer->text($this->input->get('label') ?? '');
		$meta  = $this->getMeta();
		$ok    = false;
		if (isset($meta[$file])) {
			$meta[$file]['label'] = $label;
			$metaPath = $this->wire('config')->paths->assets . self::META_FILE;
			file_put_contents($metaPath, json_encode($meta, JSON_PRETTY_PRINT), LOCK_EX);
			$ok = true;
		}
		$this->ajaxJson(['success' => $ok]);
	}

	// ── Chunked upload ────────────────────────────────────────────────────────

	protected function handleChunkUpload(): void {
		// Auth check — must be logged-in admin with db-backup permission
		if (!$this->wire('user')->isLoggedin() || !$this->wire('user')->hasPermission('db-backup')) {
			$this->ajaxJson(['success' => false, 'error' => 'Unauthorized.']);
		}
		// Validate CSRF token from POST FormData
		$csrfName  = $this->wire('session')->CSRF->getTokenName();
		$csrfValue = $this->wire('session')->CSRF->getTokenValue();
		$sentToken = $this->input->post($csrfName) ?? ($_POST[$csrfName] ?? '');
		if (!$sentToken || $sentToken !== $csrfValue) {
			$this->ajaxJson(['success' => false, 'error' => 'CSRF token invalid.']);
		}

		$chunkDir = $this->wire('config')->paths->assets . self::CHUNK_DIR;
		if (!is_dir($chunkDir)) wireMkdir($chunkDir, true);

		$uploadId  = preg_replace('/[^a-f0-9]/', '', $this->input->post('upload_id') ?? '');
		$chunkIdx  = max(0, min((int)($this->input->post('chunk_index') ?? 0), 9999));
		$totalChunks = min((int)($this->input->post('total_chunks') ?? 1), 10000); // max 10k chunks = ~20GB
		$origName  = basename($this->input->post('filename') ?? '');
		$restoreAfter = (bool)($this->input->post('restore_after_upload') ?? false);

		if (!$uploadId || !isset($_FILES['chunk'])) {
			$this->ajaxJson(['success' => false, 'error' => 'Missing chunk data.']);
		}

		$chunkFile = $chunkDir . $uploadId . '_' . str_pad($chunkIdx, 5, '0', STR_PAD_LEFT);
		if (!move_uploaded_file($_FILES['chunk']['tmp_name'], $chunkFile)) {
			$this->ajaxJson(['success' => false, 'error' => 'Failed to save chunk.']);
		}

		// Check if all chunks arrived
		$arrived = count(glob($chunkDir . $uploadId . '_*') ?: []);
		if ($arrived < $totalChunks) {
			$this->ajaxJson(['success' => true, 'status' => 'chunk_received', 'received' => $arrived, 'total' => $totalChunks]);
		}

		// All chunks here — assemble
		$filename = preg_match('/^db-[a-z\-]*[\d_\-]+\.sql\.gz$/', $origName)
			? $origName
			: 'db-uploaded-' . date('Y-m-d_His') . self::BACKUP_EXT;

		$backupDir = $this->wire('config')->paths->assets . self::BACKUP_DIR;
		if (!is_dir($backupDir)) wireMkdir($backupDir, true);
		$finalPath = $backupDir . $filename;

		// If file already exists, add timestamp suffix to avoid silent overwrite
		if (file_exists($finalPath)) {
			$filename  = 'db-uploaded-' . date('Y-m-d_His') . self::BACKUP_EXT;
			$finalPath = $backupDir . $filename;
		}
		$out = fopen($finalPath, 'wb');
		if (!$out) {
			$this->ajaxJson(['success' => false, 'error' => 'Cannot create output file. Check permissions.']);
		}
		for ($i = 0; $i < $totalChunks; $i++) {
			$cf = $chunkDir . $uploadId . '_' . str_pad($i, 5, '0', STR_PAD_LEFT);
			if (!file_exists($cf)) {
				fclose($out);
				unlink($finalPath);
				$this->ajaxJson(['success' => false, 'error' => "Missing chunk {$i}."]); 
			}
			$in = fopen($cf, 'rb');
			while (!feof($in)) fwrite($out, fread($in, 65536));
			fclose($in);
			unlink($cf);
		}
		fclose($out);

		// Validate gzip
		$fh    = fopen($finalPath, 'rb');
		$magic = fread($fh, 2);
		fclose($fh);
		if ($magic !== "\x1f\x8b") {
			unlink($finalPath);
			$this->ajaxJson(['success' => false, 'error' => 'Assembled file is not a valid gzip archive.']);
		}

		$this->saveMeta($filename, [
			'filename' => $filename,
			'date'     => date('Y-m-d H:i:s'),
			'size'     => filesize($finalPath),
			'b2'       => false,
			'local'    => true,
			'type'     => 'uploaded',
			'label'    => '',
			'method'   => 'chunked-upload',
		]);

		$this->log()->save(self::LOG_NAME, "Chunked upload complete: {$filename}");

		$restored      = false;
		$restoreError  = null;
		if ($restoreAfter) {
			$restoreResult = $this->restoreBackup($filename);
			$restored      = $restoreResult['success'];
			if (!$restored) $restoreError = $restoreResult['error'] ?? 'Restore failed.';
		}

		$this->ajaxJson(['success' => true, 'status' => 'complete', 'filename' => $filename,
			'restored' => $restored, 'restore_error' => $restoreError]);
	}

	// ── Upload backup from local computer ───────────────────────────────────────

	protected function uploadBackup(): array {
		$upload = $_FILES['backup_file'] ?? null;

		if (!$upload || $upload['error'] !== UPLOAD_ERR_OK) {
			$codes = [
				UPLOAD_ERR_INI_SIZE   => 'File exceeds upload_max_filesize.',
				UPLOAD_ERR_FORM_SIZE  => 'File exceeds MAX_FILE_SIZE.',
				UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded.',
				UPLOAD_ERR_NO_FILE    => 'No file was uploaded.',
				UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder.',
				UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
			];
			$code = $upload['error'] ?? UPLOAD_ERR_NO_FILE;
			return ['success' => false, 'error' => $codes[$code] ?? 'Upload error code ' . $code];
		}

		$origName = basename($upload['name']);

		if (!str_ends_with($origName, '.sql.gz')) {
			return ['success' => false, 'error' => 'Only .sql.gz files are accepted.'];
		}

		// Validate gzip magic bytes
		$fh    = fopen($upload['tmp_name'], 'rb');
		$magic = fread($fh, 2);
		fclose($fh);
		if ($magic !== "\x1f\x8b") {
			return ['success' => false, 'error' => 'File does not appear to be a valid gzip archive.'];
		}

		// Keep original filename if it matches naming convention, otherwise generate one
		$filename = preg_match('/^db-[a-z\-]*[\d_\-]+\.sql\.gz$/', $origName)
			? $origName
			: 'db-uploaded-' . date('Y-m-d_His') . '.sql.gz';

		$dir = $this->wire('config')->paths->assets . self::BACKUP_DIR;
		if (!is_dir($dir) && !wireMkdir($dir, true)) {
			return ['success' => false, 'error' => 'Cannot create backup directory.'];
		}

		$filepath = $dir . $filename;

		if (!move_uploaded_file($upload['tmp_name'], $filepath)) {
			return ['success' => false, 'error' => 'Failed to move uploaded file.'];
		}

		$this->saveMeta($filename, [
			'filename' => $filename,
			'date'     => date('Y-m-d H:i:s'),
			'size'     => filesize($filepath),
			'b2'       => false,
			'local'    => true,
			'type'     => 'uploaded',
			'label'    => '',
			'method'   => 'uploaded',
		]);

		$this->log()->save(self::LOG_NAME, "Backup uploaded: {$filename}");

		$restored = false;
		if ($this->input->post('restore_after_upload')) {
			$restoreResult = $this->restoreBackup($filename);
			if (!$restoreResult['success']) {
				return ['success' => false, 'error' => 'File saved but restore failed: ' . $restoreResult['error']];
			}
			$restored = true;
		}

		return ['success' => true, 'filename' => $filename, 'restored' => $restored];
	}

	// ── Download ──────────────────────────────────────────────────────────────

	protected function doDownload(string $filename): void {
		$filename = basename((string)$filename);

		if (!preg_match('/^db-[a-z\-]*[\d_\-]+\.sql\.gz$/', $filename)) {
			$this->error('Invalid filename.');
			return;
		}

		$filepath = $this->wire('config')->paths->assets . self::BACKUP_DIR . $filename;

		if (!file_exists($filepath)) {
			$this->error('File not found.');
			return;
		}

		// Discard any prior output so headers can be sent cleanly
		if (ob_get_level()) ob_end_clean();

		header('Content-Type: application/gzip');
		header('Content-Disposition: attachment; filename="' . $filename . '"');
		header('Content-Length: ' . filesize($filepath));
		header('Cache-Control: no-cache, must-revalidate');
		header('Pragma: no-cache');
		readfile($filepath);
		exit;
	}

	// ── Retention ─────────────────────────────────────────────────────────────

	protected function enforceRetention(string $type = 'regular'): void {
		$max = match($type) {
			'weekly'  => (int)($this->retention_weekly  ?: 4),
			'monthly' => (int)($this->retention_monthly ?: 3),
			default   => (int)($this->retention_count   ?: 0),
		};
		if ($max <= 0) return;

		$backups = array_filter($this->getBackupList(), fn($b) => ($b['type'] ?? 'regular') === $type);
		$backups = array_values($backups); // newest first from getBackupList

		if (count($backups) > $max) {
			$toDelete = array_slice($backups, $max);
			foreach ($toDelete as $b) {
				$this->deleteBackup($b['filename']);
			}
		}
	}

	// ── Cron hook ─────────────────────────────────────────────────────────────

	public function cronBackup(HookEvent $event): void {
		$result = $this->createBackup('regular');
		if (!$result['success']) {
			$this->log()->save(self::LOG_NAME, 'Cron backup (regular) failed: ' . $result['error']);
			// Release stale lock if backup failed
			$lockFile = $this->wire('config')->paths->assets . dirname(self::LOCK_FILE) . '/db-regular.lock';
			if (file_exists($lockFile)) unlink($lockFile);
		}
	}

	public function cronBackupWeekly(HookEvent $event): void {
		$result = $this->createBackup('weekly');
		if (!$result['success']) {
			$this->log()->save(self::LOG_NAME, 'Cron backup (weekly) failed: ' . $result['error']);
			$lockFile = $this->wire('config')->paths->assets . dirname(self::LOCK_FILE) . '/db-weekly.lock';
			if (file_exists($lockFile)) unlink($lockFile);
		}
	}

	public function cronBackupMonthly(HookEvent $event): void {
		$result = $this->createBackup('monthly');
		if (!$result['success']) {
			$this->log()->save(self::LOG_NAME, 'Cron backup (monthly) failed: ' . $result['error']);
			$lockFile = $this->wire('config')->paths->assets . dirname(self::LOCK_FILE) . '/db-monthly.lock';
			if (file_exists($lockFile)) unlink($lockFile);
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
				'size_raw' => (int)($m['size'] ?? 0), // raw bytes for correct numeric sort
				'b2'       => !empty($m['b2']),
				'local'    => $localExists,
				'type'     => $m['type'] ?? 'regular',
				'label'    => $m['label'] ?? '',
			];
		}

		// Newest first
		usort($list, fn($a, $b) => strcmp($b['date'], $a['date']));

		return $list;
	}

	protected function getDiskUsage(): string {
		// Sum sizes from meta, excluding orphaned entries (no local file, no B2)
		$meta = $this->getMeta();
		if ($meta) {
			$dir   = $this->wire('config')->paths->assets . self::BACKUP_DIR;
			$total = 0;
			foreach ($meta as $filename => $m) {
				if (!empty($m['b2']) || file_exists($dir . $filename)) {
					$total += (int)($m['size'] ?? 0);
				}
			}
			return $this->formatBytes($total);
		}
		$dir   = $this->wire('config')->paths->assets . self::BACKUP_DIR;
		if (!is_dir($dir)) return '0 B';
		$files = glob($dir . 'db-*' . self::BACKUP_EXT) ?: [];
		$total = array_sum(array_map('filesize', $files));
		return $this->formatBytes((int)$total);
	}

	protected function getExcludedTables(): array {
		if (!$this->exclude_tables) return [];
		$tables = array_filter(array_map('trim', explode("\n", $this->exclude_tables)));
		return array_values(array_unique(array_filter(array_map(
			fn($table) => preg_replace('/[^a-zA-Z0-9_]/', '', $table),
			$tables
		))));
	}

	protected function getTableSizeList(): array {
		try {
			$cfg = $this->wire('config');
			$dsn = "mysql:host={$cfg->dbHost};dbname={$cfg->dbName};charset={$cfg->dbCharset}";
			$pdo = new \PDO($dsn, $cfg->dbUser, $cfg->dbPass, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
			$stmt = $pdo->prepare("
				SELECT
					TABLE_NAME AS table_name,
					COALESCE(TABLE_ROWS, 0) AS table_rows,
					COALESCE(DATA_LENGTH, 0) AS data_length,
					COALESCE(INDEX_LENGTH, 0) AS index_length,
					COALESCE(DATA_LENGTH, 0) + COALESCE(INDEX_LENGTH, 0) AS total_length
				FROM information_schema.TABLES
				WHERE TABLE_SCHEMA = :schema
				ORDER BY total_length DESC, TABLE_NAME ASC
			");
			$stmt->execute(['schema' => $cfg->dbName]);
			return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
		} catch (\Throwable $e) {
			$this->log()->save(self::LOG_NAME, 'Could not read table sizes: ' . $e->getMessage());
			return [];
		}
	}

	protected function formatBytes(int $bytes): string {
		if ($bytes >= 1073741824) return round($bytes / 1073741824, 2) . ' GB';
		if ($bytes >= 1048576)    return round($bytes / 1048576, 2) . ' MB';
		if ($bytes >= 1024)       return round($bytes / 1024, 2) . ' KB';
		return $bytes . ' B';
	}

	// ── Meta migration ───────────────────────────────────────────────────────────

	protected function migrateMeta(): void {
		// Skip if already migrated (tracked in module data to avoid reading file every admin load)
		if ($this->wire('modules')->getConfig($this, 'meta_migrated')) return;

		$path = $this->wire('config')->paths->assets . self::META_FILE;
		if (!file_exists($path)) {
			$this->wire('modules')->saveConfig($this, 'meta_migrated', true);
			return;
		}

		$json = json_decode(file_get_contents($path), true);
		if (!is_array($json)) return;

		$changed = false;
		foreach ($json as $filename => &$m) {
			// Infer type from filename prefix if missing
			if (!isset($m['type'])) {
				if (str_starts_with($filename, 'db-weekly-')) {
					$m['type'] = 'weekly';
				} elseif (str_starts_with($filename, 'db-monthly-')) {
					$m['type'] = 'monthly';
				} elseif (str_starts_with($filename, 'db-uploaded-')) {
					$m['type'] = 'uploaded';
				} else {
					$m['type'] = 'regular';
				}
				$changed = true;
			}
			if (!isset($m['label'])) {
				$m['label'] = '';
				$changed = true;
			}
			if (!isset($m['local'])) {
				$dir = $this->wire('config')->paths->assets . self::BACKUP_DIR;
				$m['local'] = file_exists($dir . $filename);
				$changed = true;
			}
		}
		unset($m);

		if ($changed) {
			file_put_contents($path, json_encode($json, JSON_PRETTY_PRINT), LOCK_EX);
			$this->wire('modules')->saveConfig($this, 'meta_migrated', true);
		} else {
			// All entries already have required fields — mark migration complete
			$this->wire('modules')->saveConfig($this, 'meta_migrated', true);
		}
	}

	// ── Meta store ────────────────────────────────────────────────────────────

	protected function getMeta(): array {
		$path = $this->wire('config')->paths->assets . self::META_FILE;
		if (!file_exists($path)) return [];
		// Use shared lock to prevent reading partial writes
		$fh = fopen($path, 'r');
		if (!$fh) return [];
		flock($fh, LOCK_SH);
		$json = json_decode(stream_get_contents($fh), true);
		flock($fh, LOCK_UN);
		fclose($fh);
		return is_array($json) ? $json : [];
	}

	protected function saveMeta(string $filename, array $data): void {
		$meta = $this->getMeta();
		$meta[$filename] = $data;
		$path = $this->wire('config')->paths->assets . self::META_FILE;
		file_put_contents($path, json_encode($meta, JSON_PRETTY_PRINT), LOCK_EX);
	}

	protected function removeMeta(string $filename): void {
		$meta = $this->getMeta();
		unset($meta[$filename]);
		$path = $this->wire('config')->paths->assets . self::META_FILE;
		file_put_contents($path, json_encode($meta, JSON_PRETTY_PRINT), LOCK_EX);
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
		$fileSize    = filesize($filepath);
		$prefix      = $this->b2_prefix ? rtrim($this->b2_prefix, '/') . '/' : '';

		// Stream file via CURLOPT_INFILE — avoids loading entire file into memory
		$sha1 = hash_file('sha1', $filepath);
		$fh   = fopen($filepath, 'rb');

		$ch = curl_init($uploadUrl);
		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_PUT            => true,
			CURLOPT_INFILE         => $fh,
			CURLOPT_INFILESIZE     => $fileSize,
			CURLOPT_HTTPHEADER     => [
				"Authorization: {$uploadToken}",
				"X-Bz-File-Name: " . urlencode($prefix . $filename),
				"Content-Type: application/gzip",
				// Content-Length set automatically by curl via CURLOPT_INFILESIZE
				"X-Bz-Content-Sha1: {$sha1}",
			],
		]);
		$result = json_decode(curl_exec($ch), true);
		$code   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);
		if (isset($fh) && is_resource($fh)) fclose($fh);

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
		$delResult = json_decode(curl_exec($ch), true);
		$delCode   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);
		if ($delCode !== 200) {
			$this->log()->save(self::LOG_NAME, 'B2 delete failed for ' . $b2Name . ': ' . ($delResult['message'] ?? 'HTTP ' . $delCode));
		}
	}

	// ── Dashboard widget ────────────────────────────────────────────────────────

	public function renderWidget(HookEvent $event): void {
		if (!$this->wire('user')->hasPermission('db-backup')) return;

		$allBackups = $this->getBackupList();
		$backupPage = $this->wire('pages')->get('template=admin, name=db-backup');
		if (!$backupPage->id) return; // page not found — abort widget
		$pageUrl    = $backupPage->url;
		$csrf       = $this->wire('session')->CSRF->renderInput();
		$diskUsed   = $this->getDiskUsage();
		$totalCount = count($allBackups);

		// Group by type
		$byType = ['regular' => [], 'weekly' => [], 'monthly' => []];
		foreach ($allBackups as $b) {
			$t = $b['type'] ?? 'regular';
			if (isset($byType[$t])) $byType[$t][] = $b;
		}

		// Per-type config
		$typeConfig = [
			'regular' => [
				'label'    => 'Regular',
				'icon'     => 'history',
				'schedule' => $this->cron_interval ?? 'never',
				'maxAge'   => 86400 * 2, // warn if older than 2 days
			],
			'weekly' => [
				'label'    => 'Weekly',
				'icon'     => 'calendar',
				'schedule' => $this->cron_weekly ?? 'never',
				'maxAge'   => 86400 * 7,
			],
			'monthly' => [
				'label'    => 'Monthly',
				'icon'     => 'star',
				'schedule' => $this->cron_monthly ?? 'never',
				'maxAge'   => 86400 * 28,
			],
		];

		$cronLabels = [
			'never' => 'Not scheduled', 'every30Seconds' => 'Every 30s',
			'everyMinute' => 'Every 1m', 'every2Minutes' => 'Every 2m',
			'every5Minutes' => 'Every 5m', 'every10Minutes' => 'Every 10m',
			'every15Minutes' => 'Every 15m', 'every30Minutes' => 'Every 30m',
			'everyHour' => 'Every 1h', 'every2Hours' => 'Every 2h',
			'every6Hours' => 'Every 6h', 'every12Hours' => 'Every 12h',
			'everyDay' => 'Daily', 'every2Days' => 'Every 2d',
			'everyWeek' => 'Weekly', 'every2Weeks' => 'Every 2w',
			'every4Weeks' => 'Every 4w',
		];

		// Build per-type rows
		$rows = '';
		foreach ($typeConfig as $type => $cfg) {
			$backupsOfType = $byType[$type];
			$latest        = $backupsOfType[0] ?? null;
			$count         = count($backupsOfType);
			$scheduleLabel = $cronLabels[$cfg['schedule']] ?? $cfg['schedule'];
			$isScheduled   = $cfg['schedule'] !== 'never';

			// Status
			if (!$latest) {
				$statusColor = 'danger';
				$statusText  = 'No backups';
				$dateHtml    = '<span class="uk-text-muted uk-text-small">—</span>';
			} elseif (strtotime($latest['date']) < time() - $cfg['maxAge']) {
				$statusColor = 'warning';
				$statusText  = 'Outdated';
				$dateHtml    = '<span class="uk-text-small">' . htmlspecialchars($latest['date']) . '</span>';
			} else {
				$statusColor = 'success';
				$statusText  = 'OK';
				$dateHtml    = '<span class="uk-text-small">' . htmlspecialchars($latest['date']) . '</span>';
			}

			// Storage badge for latest
			$storageBadge = '';
			if ($latest) {
				if (!empty($latest['b2']) && !empty($latest['local'])) {
					$storageBadge = '<span class="uk-label uk-label-success" style="font-size:10px">Local+B2</span>';
				} elseif (!empty($latest['b2'])) {
					$storageBadge = '<span class="uk-label uk-label-warning" style="font-size:10px">B2 only</span>';
				} else {
					$storageBadge = '<span class="uk-label" style="font-size:10px">Local</span>';
				}
			}

			// Manual create button
			$createForm = '<form method="post" action="' . $pageUrl . '" class="uk-display-inline">'
				. $csrf
				. '<input type="hidden" name="action" value="create_typed">'
				. '<input type="hidden" name="backup_type" value="' . $type . '">'
				. '<button type="submit" class="uk-button uk-button-default uk-button-small">'
				. '<span uk-icon="icon: plus; ratio:.7"></span> Create now'
				. '</button></form>';

			$schedBadge = $isScheduled
				? '<span class="uk-label uk-label-success" style="font-size:10px">' . $scheduleLabel . '</span>'
				: '<span class="uk-label" style="font-size:10px;background:#aaa">Not scheduled</span>';

			$rows .= '
			<tr>
				<td>
					<span uk-icon="icon: ' . $cfg['icon'] . '; ratio:.85" class="uk-margin-small-right"></span>
					<strong>' . $cfg['label'] . '</strong>
				</td>
				<td><span class="uk-label uk-label-' . $statusColor . '" style="font-size:10px">' . $statusText . '</span></td>
				<td>' . $dateHtml . ' ' . $storageBadge . '</td>
				<td class="uk-text-center"><span class="uk-badge">' . $count . '</span></td>
				<td>' . $schedBadge . '</td>
				<td>' . $createForm . '</td>
			</tr>';
		}

		$widget = '
		<div class="uk-card uk-card-default uk-margin-bottom">
			<div class="uk-card-header uk-padding-small">
				<div class="uk-flex uk-flex-middle" style="gap:8px">
					<span uk-icon="icon: database; ratio:1"></span>
					<h3 class="uk-card-title uk-margin-remove uk-flex-1">
						<a href="' . $pageUrl . '">DB Backup</a>
					</h3>
					<span class="uk-text-small uk-text-muted">' . $totalCount . ' total &middot; ' . $diskUsed . '</span>
				</div>
			</div>
			<div class="uk-card-body uk-padding-small">
				<table class="uk-table uk-table-small uk-table-divider uk-margin-remove">
					<thead>
						<tr>
							<th class="uk-text-small">Type</th>
							<th class="uk-text-small">Status</th>
							<th class="uk-text-small">Latest backup</th>
							<th class="uk-text-small uk-text-center">Count</th>
							<th class="uk-text-small">Schedule</th>
							<th class="uk-text-small">Action</th>
						</tr>
					</thead>
					<tbody>' . $rows . '</tbody>
				</table>
			</div>
		</div>';

		$event->return = $widget . $event->return;
	}

	// ── Render: verify page ─────────────────────────────────────────────────────

	protected function renderVerify(string $filename): string {
		$filename = basename((string)$filename);
		$pageUrl  = $this->page->url;

		if (!preg_match('/^db-[a-z\-]*[\d_\-]+\.sql\.gz$/', $filename)) {
			$this->error('Invalid filename.');
			$this->session->redirect($pageUrl);
			return '';
		}

		$filepath = $this->wire('config')->paths->assets . self::BACKUP_DIR . $filename;
		$result   = $this->verifyBackup($filepath);

		$this->headline('Verify: ' . $filename);
		$backUrl = "<a href=\"{$pageUrl}\" class=\"uk-button uk-button-default uk-margin-bottom\"><span uk-icon=\"icon: arrow-left; ratio:.8\"></span>&nbsp; Back</a>";

		if ($result['valid']) {
			// Count tables in backup
			$tables = $this->getBackupTables($filename);
			$tableCount = count($tables);
			$tableList  = implode(', ', array_map(fn($t) => "<code>{$t}</code>", $tables));

			return $backUrl . "
			<div class=\"uk-alert uk-alert-success\" uk-alert>
				<span uk-icon=\"check\"></span>
				<strong>Backup is valid.</strong> gzip integrity passed, SQL structure verified.
			</div>
			<div class=\"uk-card uk-card-default uk-card-body uk-margin\">
				<h3 class=\"uk-card-title\">Contents</h3>
				<p class=\"uk-text-small\"><strong>{$tableCount}</strong> tables found:</p>
				<p class=\"uk-text-small\">{$tableList}</p>
			</div>";
		} else {
			return $backUrl . "
			<div class=\"uk-alert uk-alert-danger\" uk-alert>
				<span uk-icon=\"warning\"></span>
				<strong>Backup verification failed:</strong> " . htmlspecialchars($result['error']) . "
			</div>";
		}
	}

	// ── Render: partial restore page ──────────────────────────────────────────

	protected function renderPartialRestore(string $filename): string {
		$filename = basename((string)$filename);
		$pageUrl  = $this->page->url;
		$csrf     = $this->session->CSRF->renderInput();

		if (!preg_match('/^db-[a-z\-]*[\d_\-]+\.sql\.gz$/', $filename)) {
			$this->error('Invalid filename.');
			$this->session->redirect($pageUrl);
			return '';
		}

		$tables  = $this->getBackupTables($filename);
		$backUrl = "<a href=\"{$pageUrl}\" class=\"uk-button uk-button-default uk-margin-bottom\"><span uk-icon=\"icon: arrow-left; ratio:.8\"></span>&nbsp; Back</a>";

		if (empty($tables)) {
			return $backUrl . "<div class=\"uk-alert uk-alert-warning\" uk-alert>Could not read table list from this backup.</div>";
		}

		$this->headline('Partial Restore: ' . $filename);

		// Get current DB tables for comparison
		$cfg = $this->wire('config');
		try {
			$dsn     = "mysql:host={$cfg->dbHost};dbname={$cfg->dbName};charset={$cfg->dbCharset}";
			$pdo     = new \PDO($dsn, $cfg->dbUser, $cfg->dbPass);
			$current = $pdo->query("SHOW TABLES")->fetchAll(\PDO::FETCH_COLUMN);
		} catch (\Exception $e) {
			$current = [];
		}

		$rows = '';
		foreach ($tables as $tbl) {
			$inCurrent = in_array($tbl, $current) ? '<span class="uk-label">exists</span>' : '<span class="uk-label uk-label-warning">new</span>';
			$rows .= "
			<tr>
				<td><label><input type=\"checkbox\" name=\"tables[]\" value=\"{$tbl}\" class=\"uk-checkbox\" checked>&nbsp; <code>{$tbl}</code></label></td>
				<td>{$inCurrent}</td>
			</tr>";
		}

		$preNote = $this->pre_restore_backup
			? '<p class="uk-text-small uk-text-muted"><span uk-icon="icon: info; ratio:.8"></span> A pre-restore backup will be created automatically before restoring.</p>'
			: '';

		return $backUrl . "
		<div class=\"uk-alert uk-alert-warning\" uk-alert>
			<span uk-icon=\"warning\"></span>
			Selected tables will be <strong>dropped and recreated</strong> from the backup. All current data in those tables will be lost.
		</div>
		{$preNote}
		<form method=\"post\" action=\"{$pageUrl}\">
			{$csrf}
			<input type=\"hidden\" name=\"action\" value=\"partial_restore\">
			<input type=\"hidden\" name=\"file\" value=\"{$filename}\">
			<div class=\"uk-overflow-auto uk-margin\">
			<table class=\"uk-table uk-table-small uk-table-divider uk-table-hover uk-table-striped\">
				<thead>
					<tr>
						<th><label><input type=\"checkbox\" class=\"uk-checkbox\" id=\"pdb-select-all\"> &nbsp;Table</label></th>
						<th>Status in current DB</th>
					</tr>
				</thead>
				<tbody>{$rows}</tbody>
			</table>
			</div>
			<button type=\"submit\" class=\"uk-button uk-button-danger\"
				onclick=\"return confirm('Restore selected tables? Current data in those tables will be overwritten.')\">
				<span uk-icon=\"icon: history; ratio:.8\"></span>&nbsp; Restore Selected Tables
			</button>
		</form>
		<script>
		document.getElementById('pdb-select-all').addEventListener('change', function() {
			document.querySelectorAll('input[name=\"tables[]\"]').forEach(cb => cb.checked = this.checked);
		});
		</script>";
	}

	// ── CSS + JS ──────────────────────────────────────────────────────────────

	protected function renderStyles(): string {
		// All styling delegated to UIkit — no custom CSS needed
		return '';
	}

	protected function renderScripts(string $pageUrl): string {
		// Pre-compute CSRF values — complex expressions can't be interpolated in heredoc
		$csrfName  = $this->wire('session')->CSRF->getTokenName();
		$csrfValue = $this->wire('session')->CSRF->getTokenValue();
		return <<<HTML
		<script>
		// CSRF token embedded from PHP — avoids fragile DOM selector
		const pdbCsrfName  = '{$csrfName}';
		const pdbCsrfToken = '{$csrfValue}';

		// ── Create backup with progress bar ─────────────────────────────────────
		function pdbCreateBackup(btn, type) {
			type = type || 'regular';
			btn.disabled = true;
			const origHtml = btn.innerHTML;
			btn.innerHTML = '<span uk-spinner="ratio:.6"></span>&nbsp; Creating...';
			document.getElementById('pdb-progress').classList.remove('uk-hidden');
			const bar = document.getElementById('pdb-progress-bar');
			const msg = document.getElementById('pdb-progress-msg');
			let pct = 0;
			const ticker = setInterval(() => {
				pct = Math.min(pct + 2, 90);
				bar.value = pct;
			}, 300);
			fetch('{$pageUrl}?pdb_ajax=create&type=' + type + '&' + pdbCsrfName + '=' + pdbCsrfToken)
				.then(r => r.json())
				.then(data => {
					clearInterval(ticker);
					bar.value = 100;
					if (data.success) {
						msg.textContent = '✓ ' + data.filename;
						setTimeout(() => location.reload(), 800);
					} else {
						msg.textContent = '✗ ' + (data.error || 'Unknown error');
						btn.disabled = false;
						btn.innerHTML = origHtml;
					}
				})
				.catch(e => {
					clearInterval(ticker);
					msg.textContent = '✗ ' + e.message;
					btn.disabled = false;
					btn.innerHTML = origHtml;
				});
		}

		// ── Inline label editing ─────────────────────────────────────────────────
		document.querySelectorAll('.pdb-label').forEach(el => {
			el.addEventListener('click', function() {
				const file    = this.dataset.file;
				const current = this.querySelector('em') ? '' : this.textContent;
				const input   = document.createElement('input');
				input.type    = 'text';
				input.value   = current;
				input.className = 'uk-input uk-form-small';
				input.style.width = '140px';
				this.replaceWith(input);
				input.focus();
				let pdbSaving = false;
				const save = () => {
					if (pdbSaving) return;
					pdbSaving = true;
					const label = input.value.trim();
					fetch('{$pageUrl}?pdb_ajax=label&file=' + encodeURIComponent(file) + '&label=' + encodeURIComponent(label) + '&' + pdbCsrfName + '=' + pdbCsrfToken)
						.then(() => location.reload())
						.catch(() => { pdbSaving = false; });
				};
				input.addEventListener('blur', save);
				input.addEventListener('keydown', e => {
					if (e.key === 'Enter') { e.preventDefault(); save(); }
					if (e.key === 'Escape') location.reload();
				});
			});
		});

		// ── Chunked upload ───────────────────────────────────────────────────────
		const PDB_CHUNK_SIZE = 2 * 1024 * 1024; // 2MB chunks

		function pdbChunkedUpload(btn) {
			const file = document.getElementById('pdb-upload-file').files[0];
			if (!file) { alert('Please select a .sql.gz file.'); return; }
			if (!file.name.endsWith('.sql.gz') && !file.name.endsWith('.gz')) {
				alert('Only .sql.gz files are accepted.'); return;
			}

			const restoreAfter = document.getElementById('pdb-restore-after').checked ? '1' : '0';
			const totalChunks  = Math.ceil(file.size / PDB_CHUNK_SIZE);
			const uploadId     = Math.random().toString(16).slice(2) + Date.now().toString(16);
			const bar          = document.getElementById('pdb-upload-bar');
			const msg          = document.getElementById('pdb-upload-msg');

			document.getElementById('pdb-upload-progress').classList.remove('uk-hidden');
			btn.disabled = true;

			function sendChunk(idx) {
				const start  = idx * PDB_CHUNK_SIZE;
				const end    = Math.min(start + PDB_CHUNK_SIZE, file.size);
				const slice  = file.slice(start, end);
				const fd     = new FormData();
				fd.append('chunk', slice);
				fd.append('upload_id', uploadId);
				fd.append('chunk_index', idx);
				fd.append('total_chunks', totalChunks);
				fd.append('filename', file.name);
				fd.append('restore_after_upload', restoreAfter);
				if (pdbCsrfName) fd.append(pdbCsrfName, pdbCsrfToken);

				bar.value = Math.round((idx / totalChunks) * 100);
				msg.textContent = 'Uploading chunk ' + (idx + 1) + ' / ' + totalChunks + '...';

				fetch('{$pageUrl}?pdb_ajax=chunk', { method: 'POST', body: fd })
					.then(r => r.json())
					.then(data => {
						if (!data.success) {
							msg.textContent = '✗ ' + (data.error || 'Upload failed');
							btn.disabled = false;
							return;
						}
						if (data.status === 'complete') {
							bar.value = 100;
							if (data.restore_error) {
								msg.textContent = '⚠ Uploaded but restore failed: ' + data.restore_error;
							} else {
								msg.textContent = data.restored
									? '✓ Uploaded and restored: ' + data.filename
									: '✓ Uploaded: ' + data.filename;
							}
							setTimeout(() => location.reload(), 1500);
						} else {
							sendChunk(idx + 1);
						}
					})
					.catch(e => {
						msg.textContent = '✗ ' + e.message;
						btn.disabled = false;
					});
			}
			sendChunk(0);
		}
		</script>
HTML;
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

		// ── Pre-restore backup ────────────────────────────────────────────────────
		$f = $modules->get('InputfieldCheckbox');
		$f->attr('name', 'pre_restore_backup');
		$f->label = 'Auto-backup before restore';
		$f->description = 'Creates a backup of the current database before any restore operation.';
		$f->attr('checked', !empty($data['pre_restore_backup']));
		$f->attr('value', 1);
		$f->columnWidth = 50;
		$wrapper->add($f);

		// ── Exclude tables ────────────────────────────────────────────────────────
		$f = $modules->get('InputfieldTextarea');
		$f->attr('name', 'exclude_tables');
		$f->label = 'Exclude tables from backup';
		$f->description = 'One table name per line. These tables will be skipped during backup.';
		$f->placeholder = "cache\nsessions\nwire_cache";
		$f->attr('value', $data['exclude_tables'] ?? '');
		$f->attr('rows', 4);
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

		// ── Weekly schedule ───────────────────────────────────────────────────────
		$fsw = $modules->get('InputfieldFieldset');
		$fsw->label = 'Weekly Backups';
		$fsw->description = 'Independent schedule with its own retention. Use for longer-term snapshots.';
		$fsw->collapsed = Inputfield::collapsedYes;

		$f = $modules->get('InputfieldSelect');
		$f->attr('name', 'cron_weekly');
		$f->label = 'Weekly backup schedule';
		$f->addOptions(['never' => 'Never', 'everyWeek' => 'Every week', 'every2Weeks' => 'Every 2 weeks', 'every4Weeks' => 'Every 4 weeks']);
		$f->attr('value', $data['cron_weekly'] ?? 'never');
		$f->columnWidth = 50;
		$fsw->add($f);

		$f = $modules->get('InputfieldInteger');
		$f->attr('name', 'retention_weekly');
		$f->label = 'Keep (weekly backups)';
		$f->description = '0 = unlimited';
		$f->attr('value', $data['retention_weekly'] ?? 4);
		$f->min = 0;
		$f->columnWidth = 50;
		$fsw->add($f);
		$wrapper->add($fsw);

		// ── Monthly schedule ──────────────────────────────────────────────────────
		$fsm = $modules->get('InputfieldFieldset');
		$fsm->label = 'Monthly Backups';
		$fsm->description = 'Long-term archival backups on a monthly schedule.';
		$fsm->collapsed = Inputfield::collapsedYes;

		$f = $modules->get('InputfieldSelect');
		$f->attr('name', 'cron_monthly');
		$f->label = 'Monthly backup schedule';
		$f->addOptions(['never' => 'Never', 'every4Weeks' => 'Every 4 weeks']);
		$f->attr('value', $data['cron_monthly'] ?? 'never');
		$f->columnWidth = 50;
		$fsm->add($f);

		$f = $modules->get('InputfieldInteger');
		$f->attr('name', 'retention_monthly');
		$f->label = 'Keep (monthly backups)';
		$f->description = '0 = unlimited';
		$f->attr('value', $data['retention_monthly'] ?? 3);
		$f->min = 0;
		$f->columnWidth = 50;
		$fsm->add($f);
		$wrapper->add($fsm);

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
