<?php

namespace SMWks\LaravelDbSnapshots\Stores;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class RemoteSnapshotStore implements SnapshotStore
{
    protected ?array $listCache = null;

    public function __construct(
        protected string $endpoint,
        protected string $project,
        protected string $plan,
        protected string $token,
        protected int $timeout = 300,
    ) {}

    public function allFiles(): array
    {
        return collect($this->list())->pluck('file')->all();
    }

    public function exists(string $file): bool
    {
        return $this->findInList($file) !== null;
    }

    public function size(string $file): int
    {
        $entry = $this->findInList($file);

        if (! $entry) {
            throw new RuntimeException("Remote snapshot {$file} does not exist for plan {$this->plan}");
        }

        return (int) ($entry['metadata']['size'] ?? 0);
    }

    public function delete(string $file): bool
    {
        $response = $this->client()->delete("{$this->baseUrl()}/{$file}");

        if (! $response->successful()) {
            throw new RuntimeException("Failed to delete remote snapshot {$file}: {$response->status()} {$response->body()}");
        }

        $this->listCache = null;

        return true;
    }

    public function metadata(string $file): ?array
    {
        return $this->findInList($file)['metadata'] ?? null;
    }

    public function get(string $file): string
    {
        $stream = $this->readStream($file);
        $contents = stream_get_contents($stream);
        fclose($stream);

        return $contents;
    }

    public function readStream(string $file)
    {
        $response = $this->client()
            ->withOptions(['stream' => true, 'allow_redirects' => true])
            ->get("{$this->baseUrl()}/{$file}");

        if (! $response->successful()) {
            throw new RuntimeException("Failed to download remote snapshot {$file}: {$response->status()} {$response->body()}");
        }

        return $response->toPsrResponse()->getBody()->detach();
    }

    public function publish(string $file, string $localPath, array $metadata): void
    {
        $response = $this->client()
            ->attach('file', fopen($localPath, 'r'), $file)
            ->post($this->baseUrl(), [
                'metadata' => json_encode($metadata),
            ]);

        if (! $response->successful()) {
            throw new RuntimeException("Failed to publish remote snapshot {$file}: {$response->status()} {$response->body()}");
        }

        $this->listCache = null;
    }

    protected function baseUrl(): string
    {
        return rtrim($this->endpoint, '/')."/{$this->project}/{$this->plan}";
    }

    protected function client()
    {
        return Http::withToken($this->token)->timeout($this->timeout);
    }

    /**
     * @return array<int, array{file: string, metadata: array|null}>
     */
    protected function list(): array
    {
        if ($this->listCache !== null) {
            return $this->listCache;
        }

        $response = $this->client()->get($this->baseUrl());

        if (! $response->successful()) {
            throw new RuntimeException("Failed to list remote snapshots for plan {$this->plan}: {$response->status()} {$response->body()}");
        }

        return $this->listCache = $response->json('snapshots', []);
    }

    protected function findInList(string $file): ?array
    {
        return collect($this->list())->firstWhere('file', $file);
    }
}
