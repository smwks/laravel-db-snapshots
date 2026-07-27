<?php

return [
    // Enable smart timestamp-based caching
    'cache_by_default' => false,

    // Identity fields included in every snapshot's metadata sidecar.
    // 'app' falls back to config('app.name') when left null.
    'identity' => [
        'app' => env('DB_SNAPSHOTS_IDENTITY_APP'),
        'app_version' => env('DB_SNAPSHOTS_IDENTITY_APP_VERSION'),
    ],

    'filesystem' => [
        'local_disk' => env('DB_SNAPSHOTS_LOCAL_DISK', 'local'),
        'local_path' => 'db-snapshots',
        'archive_disk' => env('DB_SNAPSHOTS_ARCHIVE_DISK', 'private'),
        'archive_path' => 'db-snapshots',
    ],

    // Used when filesystem.archive_disk is 'remote' — talks to another
    // instance of this package with its server feature enabled.
    'remote' => [
        'endpoint' => env('DB_SNAPSHOTS_REMOTE_ENDPOINT'),
        'project' => env('DB_SNAPSHOTS_REMOTE_PROJECT'),
        'token' => env('DB_SNAPSHOTS_REMOTE_TOKEN'),
        'timeout' => env('DB_SNAPSHOTS_REMOTE_TIMEOUT', 300),
    ],

    // Exposes this app's snapshots over an authenticated API — either so
    // another environment (e.g. local dev) can pull directly from this app,
    // or so this app acts as a centralized hub for other projects.
    'server' => [
        'enabled' => env('DB_SNAPSHOTS_SERVER_ENABLED', false),
        'route_prefix' => 'api/db-snapshots',
        'projects' => [
            // Example:
            // 'my-app' => [
            //     'token' => env('DB_SNAPSHOTS_SERVER_TOKEN_MY_APP'),
            //     'archive_disk' => 's3',
            //     'archive_path' => 'db-snapshots/my-app',
            // ],
        ],
    ],

    // Global SQL commands to run after ANY snapshot load
    'post_load_sqls' => [
        // Example: 'SET GLOBAL time_zone = "+00:00"',
        // Example: 'ANALYZE TABLE users',
    ],

    // Plan groups: Named groups of plans for batch operations
    'plan_groups' => [
        // Example:
        // 'daily' => [
        //     'plans' => ['daily-subset-1', 'daily-subset-2'],
        //     'post_load_sqls' => [
        //         // SQL commands to run after ALL plans in this group have been loaded
        //         // 'ANALYZE TABLE users',
        //     ],
        // ],
    ],

    'plans' => [
        'daily' => [
            'connection' => null,
            'file_template' => 'db-snapshot-daily-{date:Ymd}',
            // platform specific reasonable dump_options:
            // - MySQL 8.0+: '--single-transaction --no-tablespaces --set-gtid-purged=OFF --column-statistics=0'
            // - MariaDB:    '--single-transaction --no-tablespaces'
            // - PostgreSQL: '--no-owner --no-acl'
            'dump_options' => '',
            'schema_only_tables' => ['failed_jobs'],
            'tables' => [],
            'ignore_tables' => [],
            'keep_last' => 1,
            // Free-form tags included in this plan's snapshot metadata
            'tags' => [],
            // Run SELECT COUNT(*) per data table and resolve the full table
            // list when 'tables' is empty. Requires a working database
            // connection for this plan (not just dump-tool credentials).
            // Off by default since COUNT(*) can be costly on very large
            // tables.
            'capture_row_counts' => false,
            'environment_locks' => [
                'create' => 'production',
                'load' => 'local',
            ],
            // Plan-specific SQL commands to run after loading this plan
            'post_load_sqls' => [
                // Example: 'UPDATE users SET environment = "local"',
            ],
        ],
    ],

    'utilities' => [
        'mysql' => [
            'mysqldump' => 'mysqldump',
            'mysql' => 'mysql',
        ],
        'pgsql' => [
            'pg_dump' => 'pg_dump',
            'psql' => 'psql',
        ],
        'zcat' => env('DB_SNAPSHOTS_UTILITIES_ZCAT', 'zcat'),
        'gzip' => env('DB_SNAPSHOTS_UTILITIES_GZIP', 'gzip'),
    ],
];
