<?php

use Illuminate\Support\Facades\Storage;
use SMWks\LaravelDbSnapshots\Stores\FilesystemSnapshotStore;

function makeFilesystemStore(): FilesystemSnapshotStore
{
    return new FilesystemSnapshotStore(
        Storage::disk(config('db-snapshots.filesystem.archive_disk')),
        config('db-snapshots.filesystem.archive_path')
    );
}

test('publish writes the file and a metadata sidecar', function () {
    $store = makeFilesystemStore();

    $localFile = tempnam(sys_get_temp_dir(), 'snapshot');
    file_put_contents($localFile, 'fake snapshot bytes');

    $store->publish('db-snapshot-daily-20250209.sql.gz', $localFile, ['size' => 19, 'tags' => []]);

    unlink($localFile);

    $disk = Storage::disk(config('db-snapshots.filesystem.archive_disk'));
    $path = config('db-snapshots.filesystem.archive_path');

    expect($disk->exists("{$path}/db-snapshot-daily-20250209.sql.gz"))->toBeTrue();
    expect($disk->exists("{$path}/db-snapshot-daily-20250209.sql.gz.json"))->toBeTrue();
    expect(json_decode($disk->get("{$path}/db-snapshot-daily-20250209.sql.gz.json"), true))->toBe(['size' => 19, 'tags' => []]);
});

test('allFiles excludes metadata sidecar files', function () {
    $store = makeFilesystemStore();

    $localFile = tempnam(sys_get_temp_dir(), 'snapshot');
    file_put_contents($localFile, 'fake snapshot bytes');

    $store->publish('db-snapshot-daily-20250209.sql.gz', $localFile, ['size' => 19]);

    unlink($localFile);

    expect($store->allFiles())->toBe(['db-snapshot-daily-20250209.sql.gz']);
});

test('exists, size, get, and delete operate on the snapshot file', function () {
    $store = makeFilesystemStore();

    $localFile = tempnam(sys_get_temp_dir(), 'snapshot');
    file_put_contents($localFile, 'fake snapshot bytes');

    $store->publish('db-snapshot-daily-20250209.sql.gz', $localFile, ['size' => 19]);

    unlink($localFile);

    expect($store->exists('db-snapshot-daily-20250209.sql.gz'))->toBeTrue();
    expect($store->size('db-snapshot-daily-20250209.sql.gz'))->toBe(19);
    expect($store->get('db-snapshot-daily-20250209.sql.gz'))->toBe('fake snapshot bytes');
    expect($store->metadata('db-snapshot-daily-20250209.sql.gz'))->toBe(['size' => 19]);

    $store->delete('db-snapshot-daily-20250209.sql.gz');

    expect($store->exists('db-snapshot-daily-20250209.sql.gz'))->toBeFalse();
    expect($store->metadata('db-snapshot-daily-20250209.sql.gz'))->toBeNull();
});

test('readStream returns a readable resource', function () {
    $store = makeFilesystemStore();

    $localFile = tempnam(sys_get_temp_dir(), 'snapshot');
    file_put_contents($localFile, 'fake snapshot bytes');

    $store->publish('db-snapshot-daily-20250209.sql.gz', $localFile, ['size' => 19]);

    unlink($localFile);

    $stream = $store->readStream('db-snapshot-daily-20250209.sql.gz');

    expect(stream_get_contents($stream))->toBe('fake snapshot bytes');

    fclose($stream);
});
