<?php

use Illuminate\Support\Facades\Storage;
use SMWks\LaravelDbSnapshots\Drivers\MysqlDriver;
use SMWks\LaravelDbSnapshots\Drivers\PostgresDriver;
use SMWks\LaravelDbSnapshots\SnapshotPlan;

// --- MysqlDriver tests ---

test('mysql driver builds dump command', function () {
    $driver = new MysqlDriver();

    $commands = $driver->buildDumpCommand(
        '/tmp/output.sql',
        '--single-transaction',
        [],
        [],
        [],
        'mydb',
    );

    expect($commands)->toHaveCount(1);
    expect($commands[0])->toContain('mysqldump');
    expect($commands[0])->toContain('--defaults-extra-file={credentials_file}');
    expect($commands[0])->toContain('--single-transaction');
    expect($commands[0])->toContain('mydb');
    expect($commands[0])->toContain('> /tmp/output.sql');
});

test('mysql driver builds dump command with tables', function () {
    $driver = new MysqlDriver();

    $commands = $driver->buildDumpCommand(
        '/tmp/output.sql',
        '--single-transaction',
        ['users', 'posts', 'comments'],
        [],
        [],
        'mydb',
    );

    expect($commands)->toHaveCount(1);
    expect($commands[0])->toContain('mydb users posts comments');
});

test('mysql driver builds dump command with ignore tables', function () {
    $driver = new MysqlDriver();

    $commands = $driver->buildDumpCommand(
        '/tmp/output.sql',
        '--single-transaction',
        [],
        ['logs', 'sessions'],
        [],
        'mydb',
    );

    expect($commands)->toHaveCount(1);
    expect($commands[0])->toContain('--ignore-table=mydb.logs');
    expect($commands[0])->toContain('--ignore-table=mydb.sessions');
});

test('mysql driver builds dump command with schema only tables', function () {
    $driver = new MysqlDriver();

    $commands = $driver->buildDumpCommand(
        '/tmp/output.sql',
        '--single-transaction',
        [],
        [],
        ['failed_jobs'],
        'mydb',
    );

    // Should have 2 commands: one for data (excluding schema-only tables), one for schema-only
    expect($commands)->toHaveCount(2);
    expect($commands[0])->toContain('--ignore-table=mydb.failed_jobs');
    expect($commands[1])->toContain('--no-data');
    expect($commands[1])->toContain('failed_jobs');
    expect($commands[1])->toContain('>> /tmp/output.sql');
});

test('mysql driver builds load command', function () {
    $driver = new MysqlDriver();

    $command = $driver->buildLoadCommand('/tmp/dump.sql.gz', 'mydb');

    expect($command)->toContain('mysql');
    expect($command)->toContain('--defaults-extra-file={credentials_file}');
    expect($command)->toContain('mydb');
});

test('mysql driver writes and cleans up credentials', function () {
    $driver = new MysqlDriver();
    $disk = Storage::disk('local');

    $dbConfig = [
        'host'     => '127.0.0.1',
        'port'     => '3306',
        'database' => 'testdb',
        'username' => 'root',
        'password' => 'secret',
    ];

    $replacements = $driver->writeCredentials($dbConfig, $disk);

    expect($replacements)->toHaveKey('{credentials_file}');
    expect($replacements)->toHaveKey('{database}');
    expect($replacements['{database}'])->toBe('testdb');
    expect($disk->exists('db-snapshots-mysql-credentials.txt'))->toBeTrue();

    // Verify credentials content
    $content = $disk->get('db-snapshots-mysql-credentials.txt');
    expect($content)->toContain('[client]');
    expect($content)->toContain("user = 'root'");
    expect($content)->toContain("password = 'secret'");
    expect($content)->toContain("host = '127.0.0.1'");
    expect($content)->toContain("port = '3306'");

    // Cleanup
    $driver->cleanupCredentials($disk);
    expect($disk->exists('db-snapshots-mysql-credentials.txt'))->toBeFalse();
});

test('mysql driver utilities list', function () {
    expect(MysqlDriver::utilities())->toBe(['mysqldump', 'mysql']);
});

// --- PostgresDriver tests ---

test('postgres driver builds dump command', function () {
    $driver = new PostgresDriver();

    $commands = $driver->buildDumpCommand(
        '/tmp/output.sql',
        '--no-owner --no-acl',
        [],
        [],
        [],
        'mydb',
    );

    expect($commands)->toHaveCount(1);
    expect($commands[0])->toContain('pg_dump');
    expect($commands[0])->toContain('PGPASSFILE={credentials_file}');
    expect($commands[0])->toContain('-h {host}');
    expect($commands[0])->toContain('-p {port}');
    expect($commands[0])->toContain('-U {username}');
    expect($commands[0])->toContain('--no-owner');
    expect($commands[0])->toContain('--no-acl');
    expect($commands[0])->toContain('mydb');
    expect($commands[0])->toContain('> /tmp/output.sql');
});

test('postgres driver builds dump command with ignore tables', function () {
    $driver = new PostgresDriver();

    $commands = $driver->buildDumpCommand(
        '/tmp/output.sql',
        '--no-owner',
        [],
        ['logs', 'sessions'],
        [],
        'mydb',
    );

    expect($commands)->toHaveCount(1);
    expect($commands[0])->toContain('--exclude-table=logs');
    expect($commands[0])->toContain('--exclude-table=sessions');
});

