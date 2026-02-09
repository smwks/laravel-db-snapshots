<?php

use Illuminate\Support\Facades\Storage;
use SMWks\LaravelDbSnapshots\SnapshotPlan;

uses(Orchestra\Testbench\TestCase::class)->in(__DIR__);

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
    $archiveDisk = Storage::disk(config('db-snapshots.filesystem.archive_disk'));

    foreach ($archiveDisk->allFiles(config('db-snapshots.filesystem.archive_path')) as $file) {
        $archiveDisk->delete($file);
    }

    $localDisk = Storage::disk(config('db-snapshots.filesystem.local_disk'));

    foreach ($localDisk->allFiles(config('db-snapshots.filesystem.local_path')) as $file) {
        $localDisk->delete($file);
    }

    $localDisk->delete('fakemysqldump-arguments.txt');
    $localDisk->delete('fakepgdump-arguments.txt');
    $localDisk->delete('fakepsql-arguments.txt');
}
