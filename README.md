# Laravel Storage Cleaner

A Laravel 10+ package for scheduled storage cleanup with file retention, optional path size caps, and database pruning policies.

This package is a safety net. It does not replace log rotation. For Laravel logs, use the daily log driver first:

```dotenv
LOG_CHANNEL=daily
LOG_DAYS=14
```

## Installation

```bash
composer require infinitietechnologies/laravel-storage-cleaner
```

Publish the config:

```bash
php artisan vendor:publish --tag=storage-cleaner-config
```

## Usage

Run cleanup manually:

```bash
php artisan storage:clean
```

Preview deletes without changing anything:

```bash
php artisan storage:clean --dry-run
```

Limit to a driver:

```bash
php artisan storage:clean --driver=file
php artisan storage:clean --driver=database
```

Output JSON for monitoring:

```bash
php artisan storage:clean --json
```

## Scheduling

The package registers its own scheduler entry when enabled:

```dotenv
STORAGE_CLEANER_ENABLED=true
STORAGE_CLEANER_SCHEDULE_ENABLED=true
STORAGE_CLEANER_FREQUENCY=daily
```

Make sure Laravel's scheduler is running on the server:

```bash
* * * * * cd /path-to-your-project && php artisan schedule:run >> /dev/null 2>&1
```

Supported frequencies:

- `daily`
- `weekly`
- `custom`, using `STORAGE_CLEANER_CRON`

## Configuration

Default file cleanup paths:

- `storage/logs`
- `storage/framework/cache`
- `storage/framework/sessions`

Set retention:

```dotenv
STORAGE_CLEANER_FILE_RETENTION_DAYS=15
```

Set a path size cap:

```dotenv
STORAGE_CLEANER_FILE_MAX_SIZE_MB=1024
```

When the cap is enabled, the oldest files in each configured path are deleted until the path is under the cap.

## Database Cleanup

Database cleanup is disabled by default. Enable it only after reviewing your configured tables:

```dotenv
STORAGE_CLEANER_DATABASE_ENABLED=true
```

Example config:

```php
'database' => [
    'enabled' => env('STORAGE_CLEANER_DATABASE_ENABLED', false),
    'tables' => [
        'failed_jobs' => 30,
        'sessions' => [
            'retention_days' => 7,
            'date_column' => 'last_activity',
            'date_type' => 'unix',
        ],
    ],
],
```

Simple numeric values use `created_at` as the date column. Array policies support `datetime` and `unix` date types.

## Recommended Production Setup

1. Use Laravel daily logs with `LOG_DAYS=7` or `LOG_DAYS=14`.
2. Keep `php artisan schedule:run` active every minute through cron or your process manager.
3. Run `php artisan storage:clean --dry-run` before enabling database cleanup.
4. Add server disk monitoring. Cleanup helps, but alerts prevent surprises.

## License

MIT
