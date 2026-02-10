<?php

namespace SMWks\LaravelDbSnapshots;

use Illuminate\Support\ServiceProvider;

class DbSnapshotsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/db-snapshots.php', 'db-snapshots');

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
    }
}
