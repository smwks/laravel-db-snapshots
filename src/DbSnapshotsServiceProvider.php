<?php

namespace SMWks\LaravelDbSnapshots;

use Illuminate\Support\ServiceProvider;
use SMWks\LaravelDbSnapshots\Server\ConfigProjectResolver;
use SMWks\LaravelDbSnapshots\Server\ProjectResolver;

class DbSnapshotsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/db-snapshots.php', 'db-snapshots');

        $this->app->singletonIf(ProjectResolver::class, ConfigProjectResolver::class);

        if ($this->app->runningInConsole()) {
            $this->commands([
                Commands\DeleteCommand::class,
                Commands\ClearCacheCommand::class,
                Commands\CreateCommand::class,
                Commands\ListCommand::class,
                Commands\LoadCommand::class,
            ]);
        }
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/db-snapshots.php' => config_path('db-snapshots.php'),
        ], 'config');

        if (config('db-snapshots.server.enabled')) {
            $this->loadRoutesFrom(__DIR__.'/Server/routes.php');
        }
    }
}
