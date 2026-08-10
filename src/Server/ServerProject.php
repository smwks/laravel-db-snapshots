<?php

namespace SMWks\LaravelDbSnapshots\Server;

final class ServerProject
{
    public function __construct(
        public readonly string $name,
        public readonly string $token,
        public readonly string $archiveDisk,
        public readonly string $archivePath,
    ) {}
}
