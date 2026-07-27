<?php

use Illuminate\Support\Facades\Storage;
use Orchestra\Testbench\TestCase;
use SMWks\LaravelDbSnapshots\SnapshotPlan;

uses(TestCase::class)->in(__DIR__);

uses()
    ->beforeEach(function () {
        config()->set('filesystems.disks.local.root', __DIR__.'/fixtures/local-filesystem/');

        config()->set('db-snapshots', include __DIR__.'/../config/db-snapshots.php');

        config()->set('db-snapshots.filesystem.archive_disk', 'local');
        config()->set('db-snapshots.filesystem.archive_path', 'cloud-snapshots');
        config()->set('db-snapshots.utilities.mysql.mysqldump', __DIR__.'/fixtures/fakemysqldump');

        cleanupFiles();

        SnapshotPlan::$unacceptedFiles = [];
    })
    ->afterEach(function () {
        cleanupFiles();
    })
    ->in(__DIR__);

// Pest only auto-loads the root tests/Pest.php file — it does not discover
// nested Pest.php files in subdirectories on its own. tests/Server/Pest.php
// binds its own underlying test case (ServerTestCase) and hooks for
// tests/Server/*, so it must be required explicitly here (after the uses()
// calls above) for that directory-scoped configuration to take effect.
require __DIR__.'/Server/Pest.php';

function defaultDailyConfig(): array
{
    return [
        'connection' => 'mysql',
        'file_template' => 'db-snapshot-daily-{date:Ymd}',
        'dump_options' => '--single-transaction',
        'keep_last' => 2,
        'environment_locks' => [
            'create' => 'production',
            'load' => 'local',
        ],
    ];
}

function cleanupFiles(): void
{
    if (config('db-snapshots.filesystem.archive_disk') !== 'remote') {
        $archiveDisk = Storage::disk(config('db-snapshots.filesystem.archive_disk'));

        foreach ($archiveDisk->allFiles(config('db-snapshots.filesystem.archive_path')) as $file) {
            $archiveDisk->delete($file);
        }
    }

    $localDisk = Storage::disk(config('db-snapshots.filesystem.local_disk'));

    foreach ($localDisk->allFiles(config('db-snapshots.filesystem.local_path')) as $file) {
        $localDisk->delete($file);
    }

    $localDisk->delete('fakemysqldump-arguments.txt');
    $localDisk->delete('fakepgdump-arguments.txt');
    $localDisk->delete('fakepsql-arguments.txt');
}
