<?php

namespace SMWks\LaravelDbSnapshots\Server\Events;

/** Fired only after both the snapshot file and its metadata sidecar are confirmed written. */
final class SnapshotUploaded
{
    public function __construct(
        public readonly string $project,
        public readonly string $plan,
        public readonly string $file,
        public readonly ?string $ip,
    ) {}
}
