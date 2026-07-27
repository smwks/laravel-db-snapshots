<?php

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

test('store uploads the file and metadata sidecar', function () {
    $file = UploadedFile::fake()->createWithContent('db-snapshot-daily-20250209.sql.gz', 'fake snapshot bytes');

    $response = $this->withToken('my-app-token')
        ->post('/api/db-snapshots/my-app/daily', [
            'file' => $file,
            'metadata' => json_encode(['size' => 19, 'tags' => []]),
        ]);

    $response->assertCreated();
    $response->assertJson(['file' => 'db-snapshot-daily-20250209.sql.gz']);

    expect(Storage::disk('local')->get('server-snapshots/my-app/daily/db-snapshot-daily-20250209.sql.gz'))->toBe('fake snapshot bytes');
    expect(json_decode(Storage::disk('local')->get('server-snapshots/my-app/daily/db-snapshot-daily-20250209.sql.gz.json'), true))->toBe(['size' => 19, 'tags' => []]);
});

test('store returns a server error when the disk write fails', function () {
    $file = UploadedFile::fake()->createWithContent('db-snapshot-daily-20250209.sql.gz', 'fake snapshot bytes');

    // Laravel's FilesystemAdapter::put() returns false (rather than
    // throwing) by default when the underlying write fails, e.g. disk full
    // or an S3 outage. Simulate that here to prove the controller checks
    // the return value instead of always answering 201.
    // Storage::disk('local') is also the disk the surrounding test suite's
    // beforeEach/afterEach cleanup hooks use (cleanupFiles(), and this
    // directory's own ServerTestCase teardown), so those calls need stubs
    // too, or they'll blow up against this mock once it's bound.
    $mockDisk = Mockery::mock(FilesystemAdapter::class);
    $mockDisk->shouldReceive('put')->once()->andReturn(false);
    $mockDisk->shouldReceive('allFiles')->andReturn([]);
    $mockDisk->shouldReceive('delete')->andReturn(true);
    $mockDisk->shouldReceive('deleteDirectory')->andReturn(true);

    Storage::shouldReceive('disk')->with('local')->andReturn($mockDisk);

    $response = $this->withToken('my-app-token')
        ->post('/api/db-snapshots/my-app/daily', [
            'file' => $file,
            'metadata' => json_encode(['size' => 19]),
        ]);

    $response->assertStatus(500);
});

test('store rejects unauthenticated uploads', function () {
    $file = UploadedFile::fake()->createWithContent('db-snapshot-daily-20250209.sql.gz', 'fake snapshot bytes');

    $response = $this->post('/api/db-snapshots/my-app/daily', [
        'file' => $file,
        'metadata' => json_encode(['size' => 19]),
    ]);

    $response->assertStatus(401);
});

test('destroy removes the file and metadata sidecar', function () {
    Storage::disk('local')->put('server-snapshots/my-app/daily/db-snapshot-daily-20250209.sql.gz', 'fake bytes');
    Storage::disk('local')->put('server-snapshots/my-app/daily/db-snapshot-daily-20250209.sql.gz.json', json_encode(['size' => 10]));

    $response = $this->withToken('my-app-token')
        ->delete('/api/db-snapshots/my-app/daily/db-snapshot-daily-20250209.sql.gz');

    $response->assertNoContent();

    expect(Storage::disk('local')->exists('server-snapshots/my-app/daily/db-snapshot-daily-20250209.sql.gz'))->toBeFalse();
    expect(Storage::disk('local')->exists('server-snapshots/my-app/daily/db-snapshot-daily-20250209.sql.gz.json'))->toBeFalse();
});
