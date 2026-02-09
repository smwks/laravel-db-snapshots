<?php

use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;
use SMWks\LaravelDbSnapshots\SnapshotPlan;

test('get all plans based off config', function () {
    config()->set('db-snapshots.plans', [
        'daily' => [],
        'monthly' => [],
    ]);

    $plans = SnapshotPlan::all();

    expect($plans)->toHaveCount(2);
    expect($plans->whereInstanceOf(SnapshotPlan::class))->toHaveCount(2);
});

test('get settings', function () {
    $plan = new SnapshotPlan('daily', defaultDailyConfig());

    $settingsFromPlan = $plan->getSettings();
    expect($settingsFromPlan['name'])->toBe('daily');
    expect($settingsFromPlan['file_template'])->toBe('db-snapshot-daily-{date:Ymd}');
    expect($settingsFromPlan['dump_options'])->toBe('--single-transaction');
    expect($settingsFromPlan['keep_last'])->toBe(2);
    expect($settingsFromPlan['environment_locks'])->toBe(['create' => 'production', 'load' => 'local']);
});

test('can create', function () {
    $plan = new SnapshotPlan('daily', defaultDailyConfig());

    // set environment detection to return local
    app()->detectEnvironment(fn () => 'local');
    expect($plan->canCreate())->toBeFalse();

    // set environment detection to return production
    app()->detectEnvironment(fn () => 'production');
    expect($plan->canCreate())->toBeTrue();
});

test('can load', function () {
    $plan = new SnapshotPlan('daily', defaultDailyConfig());

    // set environment detection to return local
    app()->detectEnvironment(fn () => 'local');
    expect($plan->canLoad())->toBeTrue();

    // set environment detection to return production
    app()->detectEnvironment(fn () => 'production');
    expect($plan->canLoad())->toBeFalse();
});

test('create', function () {
    $snapshotPlan = new SnapshotPlan('daily', defaultDailyConfig());
    $snapshot = $snapshotPlan->create();

    $archiveDisk = Storage::disk(config('db-snapshots.filesystem.archive_disk'));

    $expectedFile = 'cloud-snapshots/db-snapshot-daily-'.date('Ymd').'.sql.gz';

    // assert snapshot object is right
    expect($snapshot->fileName)->toBe('db-snapshot-daily-'.date('Ymd').'.sql.gz');

    // assert file actually on disk
    $files = $archiveDisk->allFiles(config('db-snapshots.filesystem.archive_path'));
    expect($files)->toHaveCount(1);
    expect(__DIR__.'/fixtures/local-filesystem/'.$expectedFile)->toBeFile();
});

test('create with table list', function () {
    $config = defaultDailyConfig();
    $config['tables'] = ['foo', 'bar', 'bam'];

    $snapshotPlan = new SnapshotPlan('daily', $config);
    $snapshotPlan->create();

    // assert command
    $arguments = file_get_contents(__DIR__.'/fixtures/local-filesystem/fakemysqldump-arguments.txt');
    expect($arguments)->toContain('laravel foo bar bam');
});

test('create with table list and schema only', function () {
    $config = defaultDailyConfig();
    $config['tables'] = ['foo', 'bar', 'bam'];
    $config['schema_only_tables'] = ['bar'];

    $snapshotPlan = new SnapshotPlan('daily', $config);
    $snapshotPlan->create();

    // assert command
    $arguments = file_get_contents(__DIR__.'/fixtures/local-filesystem/fakemysqldump-arguments.txt');
    expect($arguments)->toContain('laravel foo bam');
    expect($arguments)->toContain('laravel bar');
});

test('snapshot plan will throw exception when tables and ignore tables are configured', function () {
    $config = defaultDailyConfig();
    $config['tables'] = ['foo', 'bar', 'bam'];
    $config['ignore_tables'] = ['bar'];

    new SnapshotPlan('daily', $config);
})->throws(InvalidArgumentException::class, 'tables and ignore_tables cannot both be configured');

test('snapshot plan will throw exception when schema only tables not in tables list', function () {
    $config = defaultDailyConfig();
    $config['tables'] = ['foo', 'bam'];
    $config['schema_only_tables'] = ['bar'];

    new SnapshotPlan('daily', $config);
})->throws(InvalidArgumentException::class, 'schema_only_tables that are configured must appear in tables as well');

