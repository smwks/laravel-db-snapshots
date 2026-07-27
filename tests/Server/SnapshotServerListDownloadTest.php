<?php

use Illuminate\Support\Facades\Storage;

test('unauthenticated requests are rejected', function () {
    $response = $this->getJson('/api/db-snapshots/my-app/daily');

    $response->assertStatus(401);
});

test('unknown project returns 404', function () {
    $response = $this->withToken('my-app-token')
        ->getJson('/api/db-snapshots/unknown-project/daily');

    $response->assertStatus(404);
});

test('wrong token returns 401', function () {
    $response = $this->withToken('wrong-token')
        ->getJson('/api/db-snapshots/my-app/daily');

    $response->assertStatus(401);
});

test('index lists snapshots with metadata for the project and plan', function () {
    Storage::disk('local')->put('server-snapshots/my-app/daily/db-snapshot-daily-20250209.sql.gz', 'fake bytes');
    Storage::disk('local')->put('server-snapshots/my-app/daily/db-snapshot-daily-20250209.sql.gz.json', json_encode(['size' => 10]));

    $response = $this->withToken('my-app-token')
        ->getJson('/api/db-snapshots/my-app/daily');

    $response->assertOk();
    $response->assertJson([
        'snapshots' => [
            ['file' => 'db-snapshot-daily-20250209.sql.gz', 'metadata' => ['size' => 10]],
        ],
    ]);
});

test('download streams the file directly when the disk has no native temporary URL', function () {
    Storage::disk('local')->put('server-snapshots/my-app/daily/db-snapshot-daily-20250209.sql.gz', 'fake bytes');

    $response = $this->withToken('my-app-token')
        ->get('/api/db-snapshots/my-app/daily/db-snapshot-daily-20250209.sql.gz');

    $response->assertOk();
    expect($response->streamedContent())->toBe('fake bytes');
});

test('download returns a 302 redirect when the disk provides temporary URLs', function () {
    Storage::disk('local')->put('server-snapshots/my-app/daily/db-snapshot-daily-20250209.sql.gz', 'fake bytes');

    // Laravel's real 'buildTemporaryUrlsUsing' hook — no mocking library needed.
    // Once set, FilesystemAdapter::providesTemporaryUrls() reports true and
    // temporaryUrl() calls this callback, exactly like wiring a real S3 disk
    // would in production.
    Storage::disk('local')->buildTemporaryUrlsUsing(
        fn ($path, $expiration) => 'https://bucket.s3.amazonaws.com/signed-url'
    );

    $response = $this->withToken('my-app-token')
        ->get('/api/db-snapshots/my-app/daily/db-snapshot-daily-20250209.sql.gz');

    $response->assertRedirect('https://bucket.s3.amazonaws.com/signed-url');
});

test('download returns 404 for a missing file', function () {
    $response = $this->withToken('my-app-token')
        ->get('/api/db-snapshots/my-app/daily/missing.sql.gz');

    $response->assertStatus(404);
});

test('download rejects a path-traversal file segment using backslash-encoded dot-dot', function () {
    // A sibling project's file, sitting outside the 'my-app' project's own
    // archive directory. If traversal succeeded, this is what would leak.
    Storage::disk('local')->put('server-snapshots/other-app/daily/secret.sql.gz', 'other project bytes');

    $response = $this->withToken('my-app-token')
        ->get('/api/db-snapshots/my-app/daily/..%5C..%5Cother-app%5Cdaily%5Csecret.sql.gz');

    // Rejected by the route's character-class constraint (backslash isn't
    // permitted in {file}) before the controller even runs; if the
    // constraint were ever loosened, the controller's own basename() guard
    // (SnapshotServerController::assertSafeFileSegment) would reject it too.
    $response->assertStatus(404);
    expect($response->getContent())->not->toBe('other project bytes');
});

test('destroy rejects a path-traversal file segment using backslash-encoded dot-dot', function () {
    Storage::disk('local')->put('server-snapshots/other-app/daily/secret.sql.gz', 'other project bytes');

    $response = $this->withToken('my-app-token')
        ->delete('/api/db-snapshots/my-app/daily/..%5C..%5Cother-app%5Cdaily%5Csecret.sql.gz');

    $response->assertStatus(404);

    // The sibling project's file must survive untouched.
    expect(Storage::disk('local')->exists('server-snapshots/other-app/daily/secret.sql.gz'))->toBeTrue();
});

test('index rejects a path-traversal plan segment of ".."', function () {
    // A sibling project's directory listing, which a plan of ".." would
    // otherwise expose (listing the parent "my-app" project directory).
    Storage::disk('local')->put('server-snapshots/my-app/other-plan/secret.sql.gz', 'other plan bytes');

    $response = $this->withToken('my-app-token')
        ->getJson('/api/db-snapshots/my-app/..');

    $response->assertStatus(404);
});