test('postgres driver builds dump command with schema only tables', function () {
    $driver = new PostgresDriver();

    $commands = $driver->buildDumpCommand(
        '/tmp/output.sql',
        '--no-owner',
        [],
        [],
        ['failed_jobs'],
        'mydb',
    );

    // Should have 2 commands: data excluding schema-only, then schema-only
    expect($commands)->toHaveCount(2);
    expect($commands[0])->toContain('--exclude-table=failed_jobs');
    expect($commands[1])->toContain('--schema-only');
    expect($commands[1])->toContain('-t failed_jobs');
    expect($commands[1])->toContain('>> /tmp/output.sql');
});

test('postgres driver builds load command', function () {
    $driver = new PostgresDriver();

    $command = $driver->buildLoadCommand('/tmp/dump.sql.gz', 'mydb');

    expect($command)->toContain('psql');
    expect($command)->toContain('PGPASSFILE={credentials_file}');
    expect($command)->toContain('-h {host}');
    expect($command)->toContain('-p {port}');
    expect($command)->toContain('-U {username}');
    expect($command)->toContain('mydb');
});

test('postgres driver writes and cleans up credentials', function () {
    $driver = new PostgresDriver();
    $disk = Storage::disk('local');

    $dbConfig = [
        'host'     => '127.0.0.1',
        'port'     => '5432',
        'database' => 'testdb',
        'username' => 'postgres',
        'password' => 'secret',
    ];

    $replacements = $driver->writeCredentials($dbConfig, $disk);

    expect($replacements)->toHaveKey('{credentials_file}');
    expect($replacements)->toHaveKey('{database}');
    expect($replacements)->toHaveKey('{host}');
    expect($replacements)->toHaveKey('{port}');
    expect($replacements)->toHaveKey('{username}');
    expect($replacements['{database}'])->toBe('testdb');
    expect($replacements['{host}'])->toBe('127.0.0.1');
    expect($replacements['{port}'])->toBe('5432');
    expect($replacements['{username}'])->toBe('postgres');
    expect($disk->exists('db-snapshots-pgpass.txt'))->toBeTrue();

    // Verify pgpass content format: hostname:port:database:username:password
    $content = $disk->get('db-snapshots-pgpass.txt');
    expect($content)->toBe('127.0.0.1:5432:testdb:postgres:secret');

    // Cleanup
    $driver->cleanupCredentials($disk);
    expect($disk->exists('db-snapshots-pgpass.txt'))->toBeFalse();
});

test('postgres driver utilities list', function () {
    expect(PostgresDriver::utilities())->toBe(['pg_dump', 'psql']);
});

// --- Auto-detection tests ---

test('auto-detection picks mysql driver for mysql connection', function () {
    config()->set('database.connections.mysql.driver', 'mysql');

    $plan = new SnapshotPlan('daily', defaultDailyConfig());
    $driver = $plan->getDriver();

    expect($driver)->toBeInstanceOf(MysqlDriver::class);
});

test('auto-detection picks postgres driver for pgsql connection', function () {
    config()->set('database.connections.pgsql', [
        'driver'   => 'pgsql',
        'host'     => '127.0.0.1',
        'port'     => '5432',
        'database' => 'testdb',
        'username' => 'postgres',
        'password' => 'secret',
    ]);

    $config = defaultDailyConfig();
    $config['connection'] = 'pgsql';

    $plan = new SnapshotPlan('daily', $config);
    $driver = $plan->getDriver();

    expect($driver)->toBeInstanceOf(PostgresDriver::class);
});

test('auto-detection throws for unsupported driver', function () {
    config()->set('database.connections.sqlite', [
        'driver'   => 'sqlite',
        'database' => ':memory:',
    ]);

    $config = defaultDailyConfig();
    $config['connection'] = 'sqlite';

    $plan = new SnapshotPlan('daily', $config);
    $plan->getDriver();
})->throws(RuntimeException::class, 'Unsupported database driver: sqlite');

// --- PostgreSQL snapshot creation with fake pg_dump ---

test('postgres driver creates snapshot with fakepgdump', function () {
    config()->set('database.connections.pgsql', [
        'driver'   => 'pgsql',
        'host'     => '127.0.0.1',
        'port'     => '5432',
        'database' => 'testdb',
        'username' => 'postgres',
        'password' => 'secret',
    ]);

    config()->set('db-snapshots.utilities.pgsql.pg_dump', __DIR__ . '/fixtures/fakepgdump');

    $config = defaultDailyConfig();
    $config['connection'] = 'pgsql';
    $config['file_template'] = 'db-snapshot-pg-daily-{date:Ymd}';

    $snapshotPlan = new SnapshotPlan('daily', $config);
    $snapshot = $snapshotPlan->create();

    $expectedFileName = 'db-snapshot-pg-daily-' . date('Ymd') . '.sql.gz';
    expect($snapshot->fileName)->toBe($expectedFileName);

    $archiveDisk = Storage::disk(config('db-snapshots.filesystem.archive_disk'));
    $files = $archiveDisk->allFiles(config('db-snapshots.filesystem.archive_path'));
    expect($files)->toHaveCount(1);
});