test('snapshot plan handles orphaned files from removed plans', function () {
    // Setup: Create snapshot files that match different plan patterns
    $archiveDisk = Storage::disk(config('db-snapshots.filesystem.archive_disk'));
    $archivePath = config('db-snapshots.filesystem.archive_path');

    // Create a file that matches a "daily-v8" plan pattern (which we'll pretend was removed)
    $orphanedFile = $archivePath.'/db-snapshot-daily-v8-20240913.sql.gz';
    $archiveDisk->put($orphanedFile, 'fake snapshot content');

    // Create a file that matches our current "daily" plan pattern
    $validFile = $archivePath.'/db-snapshot-daily-20240913.sql.gz';
    $archiveDisk->put($validFile, 'fake snapshot content');

    // Configure only the "daily" plan (simulating that "daily-v8" was removed)
    config()->set('db-snapshots.plans', [
        'daily' => defaultDailyConfig(),
    ]);

    // This should not throw an exception despite the orphaned file
    $plans = SnapshotPlan::all();

    // Assert that we got our plan
    expect($plans)->toHaveCount(1);
    $dailyPlan = $plans->first();
    expect($dailyPlan->name)->toBe('daily');

    // Assert that only the matching file was accepted
    expect($dailyPlan->snapshots)->toHaveCount(1);
    expect($dailyPlan->snapshots->first()->fileName)->toBe('db-snapshot-daily-20240913.sql.gz');

    // Assert that the orphaned file was tracked as unaccepted
    expect(SnapshotPlan::$unacceptedFiles)->toHaveCount(1);
    expect(SnapshotPlan::$unacceptedFiles[0])->toContain('db-snapshot-daily-v8-20240913.sql.gz');
});

test('snapshot can get size', function () {
    $snapshotPlan = new SnapshotPlan('daily', defaultDailyConfig());
    $snapshot = $snapshotPlan->create();

    $size = $snapshot->getSize();
    expect($size)->toBeInt();
    expect($size)->toBeGreaterThan(0);

    $formattedSize = $snapshot->getFormattedSize();
    expect($formattedSize)->toBeString();
    expect($formattedSize)->toMatch('/\d+(\.\d+)?\s+(B|KB|MB|GB|TB)/');
});

test('file template with hour format', function () {
    $config = defaultDailyConfig();
    $config['file_template'] = 'db-snapshot-hourly-{date:YmdH}';

    $snapshotPlan = new SnapshotPlan('hourly', $config);
    $snapshot = $snapshotPlan->create();

    // Expected filename should include the hour
    $expectedFileName = 'db-snapshot-hourly-'.date('YmdH').'.sql.gz';
    expect($snapshot->fileName)->toBe($expectedFileName);

    // Test that the file was created
    $archiveDisk = Storage::disk(config('db-snapshots.filesystem.archive_disk'));
    $files = $archiveDisk->allFiles(config('db-snapshots.filesystem.archive_path'));
    expect($files)->toHaveCount(1);

    // Test that matchFileAndDate can parse the filename correctly
    $parsedDate = $snapshotPlan->matchFileAndDate($snapshot->fileName);
    expect($parsedDate)->toBeInstanceOf(Carbon::class);
    expect($parsedDate->format('YmdH'))->toBe(date('YmdH'));
});

test('file template with hour and minute format', function () {
    $config = defaultDailyConfig();
    $config['file_template'] = 'db-snapshot-{date:YmdHi}';

    $snapshotPlan = new SnapshotPlan('precise', $config);
    $snapshot = $snapshotPlan->create();

    $expectedFileName = 'db-snapshot-'.date('YmdHi').'.sql.gz';
    expect($snapshot->fileName)->toBe($expectedFileName);

    // Test parsing
    $parsedDate = $snapshotPlan->matchFileAndDate($snapshot->fileName);
    expect($parsedDate)->toBeInstanceOf(Carbon::class);
    expect($parsedDate->format('YmdHi'))->toBe(date('YmdHi'));
});

test('loading snapshots with hour format from disk', function () {
    // Create multiple snapshots with different hours
    $archiveDisk = Storage::disk(config('db-snapshots.filesystem.archive_disk'));
    $archivePath = config('db-snapshots.filesystem.archive_path');

    // Simulate snapshots from different hours
    $archiveDisk->put($archivePath.'/db-snapshot-hourly-2024091310.sql.gz', 'fake snapshot 10am');
    $archiveDisk->put($archivePath.'/db-snapshot-hourly-2024091314.sql.gz', 'fake snapshot 2pm');
    $archiveDisk->put($archivePath.'/db-snapshot-hourly-2024091318.sql.gz', 'fake snapshot 6pm');

    // Configure the plan
    config()->set('db-snapshots.plans', [
        'hourly' => [
            'connection' => 'mysql',
            'file_template' => 'db-snapshot-hourly-{date:YmdH}',
            'dump_options' => '--single-transaction',
            'keep_last' => 2,
            'environment_locks' => [
                'create' => 'production',
                'load' => 'local',
            ],
        ],
    ]);

    // Load all plans and verify it found all three snapshots
    $plans = SnapshotPlan::all();
    $hourlyPlan = $plans->firstWhere('name', 'hourly');

    expect($hourlyPlan->snapshots)->toHaveCount(3);

    // Verify they are sorted newest first
    expect($hourlyPlan->snapshots[0]->fileName)->toBe('db-snapshot-hourly-2024091318.sql.gz');
    expect($hourlyPlan->snapshots[1]->fileName)->toBe('db-snapshot-hourly-2024091314.sql.gz');
    expect($hourlyPlan->snapshots[2]->fileName)->toBe('db-snapshot-hourly-2024091310.sql.gz');
});

