# Laravel DB Snapshots

Database snapshots for Laravel using native CLI tools (`mysqldump`, `pg_dump`, `gzip`, etc.). Create compressed snapshots of your production database, store them on any Laravel filesystem disk, and load them into your local environment with a single command.

Supports **MySQL/MariaDB** and **PostgreSQL** with automatic driver detection from your Laravel database config.

## Installation

```bash
composer require smwks/laravel-db-snapshots
```

Publish the config file:

```bash
php artisan vendor:publish --provider="SMWks\LaravelDbSnapshots\DbSnapshotsServiceProvider" --tag="config"
```

This creates `config/db-snapshots.php`.

## How It Works

The package revolves around **plans** -- named snapshot configurations. A plan defines which database connection to use, how to name the files, which tables to include or exclude, and where to store the results.

The typical workflow:

1. **Production**: `db-snapshots:create` runs `mysqldump` (or `pg_dump`), compresses the output with `gzip`, and uploads it to your archive disk (e.g. S3).
2. **Local**: `db-snapshots:load` downloads the latest snapshot, drops your local tables, and pipes the dump through `zcat` into `mysql` (or `psql`).

## Configuration

### Filesystem

Snapshots are stored on a Laravel filesystem disk. The **archive disk** is where snapshots are permanently stored (typically a cloud disk like S3). The **local disk** is used for temporary caching during downloads.

```php
'filesystem' => [
    'local_disk'   => 'local',
    'local_path'   => 'db-snapshots',
    'archive_disk' => 's3',        // any configured disk, or 'cloud' to use your default cloud disk
    'archive_path' => 'db-snapshots',
],
```

### Plans

Each plan defines a snapshot configuration:

```php
'plans' => [
    'daily' => [
        'connection'         => null,             // null = use default connection; driver auto-detected
        'file_template'      => 'db-snapshot-daily-{date:Ymd}',
        'dump_options'       => '--single-transaction --no-tablespaces',
        'schema_only_tables' => ['failed_jobs'],  // dump structure only, no data
        'tables'             => [],               // empty = all tables
        'ignore_tables'      => [],               // tables to skip entirely
        'keep_last'          => 1,                // number of snapshots to retain during cleanup
        'environment_locks'  => [
            'create' => 'production',             // only allow creation in this environment
            'load'   => 'local',                  // only allow loading in this environment
        ],
        'post_load_sqls' => [
            // SQL to run after loading this plan's snapshot
            // 'UPDATE users SET password = "$2y$10$..."',
        ],
    ],
],
```

#### File Templates

The `file_template` controls snapshot file naming. It must contain a `{date:FORMAT}` placeholder using PHP date format characters:

| Template | Example Filename |
|---|---|
| `db-snapshot-daily-{date:Ymd}` | `db-snapshot-daily-20250209.sql.gz` |
| `db-snapshot-hourly-{date:YmdH}` | `db-snapshot-hourly-2025020914.sql.gz` |
| `db-snapshot-{date:Ymd-His}` | `db-snapshot-20250209-143022.sql.gz` |

#### Table Selection

You have three ways to control which tables are included:

- **All tables** (default): Leave `tables` and `ignore_tables` empty.
- **Specific tables only**: List them in `tables`. Only these tables will be dumped.
- **Exclude specific tables**: List them in `ignore_tables`. Everything except these will be dumped.

`tables` and `ignore_tables` cannot both be set on the same plan.

`schema_only_tables` dumps the table structure without data -- useful for large tables like `failed_jobs` where you need the schema but not the rows. When using `tables`, any `schema_only_tables` must also appear in the `tables` list.

#### Dump Options

The `dump_options` string is passed directly to the dump utility. Common values:

```php
// MySQL 8.0+
'dump_options' => '--single-transaction --no-tablespaces --set-gtid-purged=OFF --column-statistics=0',

// MariaDB
'dump_options' => '--single-transaction --no-tablespaces',

// PostgreSQL
'dump_options' => '--no-owner --no-acl',
```

#### Environment Locks

Environment locks prevent accidental operations. With the default config, snapshots can only be **created** in `production` and only **loaded** in `local`. Set either to `null` to allow the operation in any environment.

### Plan Groups

Plan groups let you batch multiple plans together. This is useful when your application uses multiple databases:

```php
'plan_groups' => [
    'all-daily' => [
        'plans' => ['daily-main', 'daily-analytics'],
        'post_load_sqls' => [
            // SQL to run after ALL plans in the group have loaded
            'ANALYZE TABLE users',
        ],
    ],
],
```

