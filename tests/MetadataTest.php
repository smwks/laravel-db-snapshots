<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use SMWks\LaravelDbSnapshots\Drivers\MysqlDriver;
use SMWks\LaravelDbSnapshots\SnapshotPlan;

test('create publishes metadata sidecar with snapshot facts and identity', function () {
    config()->set('db-snapshots.identity.app', 'my-app');
    config()->set('db-snapshots.identity.app_version', '2.4.1');

    $snapshotPlan = new SnapshotPlan('daily', defaultDailyConfig());
    $snapshot = $snapshotPlan->create();

    $metadata = $snapshotPlan->archiveStore->metadata($snapshot->fileName);

    expect($metadata['app'])->toBe('my-app');
    expect($metadata['app_version'])->toBe('2.4.1');
    expect($metadata['environment'])->toBe(app()->environment());
    expect($metadata['php_version'])->toBe(PHP_VERSION);
    expect($metadata['driver'])->toBe(MysqlDriver::class);
    expect($metadata['size'])->toBeGreaterThan(0);
    expect($metadata['checksum'])->toStartWith('sha256:');
    expect($metadata['duration_seconds'])->toBeGreaterThanOrEqual(0);
    expect($metadata['tags'])->toBe([]);
});

test('metadata tables reflect explicit table config without a database connection', function () {
    $config = defaultDailyConfig();
    $config['tables'] = ['users', 'posts'];

    $snapshotPlan = new SnapshotPlan('daily', $config);
    $snapshot = $snapshotPlan->create();

    $metadata = $snapshotPlan->archiveStore->metadata($snapshot->fileName);

    expect($metadata['tables'])->toBe(['users', 'posts']);
    expect($metadata['table_count'])->toBe(2);
    expect($metadata['row_counts'])->toBeNull();
});

test('metadata table_count is null when dumping all tables without capture_row_counts', function () {
    $snapshotPlan = new SnapshotPlan('daily', defaultDailyConfig());
    $snapshot = $snapshotPlan->create();

    $metadata = $snapshotPlan->archiveStore->metadata($snapshot->fileName);

    expect($metadata['tables'])->toBe([]);
    expect($metadata['table_count'])->toBeNull();
    expect($metadata['row_counts'])->toBeNull();
});

test('plan tags are included in metadata', function () {
    $config = defaultDailyConfig();
    $config['tags'] = ['release' => '2.4.1'];

    $snapshotPlan = new SnapshotPlan('daily', $config);
    $snapshot = $snapshotPlan->create();

    $metadata = $snapshotPlan->archiveStore->metadata($snapshot->fileName);

    expect($metadata['tags'])->toBe(['release' => '2.4.1']);
});

test('metadata app falls back to config app.name when identity.app is not set', function () {
    config()->set('db-snapshots.identity.app', null);

    $snapshotPlan = new SnapshotPlan('daily', defaultDailyConfig());
    $snapshot = $snapshotPlan->create();

    $metadata = $snapshotPlan->archiveStore->metadata($snapshot->fileName);

    expect($metadata['app'])->toBe(config('app.name'));
});

test('metadata reflects real resolved tables and row counts when capture_row_counts is enabled', function () {
    // The dump/gzip pipeline still runs through the faked mysqldump binary
    // (per tests/Pest.php's beforeEach), so the plan's connection must keep
    // a 'driver' of 'mysql' for SnapshotPlan::getDriver() to pick MysqlDriver.
    // Only the *metadata resolution* step (Schema::connection()/DB::connection())
    // needs a real, working connection - so we register a dedicated connection
    // name that still reports driver 'mysql' to config(), but whose actual
    // connection is resolved (via DB::extend) to a real, working in-memory
    // SQLite database seeded with real tables and rows.
    config()->set('database.connections.capture_test', array_merge(
        config('database.connections.mysql'),
        ['database' => 'capture_test']
    ));

    DB::extend('capture_test', fn ($config, $name) => new SQLiteConnection(
        new PDO('sqlite::memory:'),
        $config['database'] ?? ':memory:',
        '',
        $config
    ));

    Schema::connection('capture_test')->create('users', function (Blueprint $table) {
        $table->string('name');
    });
    DB::connection('capture_test')->table('users')->insert([
        ['name' => 'alice'],
        ['name' => 'bob'],
        ['name' => 'carol'],
    ]);

    Schema::connection('capture_test')->create('posts', function (Blueprint $table) {
        $table->string('title');
    });
    DB::connection('capture_test')->table('posts')->insert([
        ['title' => 'first post'],
        ['title' => 'second post'],
    ]);

    $config = defaultDailyConfig();
    $config['connection'] = 'capture_test';
    $config['capture_row_counts'] = true;

    $snapshotPlan = new SnapshotPlan('daily', $config);
    $snapshot = $snapshotPlan->create();

    $metadata = $snapshotPlan->archiveStore->metadata($snapshot->fileName);

    expect($metadata['tables'])->toEqualCanonicalizing(['users', 'posts']);
    expect($metadata['table_count'])->toBe(2);
    // Use toEqual (non-strict) rather than toBe since Schema::getTables()
    // does not guarantee a stable table order across environments.
    expect($metadata['row_counts'])->toEqual([
        'users' => 3,
        'posts' => 2,
    ]);
});
