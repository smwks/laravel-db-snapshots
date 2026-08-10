<?php

namespace SMWks\LaravelDbSnapshots\Server\Events;

/** Fired once the download is authorized and the response has begun (redirect or stream) — does not confirm the client fully received the file. */
final class SnapshotDownloaded
{
    public function __construct(
        public readonly string $project,
        public readonly string $plan,
        public readonly string $file,
        public readonly ?string $ip,
    ) {}
}
