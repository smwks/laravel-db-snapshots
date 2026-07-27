<?php

use Illuminate\Support\Facades\Http;
use SMWks\LaravelDbSnapshots\SnapshotPlan;
use SMWks\LaravelDbSnapshots\Stores\RemoteSnapshotStore;

test('snapshot plan uses a RemoteSnapshotStore when archive_disk is remote', function () {
    config()->set('db-snapshots.filesystem.archive_disk', 'remote');
    config()->set('db-snapshots.remote', [
        'endpoint' => 'https://hub.example.com/api/db-snapshots',
        'project' => 'my-app',
        'token' => 'secret-token',
        'timeout' => 300,
    ]);

    $snapshotPlan = new SnapshotPlan('daily', defaultDailyConfig());

    expect($snapshotPlan->archiveStore)->toBeInstanceOf(RemoteSnapshotStore::class);
});

test('SnapshotPlan::all() lists snapshots per-plan from the remote endpoint without cross-plan matching', function () {
    config()->set('db-snapshots.filesystem.archive_disk', 'remote');
    config()->set('db-snapshots.remote', [
        'endpoint' => 'https://hub.example.com/api/db-snapshots',
        'project' => 'my-app',
        'token' => 'secret-token',
        'timeout' => 300,
    ]);

    config()->set('db-snapshots.plans', [
        'daily' => defaultDailyConfig(),
    ]);

    Http::fake([
        'hub.example.com/api/db-snapshots/my-app/daily' => Http::response([
            'snapshots' => [
                ['file' => 'db-snapshot-daily-20250209.sql.gz', 'metadata' => ['size' => 100]],
            ],
        ], 200),
    ]);

    $plans = SnapshotPlan::all();
    $dailyPlan = $plans->firstWhere('name', 'daily');

    expect($dailyPlan->snapshots)->toHaveCount(1);
    expect($dailyPlan->snapshots->first()->fileName)->toBe('db-snapshot-daily-20250209.sql.gz');
});
