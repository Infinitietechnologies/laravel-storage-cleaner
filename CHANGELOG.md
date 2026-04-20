# Changelog

All notable changes to `infinitietechnologies/laravel-storage-cleaner` will be documented in this file.

## v1.0.0 - 2026-04-20

### Overview

`v1.0.0` is the first stable release of Laravel Storage Cleaner, a Laravel 10, 11, and 12 compatible package for keeping application storage under control through safe, scheduled cleanup policies.

The package is designed as a practical safety net for production Laravel applications. It helps remove old disposable files, prune configured database records, and enforce optional storage caps while still encouraging the right first line of defense: Laravel log rotation through the daily log channel.

### Highlights

- Automatic Laravel scheduler registration.
- No manual `Schedule::command(...)` setup required.
- `php artisan schedule:run` is enough once Laravel's scheduler is active.
- Scheduled cleanup runs with `--force` internally so it never waits for interactive confirmation.
- Safe manual command behavior with confirmation prompts before real deletion.
- Dry-run support for previewing cleanup actions before deleting anything.
- Human-readable command output and JSON output for monitoring systems.
- Sample paths in dry-run and cleanup summaries.
- Optional audit logging for deleted files.
- Local directory cleanup with named targets and per-target retention policies.
- Laravel filesystem disk cleanup, including local disks and Flysystem-backed disks such as S3.
- Wildcard exclusion rules to protect important files and directories.
- Optional max-size caps that delete oldest files first when a target grows too large.
- Configurable database pruning for records such as jobs, failed jobs, and sessions.

### Artisan Commands

Primary command:

```bash
php artisan storage:clean
```

Explicit alias:

```bash
php artisan storage:clean-old
```

Preview without deleting:

```bash
php artisan storage:clean --dry-run
php artisan storage:clean --pretend
```

Target one driver:

```bash
php artisan storage:clean --driver=file
php artisan storage:clean --driver=disk
php artisan storage:clean --driver=database
```

Output JSON:

```bash
php artisan storage:clean --json
```

Skip manual confirmation:

```bash
php artisan storage:clean --force
```

### Configuration

The package ships with a publishable config file:

```bash
php artisan vendor:publish --tag=storage-cleaner-config
```

Main configuration areas:

- `enabled`: globally enable or disable cleanup.
- `safety`: confirmation, sample paths, and optional audit logging.
- `schedule`: automatic scheduler frequency.
- `drivers.file`: local absolute-path cleanup.
- `drivers.disk`: Laravel filesystem disk cleanup.
- `drivers.database`: database record pruning.

### Safety Notes

File deletion is permanent. This release includes safety defaults and tooling so teams can review policies before enabling them broadly:

- Use `--dry-run --json` before production cleanup.
- Keep targets narrow and explicit.
- Prefer cleaning disposable directories such as temp exports, generated files, or old sessions.
- Avoid broad paths such as `storage/app` unless every child path is disposable.
- Use exclusion patterns for keep files, avatars, user-owned uploads, and critical records.
- Enable audit logging when you need operational traceability.

### Cloud Disk Notes

The disk driver works through Laravel's filesystem abstraction. For S3 and other cloud disks, object listing can be slow, paginated, rate-limited, and potentially billable.

For cloud cleanup:

- Keep paths narrow.
- Avoid cleaning whole buckets.
- Test with `--dry-run --driver=disk`.
- Prefer lifecycle policies from the cloud provider when they fit the use case.

### Testing

This release includes PHPUnit coverage for:

- local file retention cleanup
- dry-run behavior
- exclusion rules
- nested directories
- non-recursive targets
- hidden files
- empty directories
- Laravel fake disk cleanup
- command JSON output
- interactive confirmation cancellation
- forced cleanup
- scheduler registration
- scheduler usage of `--force`

Validation at release:

```text
composer test: OK (13 tests, 39 assertions)
composer validate --strict: OK
```

### Installation

```bash
composer require infinitietechnologies/laravel-storage-cleaner
```

### Recommended Production Setup

Use Laravel's daily log driver first:

```dotenv
LOG_CHANNEL=daily
LOG_DAYS=14
```

Then enable the Laravel scheduler on the server:

```bash
* * * * * cd /path-to-your-project && php artisan schedule:run >> /dev/null 2>&1
```

Finally, configure this package for old files, temporary exports, cache/session cleanup, and optional database pruning.
