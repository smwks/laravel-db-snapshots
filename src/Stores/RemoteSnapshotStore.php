<?php

namespace SMWks\LaravelDbSnapshots\Stores;

use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
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
        $this->send(
            fn (PendingRequest $client) => $client->delete("{$this->baseUrl()}/{$file}"),
            "Failed to delete remote snapshot {$file}",
        );

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
        $response = $this->send(
            fn (PendingRequest $client) => $client
                ->withOptions(['stream' => true, 'allow_redirects' => true])
                ->get("{$this->baseUrl()}/{$file}"),
            "Failed to download remote snapshot {$file}",
        );

        return $response->toPsrResponse()->getBody()->detach();
    }

    public function publish(string $file, string $localPath, array $metadata): void
    {
        $this->send(
            fn (PendingRequest $client) => $client
                ->attach('file', fopen($localPath, 'r'), $file)
                ->post($this->baseUrl(), [
                    'metadata' => json_encode($metadata),
                ]),
            "Failed to publish remote snapshot {$file}",
        );

        $this->listCache = null;
    }

    protected function baseUrl(): string
    {
        return rtrim($this->endpoint, '/')."/{$this->project}/{$this->plan}";
    }

    protected function client(): PendingRequest
    {
        return Http::withToken($this->token)->timeout($this->timeout);
    }

    /**
     * Runs an HTTP call through the shared client, rewrapping any connection
     * level failure (DNS, refused connection, timeout) as a RuntimeException
     * so every failure mode from this store surfaces as one exception type,
     * and centralizing the "non-successful response" -> RuntimeException
     * check that was previously duplicated across every method here.
     */
    protected function send(Closure $callback, string $context): Response
    {
        try {
            $response = $callback($this->client());
        } catch (ConnectionException $e) {
            throw new RuntimeException("{$context}: {$e->getMessage()}", 0, $e);
        }

        if (! $response->successful()) {
            throw new RuntimeException("{$context}: {$response->status()} {$response->body()}");
        }

        return $response;
    }

    /**
     * @return array<int, array{file: string, metadata: array|null}>
     */
    protected function list(): array
    {
        if ($this->listCache !== null) {
            return $this->listCache;
        }

        $response = $this->send(
            fn (PendingRequest $client) => $client->get($this->baseUrl()),
            "Failed to list remote snapshots for plan {$this->plan}",
        );

        return $this->listCache = $response->json('snapshots', []);
    }

    protected function findInList(string $file): ?array
    {
        return collect($this->list())->firstWhere('file', $file);
    }
}
