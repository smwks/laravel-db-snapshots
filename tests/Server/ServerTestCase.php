<?php

namespace SMWks\LaravelDbSnapshots\Tests\Server;

use SMWks\LaravelDbSnapshots\DbSnapshotsServiceProvider;

// Pest's `uses(Class::class)->in(...)` mechanism throws TestCaseAlreadyInUse
// when two overlapping directory registrations both try to assign a real
// *class* to the same test file (root tests/Pest.php already binds the
// plain Orchestra\Testbench\TestCase to the whole tests/ tree, which
// recursively includes tests/Server/). Traits don't hit that conflict check
// — Pest just mixes them into the generated test case class — so this is a
// trait, mixed in via `uses(ServerTestCase::class)->in(__DIR__)` in
// tests/Server/Pest.php, providing the getEnvironmentSetUp() override on
// top of the root-bound TestCase.
trait ServerTestCase
{
    protected function getEnvironmentSetUp($app)
    {
        $app['config']->set('filesystems.disks.local.root', __DIR__.'/../fixtures/local-filesystem/');

        $app['config']->set('db-snapshots.server.enabled', true);
        $app['config']->set('db-snapshots.server.route_prefix', 'api/db-snapshots');
        $app['config']->set('db-snapshots.server.projects', [
            'my-app' => [
                'token' => 'my-app-token',
                'archive_disk' => 'local',
                'archive_path' => 'server-snapshots/my-app',
            ],
        ]);
    }

    // The rest of this suite never registers the package's own
    // ServiceProvider (config is poked directly in tests/Pest.php instead),
    // so it has never needed this. This directory is the first to depend on
    // the service provider's actual boot() behavior (conditional route
    // registration), which requires the provider to really be loaded.
    protected function getPackageProviders($app)
    {
        return [DbSnapshotsServiceProvider::class];
    }
}
