<?php

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
