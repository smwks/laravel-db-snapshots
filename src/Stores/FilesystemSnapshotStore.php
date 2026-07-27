<?php

namespace SMWks\LaravelDbSnapshots\Stores;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Str;

class FilesystemSnapshotStore implements SnapshotStore
{
    public function __construct(
        protected FilesystemAdapter $disk,
        protected string $path
    ) {}

    public function allFiles(): array
    {
        return collect($this->disk->allFiles($this->path))
            ->reject(fn (string $file) => Str::endsWith($file, '.json'))
            ->map(fn (string $file) => Str::substr($file, strlen($this->path) + 1))
            ->values()
            ->all();
    }

    public function exists(string $file): bool
    {
        return $this->disk->exists("{$this->path}/{$file}");
    }

    public function size(string $file): int
    {
        return $this->disk->size("{$this->path}/{$file}");
    }

    public function delete(string $file): bool
    {
        $this->disk->delete("{$this->path}/{$file}.json");

        return $this->disk->delete("{$this->path}/{$file}");
    }

    public function metadata(string $file): ?array
    {
        $metadataPath = "{$this->path}/{$file}.json";

        if (! $this->disk->exists($metadataPath)) {
            return null;
        }

        return json_decode($this->disk->get($metadataPath), true);
    }

    public function get(string $file): string
    {
        return $this->disk->get("{$this->path}/{$file}");
    }

    public function readStream(string $file)
    {
        return $this->disk->readStream("{$this->path}/{$file}");
    }

    public function publish(string $file, string $localPath, array $metadata): void
    {
        $this->disk->put("{$this->path}/{$file}", fopen($localPath, 'r+'));
        $this->disk->put("{$this->path}/{$file}.json", json_encode($metadata, JSON_PRETTY_PRINT));
    }
}
