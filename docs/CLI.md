# Scheduled backups without frontend LazyCron

Large database dumps should not run in the request that renders a public page.
Disable **Run scheduled backups after frontend page views** in the module
settings and invoke the deployed CLI entry point from cron, launchd, or a queue
worker instead.

```bash
php -d memory_limit=512M -d max_execution_time=0 \
  site/modules/ProcessDbBackup/bin/process-db-backup.php \
  --type=regular --root=/path/to/processwire
```

Use `--type=weekly` and `--type=monthly` for the corresponding retention
classes. Validate the installation without writing a dump with `--check`.

The command uses the module's existing lock, integrity checks, retention, and
storage configuration. Schedule only one backup process at a time.
