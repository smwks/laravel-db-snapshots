<?php

return [
    // Enable smart timestamp-based caching
    'cache_by_default' => false,

    'filesystem' => [
        'local_disk' => 'local',
        'local_path' => 'db-snapshots',
        'archive_disk' => 'cloud',
        'archive_path' => 'db-snapshots',
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
            // MySQL 8.0+: '--single-transaction --no-tablespaces --set-gtid-purged=OFF --column-statistics=0'
            // MariaDB:    '--single-transaction --no-tablespaces'
            // PostgreSQL: '--no-owner --no-acl'
            'dump_options' => '--single-transaction --no-tablespaces --set-gtid-purged=OFF --column-statistics=0',
            'schema_only_tables' => ['failed_jobs'],
            'tables' => [],
            'ignore_tables' => [],
            'keep_last' => 1,
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
        'zcat' => 'zcat',
        'gzip' => 'gzip',
    ],
];
