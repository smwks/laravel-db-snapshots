<?php

namespace SMWks\LaravelDbSnapshots\Server\Events;

/** Fired on every list request, regardless of how many snapshots (if any) are returned. */
final class SnapshotListed
{
    public function __construct(
        public readonly string $project,
        public readonly string $plan,
        public readonly ?string $ip,
    ) {}
}
