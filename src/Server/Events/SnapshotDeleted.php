<?php

namespace SMWks\LaravelDbSnapshots\Server\Events;

/** Fired unconditionally on a delete call, even if the target file never existed — delete is idempotent. */
final class SnapshotDeleted
{
    public function __construct(
        public readonly string $project,
        public readonly string $plan,
        public readonly string $file,
        public readonly ?string $ip,
    ) {}
}
