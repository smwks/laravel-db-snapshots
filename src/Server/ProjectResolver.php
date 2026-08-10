<?php

namespace SMWks\LaravelDbSnapshots\Server;

/** Resolves a route project segment to the ServerProject it identifies, or null if unknown. */
interface ProjectResolver
{
    /**
     * May be called more than once per request (though since it is bound as a
     * singleton, every call within a request receives the same instance).
     * Returns null to mean "unknown project" — callers should treat that as a 404.
     * Implementations must be safe to call with an arbitrary, untrusted string
     * (the raw {project} route segment).
     */
    public function resolve(string $project): ?ServerProject;
}
