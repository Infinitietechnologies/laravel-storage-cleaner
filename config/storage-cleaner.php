<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Storage Cleaner
    |--------------------------------------------------------------------------
    |
    | Keep this enabled in production only after reviewing the paths and
    | database tables below. Use --dry-run before allowing deletes.
    |
    */

    'enabled' => env('STORAGE_CLEANER_ENABLED', true),

    'safety' => [
        'confirm_before_delete' => env('STORAGE_CLEANER_CONFIRM_BEFORE_DELETE', true),
        'log_deleted_files' => env('STORAGE_CLEANER_LOG_DELETED_FILES', false),
        'log_channel' => env('STORAGE_CLEANER_LOG_CHANNEL', null),
        'sample_limit' => env('STORAGE_CLEANER_SAMPLE_LIMIT', 10),
    ],

    'schedule' => [
        'enabled' => env('STORAGE_CLEANER_SCHEDULE_ENABLED', true),
        'frequency' => env('STORAGE_CLEANER_FREQUENCY', 'daily'), // daily|weekly|custom
        'cron' => env('STORAGE_CLEANER_CRON', '0 0 */15 * *'),
    ],

    'drivers' => [
        'file' => [
            'enabled' => env('STORAGE_CLEANER_FILE_ENABLED', true),

            'paths' => [
                'logs' => [
                    'path' => storage_path('logs'),
                    'retention_days' => env('STORAGE_CLEANER_LOG_RETENTION_DAYS', 15),
                    'recursive' => true,
                ],

                'cache' => [
                    'path' => storage_path('framework/cache'),
                    'retention_days' => env('STORAGE_CLEANER_CACHE_RETENTION_DAYS', 15),
                    'recursive' => true,
                ],

                'sessions' => [
                    'path' => storage_path('framework/sessions'),
                    'retention_days' => env('STORAGE_CLEANER_SESSION_RETENTION_DAYS', 15),
                    'recursive' => true,
                ],
            ],

            'delete_older_than_days' => env('STORAGE_CLEANER_FILE_RETENTION_DAYS', 15),

            /*
             * Optional cap per configured path. When a path grows beyond this
             * size, the oldest files are deleted until the path is under cap.
             * Set null to disable size caps.
             */
            'max_size_mb' => env('STORAGE_CLEANER_FILE_MAX_SIZE_MB', null),

            'exclude' => [
                '.gitignore',
                '.gitkeep',
                '*.gitkeep',
            ],
        ],

        'disk' => [
            'enabled' => env('STORAGE_CLEANER_DISK_ENABLED', false),
            'delete_older_than_days' => env('STORAGE_CLEANER_DISK_RETENTION_DAYS', 15),
            'max_size_mb' => env('STORAGE_CLEANER_DISK_MAX_SIZE_MB', null),

            'exclude' => [
                '.gitignore',
                '.gitkeep',
                '*.gitkeep',
            ],

            /*
             * Example:
             * 'local' => [
             *     'temp-exports' => [
             *         'path' => 'temp/exports',
             *         'retention_days' => 7,
             *         'recursive' => true,
             *         'exclude' => ['important-*.pdf'],
             *     ],
             * ],
             */
            'disks' => [
                'local' => [
                    // 'temp' => 'temp',
                ],
            ],
        ],

        'database' => [
            'enabled' => env('STORAGE_CLEANER_DATABASE_ENABLED', false),

            /*
             * Each table can be configured as:
             * 'failed_jobs' => 30
             * or:
             * 'sessions' => [
             *     'retention_days' => 7,
             *     'date_column' => 'last_activity',
             *     'date_type' => 'unix', // datetime|unix
             * ]
             */
            'tables' => [
                'jobs' => 7,
                'failed_jobs' => 30,
                'sessions' => [
                    'retention_days' => 7,
                    'date_column' => 'last_activity',
                    'date_type' => 'unix',
                ],
            ],
        ],
    ],
];
