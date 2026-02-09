<?php

namespace SMWks\LaravelDbSnapshots\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ClearCacheCommand extends Command
{
    protected $signature = 'db-snapshots:clear-cache {--except-file=}';

    protected $description = 'Clear cache of snapshots';

    public function handle()
    {
        $exceptFile = $this->option('except-file');

        // check for cached files
        $localDisk = Storage::disk(config('db-snapshots.filesystem.local_disk'));
        $localPath = config('db-snapshots.filesystem.local_path');

        $files = $localDisk->allFiles($localPath);

        foreach ($files as $file) {
            if (! Str::startsWith($file, $localPath)) {
                continue;
            }

            $fileName = Str::substr($file, strlen($localPath) + 1);

            if ($exceptFile && $exceptFile == $fileName) {
                continue;
            }

            $this->info("Deleting {$file}");

            $localDisk->delete($file);
        }
    }
}
