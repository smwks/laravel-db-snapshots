<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use SMWks\LaravelDbSnapshots\Server\Events\SnapshotDeleted;
use SMWks\LaravelDbSnapshots\Server\Events\SnapshotDownloaded;
use SMWks\LaravelDbSnapshots\Server\Events\SnapshotListed;
use SMWks\LaravelDbSnapshots\Server\Events\SnapshotUploaded;

test('listing fires SnapshotListed', function () {
    Event::fake([SnapshotListed::class]);

    $this->withToken('my-app-token')->getJson('/api/db-snapshots/my-app/daily');

    Event::assertDispatched(
        SnapshotListed::class,
        fn (SnapshotListed $event) => $event->project === 'my-app' && $event->plan === 'daily'
    );
});

test('an unauthenticated list request does not fire SnapshotListed', function () {
    Event::fake([SnapshotListed::class]);

    $this->getJson('/api/db-snapshots/my-app/daily');

    Event::assertNotDispatched(SnapshotListed::class);
});

test('downloading a snapshot fires SnapshotDownloaded', function () {
    Storage::disk('local')->put('server-snapshots/my-app/daily/db-snapshot-daily-20250209.sql.gz', 'fake bytes');

    Event::fake([SnapshotDownloaded::class]);

    $this->withToken('my-app-token')->get('/api/db-snapshots/my-app/daily/db-snapshot-daily-20250209.sql.gz');

    Event::assertDispatched(
        SnapshotDownloaded::class,
        fn (SnapshotDownloaded $event) => $event->project === 'my-app'
            && $event->plan === 'daily'
            && $event->file === 'db-snapshot-daily-20250209.sql.gz'
    );
});

test('downloading a missing snapshot does not fire SnapshotDownloaded', function () {
    Event::fake([SnapshotDownloaded::class]);

    $this->withToken('my-app-token')->get('/api/db-snapshots/my-app/daily/missing.sql.gz');

    Event::assertNotDispatched(SnapshotDownloaded::class);
});

test('uploading a snapshot fires SnapshotUploaded', function () {
    Event::fake([SnapshotUploaded::class]);

    $file = UploadedFile::fake()->createWithContent('db-snapshot-daily-20250209.sql.gz', 'fake snapshot bytes');

    $this->withToken('my-app-token')->post('/api/db-snapshots/my-app/daily', [
        'file' => $file,
        'metadata' => json_encode(['size' => 19]),
    ]);

    Event::assertDispatched(
        SnapshotUploaded::class,
        fn (SnapshotUploaded $event) => $event->project === 'my-app'
            && $event->plan === 'daily'
            && $event->file === 'db-snapshot-daily-20250209.sql.gz'
    );
});

test('deleting a snapshot fires SnapshotDeleted', function () {
    Storage::disk('local')->put('server-snapshots/my-app/daily/db-snapshot-daily-20250209.sql.gz', 'fake bytes');

    Event::fake([SnapshotDeleted::class]);

    $this->withToken('my-app-token')->delete('/api/db-snapshots/my-app/daily/db-snapshot-daily-20250209.sql.gz');

    Event::assertDispatched(
        SnapshotDeleted::class,
        fn (SnapshotDeleted $event) => $event->project === 'my-app'
            && $event->plan === 'daily'
            && $event->file === 'db-snapshot-daily-20250209.sql.gz'
    );
});
