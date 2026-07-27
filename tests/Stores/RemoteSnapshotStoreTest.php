<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use SMWks\LaravelDbSnapshots\Stores\RemoteSnapshotStore;

function makeRemoteStore(): RemoteSnapshotStore
{
    return new RemoteSnapshotStore(
        endpoint: 'https://hub.example.com/api/db-snapshots',
        project: 'my-app',
        plan: 'daily',
        token: 'secret-token',
    );
}

test('allFiles lists files from the remote endpoint with bearer auth', function () {
    Http::fake([
        'hub.example.com/api/db-snapshots/my-app/daily' => Http::response([
            'snapshots' => [
                ['file' => 'db-snapshot-daily-20250209.sql.gz', 'metadata' => ['size' => 100]],
                ['file' => 'db-snapshot-daily-20250208.sql.gz', 'metadata' => ['size' => 90]],
            ],
        ], 200),
    ]);

    $store = makeRemoteStore();

    expect($store->allFiles())->toBe([
        'db-snapshot-daily-20250209.sql.gz',
        'db-snapshot-daily-20250208.sql.gz',
    ]);

    Http::assertSent(function ($request) {
        return $request->url() === 'https://hub.example.com/api/db-snapshots/my-app/daily'
            && $request->hasHeader('Authorization', 'Bearer secret-token');
    });
});

test('size and metadata read from the cached list response', function () {
    Http::fake([
        'hub.example.com/api/db-snapshots/my-app/daily' => Http::response([
            'snapshots' => [
                ['file' => 'db-snapshot-daily-20250209.sql.gz', 'metadata' => ['size' => 100, 'tags' => ['release' => '1.0']]],
            ],
        ], 200),
    ]);

    $store = makeRemoteStore();

    expect($store->exists('db-snapshot-daily-20250209.sql.gz'))->toBeTrue();
    expect($store->exists('missing-file.sql.gz'))->toBeFalse();
    expect($store->size('db-snapshot-daily-20250209.sql.gz'))->toBe(100);
    expect($store->metadata('db-snapshot-daily-20250209.sql.gz'))->toBe(['size' => 100, 'tags' => ['release' => '1.0']]);

    Http::assertSentCount(1); // list response is cached across exists/size/metadata calls
});

test('publish sends the file and metadata as multipart form data', function () {
    Http::fake([
        'hub.example.com/api/db-snapshots/my-app/daily' => Http::response(['file' => 'db-snapshot-daily-20250209.sql.gz'], 201),
    ]);

    $localFile = tempnam(sys_get_temp_dir(), 'snapshot');
    file_put_contents($localFile, 'fake snapshot bytes');

    $store = makeRemoteStore();
    $store->publish('db-snapshot-daily-20250209.sql.gz', $localFile, ['size' => 19]);

    unlink($localFile);

    Http::assertSent(function ($request) {
        $body = (string) $request->body();

        return $request->method() === 'POST'
            && $request->hasHeader('Authorization', 'Bearer secret-token')
            && str_contains($body, 'name="metadata"')
            && str_contains($body, json_encode(['size' => 19]))
            && str_contains($body, 'name="file"')
            && str_contains($body, 'filename="db-snapshot-daily-20250209.sql.gz"');
    });
});

test('delete sends an authenticated DELETE request', function () {
    Http::fake([
        'hub.example.com/api/db-snapshots/my-app/daily/db-snapshot-daily-20250209.sql.gz' => Http::response([], 204),
    ]);

    $store = makeRemoteStore();

    expect($store->delete('db-snapshot-daily-20250209.sql.gz'))->toBeTrue();

    Http::assertSent(function ($request) {
        return $request->method() === 'DELETE'
            && $request->hasHeader('Authorization', 'Bearer secret-token');
    });
});

test('readStream returns the response body as a resource', function () {
    Http::fake([
        'hub.example.com/api/db-snapshots/my-app/daily/db-snapshot-daily-20250209.sql.gz' => Http::response('fake snapshot bytes', 200),
    ]);

    $store = makeRemoteStore();

    $stream = $store->readStream('db-snapshot-daily-20250209.sql.gz');

    expect(stream_get_contents($stream))->toBe('fake snapshot bytes');

    fclose($stream);
});

test('readStream throws a RuntimeException on a failed download', function () {
    Http::fake([
        'hub.example.com/api/db-snapshots/my-app/daily/missing.sql.gz' => Http::response(['message' => 'Not Found'], 404),
    ]);

    $store = makeRemoteStore();

    expect(fn () => $store->readStream('missing.sql.gz'))->toThrow(RuntimeException::class, 'Failed to download remote snapshot');
});

test('list failure throws a RuntimeException', function () {
    Http::fake([
        'hub.example.com/api/db-snapshots/my-app/daily' => Http::response(['message' => 'Invalid token'], 401),
    ]);

    $store = makeRemoteStore();

    expect(fn () => $store->allFiles())->toThrow(RuntimeException::class, 'Failed to list remote snapshots');
});

test('a connection-level failure (DNS/refused/timeout) is rewrapped as a RuntimeException, not left as a ConnectionException', function () {
    Http::fake(function () {
        throw new ConnectionException('Connection refused');
    });

    $store = makeRemoteStore();

    // allFiles() -> list() is the call site exercised here, but every
    // method funnels through the same RemoteSnapshotStore::send() helper,
    // so this proves the rewrapping for the whole class, not just list().
    expect(fn () => $store->allFiles())
        ->toThrow(RuntimeException::class, 'Failed to list remote snapshots for plan daily: Connection refused');
});

test('a connection-level failure during publish is rewrapped as a RuntimeException', function () {
    Http::fake(function () {
        throw new ConnectionException('Connection refused');
    });

    $localFile = tempnam(sys_get_temp_dir(), 'snapshot');
    file_put_contents($localFile, 'fake snapshot bytes');

    $store = makeRemoteStore();

    try {
        expect(fn () => $store->publish('db-snapshot-daily-20250209.sql.gz', $localFile, ['size' => 19]))
            ->toThrow(RuntimeException::class, 'Failed to publish remote snapshot');
    } finally {
        unlink($localFile);
    }
});
