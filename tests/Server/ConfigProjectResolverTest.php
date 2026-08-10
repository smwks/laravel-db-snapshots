<?php

use SMWks\LaravelDbSnapshots\Server\ConfigProjectResolver;
use SMWks\LaravelDbSnapshots\Server\ProjectResolver;

test('resolve returns a ServerProject for a configured project', function () {
    config()->set('db-snapshots.server.projects', [
        'my-app' => [
            'token' => 'my-app-token',
            'archive_disk' => 'local',
            'archive_path' => 'server-snapshots/my-app',
        ],
    ]);

    $project = (new ConfigProjectResolver)->resolve('my-app');

    expect($project->name)->toBe('my-app');
    expect($project->token)->toBe('my-app-token');
    expect($project->archiveDisk)->toBe('local');
    expect($project->archivePath)->toBe('server-snapshots/my-app');
});

test('resolve returns null for an unconfigured project', function () {
    config()->set('db-snapshots.server.projects', []);

    expect((new ConfigProjectResolver)->resolve('unknown'))->toBeNull();
});

test('the container resolves ProjectResolver to ConfigProjectResolver by default', function () {
    expect(app(ProjectResolver::class))->toBeInstanceOf(ConfigProjectResolver::class);
});
