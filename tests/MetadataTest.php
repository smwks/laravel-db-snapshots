<?php

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
