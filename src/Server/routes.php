<?php

use Illuminate\Support\Facades\Route;
use SMWks\LaravelDbSnapshots\Server\Http\Controllers\SnapshotServerController;
use SMWks\LaravelDbSnapshots\Server\Http\Middleware\AuthenticateSnapshotProject;

Route::prefix(config('db-snapshots.server.route_prefix', 'api/db-snapshots'))
    ->middleware([AuthenticateSnapshotProject::class])
    ->group(function () {
        Route::get('{project}/{plan}', [SnapshotServerController::class, 'index']);
        Route::get('{project}/{plan}/{file}', [SnapshotServerController::class, 'download']);
        Route::post('{project}/{plan}', [SnapshotServerController::class, 'store']);
        Route::delete('{project}/{plan}/{file}', [SnapshotServerController::class, 'destroy']);
    });
