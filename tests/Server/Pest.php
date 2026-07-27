<?php

use Illuminate\Support\Facades\Storage;
use SMWks\LaravelDbSnapshots\Tests\Server\ServerTestCase;

uses(ServerTestCase::class)->in(__DIR__);

uses()
    ->beforeEach(function () {
        // The root tests/Pest.php beforeEach (which also applies here) reloads
        // the whole 'db-snapshots' config array from the default config file,
        // wiping the server.* values set in ServerTestCase::getEnvironmentSetUp().
        // Re-apply them for the actual test body.
        config()->set('db-snapshots.server.enabled', true);
        config()->set('db-snapshots.server.route_prefix', 'api/db-snapshots');
        config()->set('db-snapshots.server.projects', [
            'my-app' => [
                'token' => 'my-app-token',
                'archive_disk' => 'local',
                'archive_path' => 'server-snapshots/my-app',
            ],
        ]);
    })
    ->afterEach(function () {
        Storage::disk('local')->deleteDirectory('server-snapshots');
    })
    ->in(__DIR__);
