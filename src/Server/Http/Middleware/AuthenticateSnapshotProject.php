<?php

namespace SMWks\LaravelDbSnapshots\Server\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateSnapshotProject
{
    public function handle(Request $request, Closure $next): Response
    {
        $project = $request->route('project');

        $projectConfig = config("db-snapshots.server.projects.{$project}");

        if (! $projectConfig) {
            abort(404, 'Unknown project');
        }

        $token = $request->bearerToken();

        if (! $token || ! hash_equals((string) $projectConfig['token'], $token)) {
            abort(401, 'Invalid token');
        }

        return $next($request);
    }
}
