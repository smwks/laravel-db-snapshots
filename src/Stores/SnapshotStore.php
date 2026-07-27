<?php

namespace SMWks\LaravelDbSnapshots\Stores;

interface SnapshotStore
{
    /** @return array<int, string> relative filenames in the archive */
    public function allFiles(): array;

    public function exists(string $file): bool;

    public function size(string $file): int;

    public function delete(string $file): bool;

    /** @return array<string, mixed>|null */
    public function metadata(string $file): ?array;

    public function get(string $file): string;

    /** @return resource */
    public function readStream(string $file);

    /** @param array<string, mixed> $metadata */
    public function publish(string $file, string $localPath, array $metadata): void;
}