test('file template with date and time separated', function () {
    $config = defaultDailyConfig();
    $config['file_template'] = 'db-snapshot-{date:Ymd-His}';

    $snapshotPlan = new SnapshotPlan('datetime', $config);
    $snapshot = $snapshotPlan->create();

    // Verify filename format matches expected pattern
    expect($snapshot->fileName)->toMatch('/^db-snapshot-\d{8}-\d{6}\.sql\.gz$/');

    // Test that matchFileAndDate can parse the filename correctly
    $parsedDate = $snapshotPlan->matchFileAndDate($snapshot->fileName);
    expect($parsedDate)->toBeInstanceOf(Carbon::class);

    // Use the snapshot's date property to verify parsing
    $expectedFormat = $snapshot->date->format('Ymd-His');
    expect($parsedDate->format('Ymd-His'))->toBe($expectedFormat);

    // Verify the parsed date components match the snapshot date
    expect($parsedDate->format('Y'))->toBe($snapshot->date->format('Y'));
    expect($parsedDate->format('m'))->toBe($snapshot->date->format('m'));
    expect($parsedDate->format('d'))->toBe($snapshot->date->format('d'));
    expect($parsedDate->format('H'))->toBe($snapshot->date->format('H'));
    expect($parsedDate->format('i'))->toBe($snapshot->date->format('i'));
    expect($parsedDate->format('s'))->toBe($snapshot->date->format('s'));
});

test('gzip failure cleans up partial files', function () {
    config()->set('db-snapshots.utilities.gzip', __DIR__.'/fixtures/fakegzip-failure');

    $snapshotPlan = new SnapshotPlan('daily', defaultDailyConfig());

    $localDisk = Storage::disk(config('db-snapshots.filesystem.local_disk'));
    $localPath = config('db-snapshots.filesystem.local_path');
    $fileName = 'db-snapshot-daily-'.date('Ymd').'.sql';

    try {
        $snapshotPlan->create();
        $this->fail('Expected RuntimeException was not thrown');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('gzip command failed');
        expect($e->getMessage())->toContain('simulated failure');
    }

    // Verify both files were cleaned up after the failure
    expect($localDisk->exists("{$localPath}/{$fileName}"))->toBeFalse();
    expect($localDisk->exists("{$localPath}/{$fileName}.gz"))->toBeFalse();
});

test('mysqldump failure cleans up partial file', function () {
    config()->set('db-snapshots.utilities.mysql.mysqldump', __DIR__.'/fixtures/fakemysqldump-failure');

    $snapshotPlan = new SnapshotPlan('daily', defaultDailyConfig());

    $localDisk = Storage::disk(config('db-snapshots.filesystem.local_disk'));
    $localPath = config('db-snapshots.filesystem.local_path');
    $fileName = 'db-snapshot-daily-'.date('Ymd').'.sql';

    try {
        $snapshotPlan->create();
        $this->fail('Expected RuntimeException was not thrown');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('Command failed');
    }

    // Verify the partial SQL file was cleaned up
    expect($localDisk->exists("{$localPath}/{$fileName}"))->toBeFalse();
});

test('recached flag forces download even with same date', function () {
    // Enable smart caching
    config()->set('db-snapshots.cache_by_default', true);

    $archiveDisk = Storage::disk(config('db-snapshots.filesystem.archive_disk'));
    $localDisk = Storage::disk(config('db-snapshots.filesystem.local_disk'));
    $archivePath = config('db-snapshots.filesystem.archive_path');
    $localPath = config('db-snapshots.filesystem.local_path');

    // Create a snapshot file on archive with specific content
    $fileName = 'db-snapshot-daily-20240913.sql.gz';
    $originalContent = 'original snapshot content';
    $archiveDisk->put($archivePath.'/'.$fileName, $originalContent);

    // Simulate a cached copy with different content
    $localDisk->makeDirectory($localPath);
    $cachedContent = 'old cached content';
    $localDisk->put($localPath.'/'.$fileName, $cachedContent);

    // Configure plan
    config()->set('db-snapshots.plans', [
        'daily' => defaultDailyConfig(),
    ]);

    // Load plans and get snapshot
    $plans = SnapshotPlan::all();
    $dailyPlan = $plans->firstWhere('name', 'daily');
    $snapshot = $dailyPlan->snapshots->firstWhere('fileName', $fileName);

    expect($snapshot)->not->toBeNull();

    // Verify cached file exists with old content
    expect($localDisk->exists($localPath.'/'.$fileName))->toBeTrue();
    expect($localDisk->get($localPath.'/'.$fileName))->toBe($cachedContent);

    // Simulate --recached behavior: forceDownload=true
    $downloadInfo = $snapshot->download(useLocalCopy: false, forceDownload: true);

    // Assert that it downloaded
    expect($downloadInfo['downloaded'])->toBeTrue();

    // Verify the local file now has the new content from archive
    expect($localDisk->get($localPath.'/'.$fileName))->toBe($originalContent);
});
