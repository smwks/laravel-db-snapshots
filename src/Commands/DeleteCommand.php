<?php

namespace SMWks\LaravelDbSnapshots\Commands;

use Illuminate\Console\Command;
use SMWks\LaravelDbSnapshots\SnapshotPlan;

class DeleteCommand extends Command
{
    protected $signature = <<<'EOS'
        db-snapshots:delete
        {plan : The Plan name, will default to the first one listed under 'plans'}
        {file : The file to use, will default to the latest file in the plan}
        EOS;

    protected $description = 'Delete database snapshot(s)';

    public function handle()
    {
        $plan = $this->argument('plan');
        $file = $this->argument('file');

        /** @var SnapshotPlan $snapshotPlan */
        $snapshotPlan = SnapshotPlan::all()->firstWhere('name', $plan);

        if (!$snapshotPlan) {
            $this->error("Plan by $plan does not exist.");

            return;
        }

        $snapshot = is_numeric($file)
            ? ($snapshotPlan->snapshots[$file - 1] ?? null)
            : $snapshotPlan->snapshots->firstWhere('fileName', $file);

        if (!$snapshot) {
            $this->error(
                is_numeric($file)
                    ? "Snapshot at index $file does not exist"
                    : "Snapshot with file name $file does not exist"
            );

            return;
        }

        $this->info("Removing {$snapshot->fileName}...");

        $snapshot->remove()
            ? $this->info('File deleted.')
            : $this->error('File was not deleted');
    }
}
