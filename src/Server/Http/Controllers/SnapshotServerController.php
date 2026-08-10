<?php

namespace SMWks\LaravelDbSnapshots\Server\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use SMWks\LaravelDbSnapshots\Server\Events\SnapshotDeleted;
use SMWks\LaravelDbSnapshots\Server\Events\SnapshotDownloaded;
use SMWks\LaravelDbSnapshots\Server\Events\SnapshotListed;
use SMWks\LaravelDbSnapshots\Server\Events\SnapshotUploaded;
use SMWks\LaravelDbSnapshots\Server\ProjectResolver;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SnapshotServerController
{
    public function __construct(
        protected ProjectResolver $projectResolver,
    ) {}

    public function index(Request $request, string $project, string $plan)
    {
        [$disk, $path] = $this->diskAndPath($project, $plan);

        $snapshots = collect($disk->allFiles($path))
            ->reject(fn (string $file) => Str::endsWith($file, '.json'))
            ->map(function (string $file) use ($disk, $path) {
                $fileName = Str::substr($file, strlen($path) + 1);
                $metadataPath = "{$file}.json";

                return [
                    'file' => $fileName,
                    'metadata' => $disk->exists($metadataPath)
                        ? json_decode($disk->get($metadataPath), true)
                        : null,
                ];
            })
            ->values();

        event(new SnapshotListed($project, $plan, $request->ip()));

        return response()->json(['snapshots' => $snapshots]);
    }

    public function download(Request $request, string $project, string $plan, string $file)
    {
        $this->assertSafeFileSegment($file);

        [$disk, $path] = $this->diskAndPath($project, $plan);

        $filePath = "{$path}/{$file}";

        abort_unless($disk->exists($filePath), 404);

        event(new SnapshotDownloaded($project, $plan, $file, $request->ip()));

        if ($disk->providesTemporaryUrls()) {
            return redirect()->away($disk->temporaryUrl($filePath, now()->addMinutes(5)));
        }

        return new StreamedResponse(function () use ($disk, $filePath) {
            $stream = $disk->readStream($filePath);
            fpassthru($stream);
            fclose($stream);
        }, 200, [
            'Content-Type' => 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="'.basename($filePath).'"',
            'Content-Length' => $disk->size($filePath),
        ]);
    }

    public function store(Request $request, string $project, string $plan)
    {
        $request->validate([
            'file' => ['required', 'file'],
            'metadata' => ['required', 'string'],
        ]);

        [$disk, $path] = $this->diskAndPath($project, $plan);

        $uploaded = $request->file('file');
        $fileName = $uploaded->getClientOriginalName();

        $fileStored = $disk->put("{$path}/{$fileName}", fopen($uploaded->getRealPath(), 'r'));

        abort_if($fileStored === false, 500, 'Failed to store snapshot file');

        $metadataStored = $disk->put("{$path}/{$fileName}.json", $request->input('metadata'));

        abort_if($metadataStored === false, 500, 'Failed to store snapshot metadata');

        event(new SnapshotUploaded($project, $plan, $fileName, $request->ip()));

        return response()->json(['file' => $fileName], 201);
    }

    public function destroy(Request $request, string $project, string $plan, string $file)
    {
        $this->assertSafeFileSegment($file);

        [$disk, $path] = $this->diskAndPath($project, $plan);

        $disk->delete("{$path}/{$file}.json");
        $disk->delete("{$path}/{$file}");

        event(new SnapshotDeleted($project, $plan, $file, $request->ip()));

        return response()->noContent();
    }

    /**
     * Defensive backstop for the route constraints defined in routes.php:
     * ensures a {file} route parameter can never escape the plan directory
     * via path traversal segments, regardless of what the route file allows
     * in the future.
     */
    protected function assertSafeFileSegment(string $file): void
    {
        abort_unless($file === basename(str_replace('\\', '/', $file)), 400, 'Invalid file segment');
    }

    protected function diskAndPath(string $project, string $plan): array
    {
        $serverProject = $this->projectResolver->resolve($project);

        abort_unless($serverProject, 404, 'Unknown project');

        $disk = $serverProject->archiveDisk === 'cloud'
            ? Storage::cloud()
            : Storage::disk($serverProject->archiveDisk);

        $path = rtrim($serverProject->archivePath, '/')."/{$plan}";

        return [$disk, $path];
    }
}
