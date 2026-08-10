<?php

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use SMWks\LaravelDbSnapshots\Server\Events\SnapshotListed;
use SMWks\LaravelDbSnapshots\Server\ProjectResolver;
use SMWks\LaravelDbSnapshots\Server\ServerProject;

class InMemoryProjectResolver implements ProjectResolver
{
    public function resolve(string $project): ?ServerProject
    {
        if ($project !== 'db-backed-app') {
            return null;
        }

        return new ServerProject(
            name: 'db-backed-app',
            token: 'db-backed-token',
            archiveDisk: 'local',
            archivePath: 'server-snapshots/db-backed-app',
        );
    }
}

test('a custom ProjectResolver binding is used instead of the config default', function () {
    app()->bind(ProjectResolver::class, InMemoryProjectResolver::class);

    Storage::disk('local')->put('server-snapshots/db-backed-app/daily/db-snapshot-daily-20250209.sql.gz', 'fake bytes');

    Event::fake([SnapshotListed::class]);

    $response = $this->withToken('db-backed-token')
        ->getJson('/api/db-snapshots/db-backed-app/daily');

    $response->assertOk();
    $response->assertJson([
        'snapshots' => [
            ['file' => 'db-snapshot-daily-20250209.sql.gz', 'metadata' => null],
        ],
    ]);

    Event::assertDispatched(
        SnapshotListed::class,
        fn (SnapshotListed $event) => $event->project === 'db-backed-app'
    );
});

test('a project the custom resolver does not recognize still 404s, ignoring config', function () {
    app()->bind(ProjectResolver::class, InMemoryProjectResolver::class);

    // 'my-app' exists in config('db-snapshots.server.projects') per ServerTestCase,
    // but InMemoryProjectResolver only knows 'db-backed-app' — proving the binding
    // fully replaces the config lookup rather than falling back to it.
    $response = $this->withToken('my-app-token')
        ->getJson('/api/db-snapshots/my-app/daily');

    $response->assertStatus(404);
});
