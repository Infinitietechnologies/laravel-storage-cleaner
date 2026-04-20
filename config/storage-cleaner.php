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

    'schedule' => [
        'enabled' => env('STORAGE_CLEANER_SCHEDULE_ENABLED', true),
        'frequency' => env('STORAGE_CLEANER_FREQUENCY', 'daily'), // daily|weekly|custom
        'cron' => env('STORAGE_CLEANER_CRON', '0 0 */15 * *'),
    ],

    'drivers' => [
        'file' => [
            'enabled' => env('STORAGE_CLEANER_FILE_ENABLED', true),

            'paths' => [
                storage_path('logs'),
                storage_path('framework/cache'),
                storage_path('framework/sessions'),
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