Plan groups work with `db-snapshots:create` and `db-snapshots:load` -- pass the group name instead of a plan name.

### Caching

When loading snapshots, the file is downloaded to the local disk, loaded into the database, then deleted. You can keep local copies to speed up repeated loads:

```php
'cache_by_default' => false,  // set to true to enable smart caching globally
```

Or use the `--cached` / `--recached` flags on the load command (see below).

### Utilities

The package needs CLI tools available on the system. Configure custom paths if they're not in your `$PATH`:

```php
'utilities' => [
    'mysql' => [
        'mysqldump' => 'mysqldump',  // or '/usr/local/bin/mysqldump'
        'mysql'     => 'mysql',
    ],
    'pgsql' => [
        'pg_dump' => 'pg_dump',
        'psql'    => 'psql',
    ],
    'zcat' => 'zcat',
    'gzip' => 'gzip',
],
```

### Post-Load SQL Commands

SQL commands can be configured at three levels, and they run in this order:

1. **Global** (`post_load_sqls` at the config root) -- runs after every snapshot load
2. **Plan-level** (`post_load_sqls` inside a plan) -- runs after that specific plan loads
3. **Plan group-level** (`post_load_sqls` inside a plan group) -- runs after all plans in the group have loaded

```php
// Global
'post_load_sqls' => [
    'SET GLOBAL time_zone = "+00:00"',
],

// Inside a plan
'plans' => [
    'daily' => [
        'post_load_sqls' => [
            'UPDATE users SET email = CONCAT("user", id, "@example.com")',
        ],
    ],
],
```

## Commands

### `db-snapshots:create`

Create a snapshot.

```bash
# Create using the first configured plan
php artisan db-snapshots:create

# Create using a specific plan
php artisan db-snapshots:create daily

# Create using a plan group (creates all plans in the group)
php artisan db-snapshots:create all-daily

# Create and clean up old snapshots (respects keep_last setting)
php artisan db-snapshots:create daily --cleanup
```

### `db-snapshots:load`

Download and load a snapshot into your local database. By default this **drops all tables** before loading.

```bash
# Load the latest snapshot from the first configured plan
php artisan db-snapshots:load

# Load from a specific plan
php artisan db-snapshots:load daily

# Load a specific file (by index or filename)
php artisan db-snapshots:load daily 2
php artisan db-snapshots:load daily db-snapshot-daily-20250209.sql.gz

# Load all plans in a plan group
php artisan db-snapshots:load all-daily

# Keep a local cached copy for faster future loads
php artisan db-snapshots:load daily --cached

# Force re-download even if a cached copy exists
php artisan db-snapshots:load daily --recached

# Don't drop existing tables before loading
php artisan db-snapshots:load daily --no-drop

# Skip post-load SQL commands
php artisan db-snapshots:load daily --skip-post-commands
```

Use `-v` for verbose output to see the exact commands being run.

### `db-snapshots:list`

List available snapshots, cached files, and plan groups.

```bash
# List all plans and their snapshots
php artisan db-snapshots:list

# List snapshots for a specific plan
php artisan db-snapshots:list daily
```

### `db-snapshots:delete`

Delete a snapshot from the archive.

```bash
# Delete by file index
php artisan db-snapshots:delete daily 1

# Delete by filename
php artisan db-snapshots:delete daily db-snapshot-daily-20250209.sql.gz
```

### `db-snapshots:clear-cache`

Remove locally cached snapshot files.

```bash
# Clear all cached files
php artisan db-snapshots:clear-cache

# Clear all except a specific file
php artisan db-snapshots:clear-cache --except-file=db-snapshot-daily-20250209.sql.gz
```

## Database Driver Support

The database driver is **auto-detected** from your Laravel connection config (`database.connections.{name}.driver`). No manual configuration is needed.

| Driver | Dump Tool | Load Tool | Credentials |
|---|---|---|---|
| MySQL / MariaDB | `mysqldump` | `mysql` | Temporary `.my.cnf` file with `--defaults-extra-file` |
| PostgreSQL | `pg_dump` | `psql` | Temporary `pgpass` file via `PGPASSFILE` |

Compression (`gzip` / `zcat`) is handled the same way for all drivers.

## Requirements

- PHP 8.4+
- Laravel 11+ (via Orchestra Testbench 10)
- The appropriate CLI tools installed on your system (`mysqldump`/`mysql` for MySQL, `pg_dump`/`psql` for PostgreSQL, `gzip`/`zcat` for compression)

## Testing

```bash
composer test
```

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
