# ProcessDbBackup

Database backup and restore module for ProcessWire 3.x. Supports local storage and Backblaze B2, manual and scheduled backups via LazyCron, with a native UIkit Admin UI.

## Features

- **One-click backup** from the Admin UI
- **Multiple backups** with configurable retention count (auto-delete oldest)
- **Restore** any backup directly from the UI
- **Download** backups as `.sql.gz` files
- **Backblaze B2** optional cloud upload (B2 API v3) with configurable local copy behaviour
- **LazyCron** automatic scheduled backups — 22 intervals from every 30 seconds to every 4 weeks
- **mysqldump** with PHP PDO fallback (gzip-compressed in both cases)
- **Protected local storage** (`site/assets/backups/db/`) with `.htaccess` deny-all
- **Meta-driven backup list** — backups appear in the UI even when stored on B2 only
- Module log at `Setup → Logs → db-backup`

## Requirements

- ProcessWire ≥ 3.0.0
- PHP ≥ 8.0
- PHP extensions: `zlib`, `PDO`, `curl` (curl required for B2 only)
- `mysqldump` / `mysql` CLI — optional but recommended for large databases
- LazyCron module — required for scheduled backups

## Installation

1. Copy the `ProcessDbBackup/` folder to `site/modules/`
2. In **Admin → Modules**, click **Refresh** and install **ProcessDbBackup**
3. The module creates the admin page at **Admin → DB Backup** automatically
4. Assign the `db-backup` permission to any roles that need access

## Configuration

Go to **Admin → Modules → Configure → ProcessDbBackup**:

| Setting | Description |
|---|---|
| Max backups (retention) | Oldest backups are deleted automatically when this count is exceeded. `0` = unlimited |
| Auto-backup schedule | LazyCron interval. See full list below |
| Enable Backblaze B2 upload | Upload every backup to B2 after creation |
| Keep local copy when B2 is enabled | Checked: file kept locally **and** uploaded to B2. Unchecked: local file deleted after successful B2 upload |
| Application Key ID | From your B2 bucket App Keys |
| Application Key (secret) | Secret portion of the App Key |
| Bucket ID | Found in your B2 bucket settings |
| Path prefix in bucket | Optional subfolder, e.g. `mysite/db-backups` |

### Available schedule intervals

`every30Seconds` · `everyMinute` · `every2Minutes` · `every3Minutes` · `every4Minutes` · `every5Minutes` · `every10Minutes` · `every15Minutes` · `every30Minutes` · `every45Minutes` · `everyHour` · `every2Hours` · `every4Hours` · `every6Hours` · `every12Hours` · `everyDay` · `every2Days` · `every4Days` · `everyWeek` · `every2Weeks` · `every4Weeks`

LazyCron fires on the next page load after the interval has elapsed, so actual timing depends on site traffic.

## Storage modes

| Mode | Local file | B2 | Download / Restore |
|---|---|---|---|
| Local only | ✅ | — | Available |
| Local + B2 | ✅ | ✅ | Available |
| B2 only | — | ✅ | Disabled (tooltip shown) |

The backup list is driven by `.meta.json`, not the local filesystem. B2-only entries appear in the UI with a **B2 only** badge; download and restore buttons are greyed out since the file is not present locally.

## Backup file location

```
site/assets/backups/db/db-YYYY-MM-DD_HHiiss.sql.gz
site/assets/backups/db/.meta.json
```

The directory is protected by `.htaccess` (deny all direct HTTP access). `.meta.json` tracks filename, date, size, method, and storage flags for each backup.

## Backup methods

1. **mysqldump** (preferred) — invoked via shell; uses `--single-transaction --quick` for InnoDB-safe hot backups. Output is piped through `gzip`.
2. **PHP PDO** (fallback) — used when `mysqldump` is not available. Exports all tables row-by-row in 100-row `INSERT` batches, written directly to a `.gz` file via `gzopen`.

The method used is recorded in the log and in `.meta.json`.

## Restore

Restore **overwrites the entire database**. The UI shows a confirmation dialog before proceeding. Two methods are tried in order:

1. **mysql CLI** — pipes the decompressed dump into `mysql`
2. **PHP PDO** — reads the `.gz` file and executes statements one by one

Restore is only available for backups that have a local copy. B2-only backups must be downloaded manually to restore.

## Backblaze B2 setup

1. Create a private B2 bucket
2. Go to **App Keys** and create a new key scoped to that bucket with capabilities: `readFiles`, `writeFiles`, `listFiles`, `deleteFiles`
3. Copy the **Key ID** and **Application Key** into module settings (the secret is shown only once)
4. Enter the **Bucket ID** (found on the bucket overview page)
5. Optionally set a **Path prefix** to organise backups inside the bucket

The module uses B2 API v3 (`/b2api/v3/`).

## Changelog

### 1.0.0 — 2026-03-28
- Initial release
- Manual and LazyCron scheduled backups (22 intervals)
- Local storage with `.htaccess` protection
- Backblaze B2 upload via API v3
- Configurable "keep local copy" when B2 is enabled
- B2-only storage mode — backups tracked in meta even without local file
- Restore and delete from Admin UI
- Download as `.sql.gz`
- mysqldump with PHP PDO fallback
- mysql CLI restore with PHP PDO fallback
- Retention policy (auto-delete oldest)
- Native UIkit Admin UI — no custom CSS
- Module log (`db-backup`)
- `db-backup` permission
