<?php

namespace SMWks\LaravelDbSnapshots\Server\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SnapshotServerController
{
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

        return response()->json(['snapshots' => $snapshots]);
    }

    public function download(Request $request, string $project, string $plan, string $file)
    {
        [$disk, $path] = $this->diskAndPath($project, $plan);

        $filePath = "{$path}/{$file}";

        abort_unless($disk->exists($filePath), 404);

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

        $disk->put("{$path}/{$fileName}", fopen($uploaded->getRealPath(), 'r'));
        $disk->put("{$path}/{$fileName}.json", $request->input('metadata'));

        return response()->json(['file' => $fileName], 201);
    }

    public function destroy(Request $request, string $project, string $plan, string $file)
    {
        [$disk, $path] = $this->diskAndPath($project, $plan);

        $disk->delete("{$path}/{$file}.json");
        $disk->delete("{$path}/{$file}");

        return response()->noContent();
    }

    protected function diskAndPath(string $project, string $plan): array
    {
        $projectConfig = config("db-snapshots.server.projects.{$project}");

        $disk = $projectConfig['archive_disk'] === 'cloud'
            ? Storage::cloud()
            : Storage::disk($projectConfig['archive_disk']);

        $path = rtrim($projectConfig['archive_path'], '/')."/{$plan}";

        return [$disk, $path];
    }
}
