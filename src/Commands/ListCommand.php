<?php

namespace SMWks\LaravelDbSnapshots\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use SMWks\LaravelDbSnapshots\Commands\Concerns\HasCommandHelpers;
use SMWks\LaravelDbSnapshots\PlanGroup;
use SMWks\LaravelDbSnapshots\Snapshot;
use SMWks\LaravelDbSnapshots\SnapshotPlan;

class ListCommand extends Command
{
    use HasCommandHelpers;

    protected $signature = <<<'EOS'
        db-snapshots:list
        {plan? : The Plan name, will default to the first one listed under "plans"}
        EOS;

    protected $description = 'List database snapshot(s)';

    public function handle(): int
    {
        $plan = $this->argument('plan');

        $snapshotPlans = SnapshotPlan::all()->when(
            $plan,
            fn (Collection $snapshotPlans) => $snapshotPlans->filter(
                fn ($snapshotPlan) => $snapshotPlan->name === $plan
            )
        );

        $this->newLine();

        $snapshotPlans->each(function (SnapshotPlan $snapshotPlan) {
            $this->info('Plan: ' . $snapshotPlan->name);
            $this->newLine();

            $snapshots = $snapshotPlan->snapshots;

            if ($snapshots->count() === 0) {
                $this->line('  No snapshots found.');
                $this->newLine();

                return;
            }

            // Build table data
            $rows = $snapshots->map(
                fn (Snapshot $snapshot, int $index) => [
                    $index + 1,
                    $snapshot->fileName,
                    $snapshot->date->format('Y-m-d H:i:s'),
                    $snapshot->getFormattedSize(),
                ]
            )->toArray();

            $this->table(
                ['#', 'Filename', 'Created', 'Size'],
                $rows
            );

            $this->newLine();
        });

        // check for cached files
        $localDisk = Storage::disk(config('db-snapshots.filesystem.local_disk'));
        $localPath = config('db-snapshots.filesystem.local_path');

        $files = $localDisk->allFiles($localPath);

        if ($files) {
            $this->newLine();
            $this->info('Locally Cached Files:');
            $this->newLine();

            $cachedRows = [];
            foreach ($files as $file) {
                if (!Str::startsWith($file, $localPath)) {
                    continue;
                }

                $fileName = Str::substr($file, strlen($localPath) + 1);
                $size = $localDisk->size($file);
                $cachedRows[] = [
                    $fileName,
                    $this->formatBytes($size),
                ];
            }

            $this->table(['Filename', 'Size'], $cachedRows);
        }

        // Show plan groups if they exist
        $planGroups = PlanGroup::all();

        if ($planGroups->isNotEmpty()) {
            $this->newLine();
            $this->info('Plan Groups:');
            $this->newLine();

            $planGroups->each(
                fn ($planGroup) => $this->line("  <fg=cyan>{$planGroup->name}</> → [" . implode(', ', $planGroup->planNames) . ']')
            );
        }

        $this->warnAboutUnacceptedFiles();

        $this->newLine();

        return static::SUCCESS;
    }

    protected function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        for ($i = 0; $bytes > 1024 && $i < 4; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, 2) . ' ' . $units[$i];
    }
}
