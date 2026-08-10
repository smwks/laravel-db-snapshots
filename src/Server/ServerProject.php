<?php

namespace SMWks\LaravelDbSnapshots\Server;

/** The DTO a ProjectResolver returns for a resolved project. */
final class ServerProject
{
    public function __construct(
        public readonly string $name,
        public readonly string $token,
        public readonly string $archiveDisk,
        public readonly string $archivePath,
    ) {}
}
