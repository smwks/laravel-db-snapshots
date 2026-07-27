<?php

use Illuminate\Support\Facades\Route;
use SMWks\LaravelDbSnapshots\Server\Http\Controllers\SnapshotServerController;
use SMWks\LaravelDbSnapshots\Server\Http\Middleware\AuthenticateSnapshotProject;

Route::prefix(config('db-snapshots.server.route_prefix', 'api/db-snapshots'))
    ->middleware([AuthenticateSnapshotProject::class])
    ->where([
        'project' => '[A-Za-z0-9][A-Za-z0-9._-]*',
        'plan' => '[A-Za-z0-9][A-Za-z0-9._-]*',
        'file' => '[A-Za-z0-9][A-Za-z0-9._-]*',
    ])
    ->group(function () {
        Route::get('{project}/{plan}', [SnapshotServerController::class, 'index']);
        Route::get('{project}/{plan}/{file}', [SnapshotServerController::class, 'download']);
        Route::post('{project}/{plan}', [SnapshotServerController::class, 'store']);
        Route::delete('{project}/{plan}/{file}', [SnapshotServerController::class, 'destroy']);
    });
