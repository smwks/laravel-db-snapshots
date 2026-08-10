<?php

namespace SMWks\LaravelDbSnapshots\Server\Events;

class SnapshotListed
{
    public function __construct(
        public readonly string $project,
        public readonly string $plan,
        public readonly ?string $ip,
    ) {}
}
