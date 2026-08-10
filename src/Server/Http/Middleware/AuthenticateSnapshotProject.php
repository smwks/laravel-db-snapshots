<?php

namespace SMWks\LaravelDbSnapshots\Server\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use SMWks\LaravelDbSnapshots\Server\ProjectResolver;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateSnapshotProject
{
    public function __construct(
        protected ProjectResolver $projectResolver,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $project = $this->projectResolver->resolve($request->route('project'));

        if (! $project) {
            abort(404, 'Unknown project');
        }

        $token = $request->bearerToken();

        if (! $token || ! hash_equals($project->token, $token)) {
            abort(401, 'Invalid token');
        }

        return $next($request);
    }
}
