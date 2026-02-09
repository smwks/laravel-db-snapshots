<?php

namespace SMWks\LaravelDbSnapshots;

use Carbon\Carbon;
use Exception;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use SMWks\LaravelDbSnapshots\Commands\Concerns\HasOutputCallbacks;
use SMWks\LaravelDbSnapshots\Drivers\DatabaseDriver;
use SMWks\LaravelDbSnapshots\Drivers\MysqlDriver;
use SMWks\LaravelDbSnapshots\Drivers\PostgresDriver;
use Symfony\Component\Process\Process;

class SnapshotPlan
{
    use HasOutputCallbacks;

    public string $name;

    public string $connection;

    public string $fileTemplate;

    public string $dumpOptions = '';

    public array $schemaOnlyTables = [];

    public array $tables = [];

    public array $ignoreTables = [];

    public int $keepLast = 1;

    public array $environmentLocks = [];

    public array $postLoadSqls = [];

    /** @var Collection<Snapshot> */
    public readonly Collection $snapshots;

    public readonly FilesystemAdapter $archiveDisk;

    public readonly string $archivePath;

    public readonly FilesystemAdapter $localDisk;

    public readonly string $localPath;

    protected array $fileTemplateParts;

    public static array $unacceptedFiles = [];

    protected ?DatabaseDriver $driver = null;

    /**
     * @return Collection<SnapshotPlan>
     */
    public static function all(): Collection
    {
        $snapshotPlanConfigs = config('db-snapshots.plans', []);

        if (count($snapshotPlanConfigs) === 0) {
            throw new \RuntimeException('db-snapshots.plans does not contain any configured snapshot plans');
        }

        if (isset($snapshotPlanConfigs['cached'])) {
            throw new RuntimeException('You cannot use "cached" as a plan name in your db-snapshots.php config');
        }

        $snapshotPlans = collect($snapshotPlanConfigs)
            ->map(fn ($config, $name) => new SnapshotPlan($name, $config));

        $archiveDisk = config('db-snapshots.filesystem.archive_disk') === 'cloud'
            ? Storage::cloud()
            : Storage::disk(config('db-snapshots.filesystem.archive_disk'));

        $archivePath = config('db-snapshots.filesystem.archive_path');

        foreach ($archiveDisk->allFiles($archivePath) as $archiveFile) {
            $accepted = false;

            $archiveFileName = Str::substr($archiveFile, strlen($archivePath) + 1);

            $snapshotPlansOrdered = $snapshotPlans->sort(
                fn (SnapshotPlan $a, SnapshotPlan $b) => (strlen($b->fileTemplateParts['prefix']) + strlen($b->fileTemplateParts['postfix']))
                    > (strlen($a->fileTemplateParts['prefix']) + strlen($a->fileTemplateParts['postfix']))
            );

            foreach ($snapshotPlansOrdered as $snapshotPlan) {
                $accepted = $snapshotPlan->accept($archiveFileName);

                if ($accepted) {
                    break;
                }
            }

            if ($accepted === false) {
                static::$unacceptedFiles[] = $archiveFile;
            }
        }

        // re-order the snapshots from latest to earliest
        foreach ($snapshotPlans as $snapshotPlan) {
            if ($snapshotPlan->snapshots->count() < 2) {
                continue;
            }

            $snapshotPlan->snapshots
                ->shift(PHP_INT_MAX) // shift returns new collection here
                ->sort(fn (Snapshot $a, Snapshot $b) => $b->date->gte($a->date))
                ->each(fn (Snapshot $snapshot) => $snapshotPlan->snapshots->push($snapshot));
        }

        return $snapshotPlans;
    }

    public function __construct(string $name, array $config)
    {
        $this->name = $name;
        $this->connection = $config['connection'] ?? config('database.default');
        $this->fileTemplate = $config['file_template'] ?? 'db-snapshots-{date}';

        $fileTemplateString = Str::of($this->fileTemplate);

        if ($fileTemplateString->substrCount('{') > 1) {
            throw new InvalidArgumentException("file_template for Snapshot Plan $name can only contain one date replacement");
        }

        $this->fileTemplateParts['prefix'] = (string) $fileTemplateString->before('{');
        $this->fileTemplateParts['postfix'] = (string) $fileTemplateString->after('}');
        $this->fileTemplateParts['date'] = (string) $fileTemplateString->between('{', '}');

        $dateParts = explode(':', $this->fileTemplateParts['date'], 2);

        $this->fileTemplateParts['date_format'] = $dateParts[1] ?? 'Ymd';

        if (str_contains($this->fileTemplateParts['date_format'], 'W')) {
            throw new InvalidArgumentException('"W" in the date format is not supported as it cannot be used in DateTimeImmutable::createFromDate()');
        }

        if (!strpos($this->fileTemplate, '{date')) {
            throw new InvalidArgumentException("file_template for {$this->name} snapshot plan currently does not have a {date} placeholder");
        }

        $this->dumpOptions = $config['dump_options'] ?? $config['mysqldump_options'] ?? '';
        $this->schemaOnlyTables = $config['schema_only_tables'] ?? [];
        $this->tables = $config['tables'] ?? [];
        $this->ignoreTables = $config['ignore_tables'] ?? [];

        if ($this->tables && $this->ignoreTables) {
            throw new InvalidArgumentException('tables and ignore_tables cannot both be configured with tables in a single plan');
        }

        if ($this->tables && $this->schemaOnlyTables) {
            foreach ($this->schemaOnlyTables as $schemaOnlyTable) {
                if (!in_array($schemaOnlyTable, $this->tables)) {
                    throw new InvalidArgumentException('When using tables configuration, schema_only_tables that are configured must appear in tables as well');
                }
            }
        }

        $this->keepLast = (int) ($config['keep_last'] ?? 1);
        $this->environmentLocks = $config['environment_locks'] ?? ['create' => 'production', 'load' => 'local'];
        $this->postLoadSqls = $config['post_load_sqls'] ?? [];

        $this->snapshots = new Collection;

        $this->archiveDisk = config('db-snapshots.filesystem.archive_disk') === 'cloud'
            ? Storage::cloud()
            : Storage::disk(config('db-snapshots.filesystem.archive_disk'));

        $this->localDisk = Storage::disk(config('db-snapshots.filesystem.local_disk'));

        $this->archivePath = rtrim(config('db-snapshots.filesystem.archive_path'), '/');
        $this->localPath = rtrim(config('db-snapshots.filesystem.local_path'), '/');
    }

    public function getDriver(): DatabaseDriver
    {
        if ($this->driver === null) {
            $driverName = config("database.connections.{$this->connection}.driver", 'mysql');

            $this->driver = match ($driverName) {
                'mysql', 'mariadb' => new MysqlDriver(),
                'pgsql' => new PostgresDriver(),
                default => throw new RuntimeException("Unsupported database driver: {$driverName}"),
            };
        }

        return $this->driver;
    }

    public function getSettings(): array
    {
        return [
            'name'              => $this->name,
            'connection'        => $this->connection,
            'file_template'     => $this->fileTemplate,
            'dump_options'      => $this->dumpOptions,
            'keep_last'         => $this->keepLast,
            'environment_locks' => $this->environmentLocks,
        ];
    }

    public function canCreate(): bool
    {
        return app()->environment($this->environmentLocks['create'] ?? 'production');
    }

    public function canLoad(): bool
    {
        return app()->environment($this->environmentLocks['load'] ?? 'local');
    }

    public function create(): Snapshot
    {
        $date = Carbon::now();
        $dateAsTitle = Str::title($date->format($this->fileTemplateParts['date_format']));

        $fileName = $this->fileTemplateParts['prefix'] . $dateAsTitle . $this->fileTemplateParts['postfix'] . '.sql';

        $driver = $this->getDriver();
        $dbConfig = $this->getDatabaseConnectionConfig();

        if (!$this->localDisk->exists($this->localPath)) {
            $this->localDisk->makeDirectory($this->localPath);
        }

        $localFileFullPath = $this->localDisk->path("{$this->localPath}/{$fileName}");

        try {
            $commands = $driver->buildDumpCommand(
                $localFileFullPath,
                $this->dumpOptions,
                $this->tables,
                $this->ignoreTables,
                $this->schemaOnlyTables,
                $dbConfig['database'],
            );

            foreach ($commands as $command) {
                $this->callMessaging('Running: ' . $command);

                $this->runCommandWithCredentials($command);
            }
        } catch (RuntimeException $e) {
            // Clean up partial file on failure
            $this->localDisk->delete("{$this->localPath}/{$fileName}");

            throw $e;
        }

        $gzipUtil = config('db-snapshots.utilities.gzip');

        if ($gzipUtil) {
            $command = "$gzipUtil -f $localFileFullPath";

            $this->callMessaging('Running: ' . $command);

            $process = Process::fromShellCommandline($command);
            $process->setTimeout(null); // No timeout for gzip operations
            $process->run();

            if (!$process->isSuccessful()) {
                $this->localDisk->delete("{$this->localPath}/{$fileName}");
                $this->localDisk->delete("{$this->localPath}/{$fileName}.gz");

                throw new RuntimeException('gzip command failed: ' . ($process->getErrorOutput() ?: $process->getOutput() ?: 'Unknown error'));
            }

            // tack on .gz as that is what the above command does
            $fileName .= '.gz';
            $localFileFullPath .= '.gz';
        }

        $archiveFile = "{$this->archivePath}/$fileName";

        // store in cloud and remove from local
        $this->archiveDisk->put($archiveFile, fopen($localFileFullPath, 'r+'));
        $this->localDisk->delete("{$this->localPath}/{$fileName}");

        $snapshot = new Snapshot($fileName, $date, $this);

        // don't put in list if it matches something that was overwritten
        if (!$this->snapshots->firstWhere('fileName', $snapshot->fileName)) {
            $this->snapshots->prepend($snapshot);
        }

        return $snapshot;
    }

    public function matchFileAndDate(string $testFileName): false|Carbon
    {
        $fileName = Str::of($testFileName)->before('.');

        if (($this->fileTemplateParts['prefix'] && !$fileName->startsWith($this->fileTemplateParts['prefix']))
            || ($this->fileTemplateParts['postfix'] && !$fileName->endsWith($this->fileTemplateParts['postfix']))) {
            return false;
        }

        if (($this->fileTemplateParts['prefix'] && !$fileName->startsWith($this->fileTemplateParts['prefix']))
            || ($this->fileTemplateParts['postfix'] && !$fileName->endsWith($this->fileTemplateParts['postfix']))) {
            return false;
        }

        if (!$this->fileTemplateParts['postfix']) {
            $fileDatePart = $fileName->after($this->fileTemplateParts['prefix']);
        } elseif (!$this->fileTemplateParts['prefix']) {
            $fileDatePart = $fileName->before($this->fileTemplateParts['postfix']);
        } else {
            $fileDatePart = $fileName->betweenFirst($this->fileTemplateParts['prefix'], $this->fileTemplateParts['postfix']);
        }

        try {
            return Carbon::createFromFormat($this->fileTemplateParts['date_format'] . '|', (string) $fileDatePart);
        } catch (Exception $e) {
            // If Carbon cannot parse the date format (e.g., file from a removed plan with different naming),
            // return false to indicate this file doesn't match this plan's pattern
            return false;
        }
    }

    public function accept(string $archiveFileName)
    {
        $fileDate = $this->matchFileAndDate($archiveFileName);

        if (!$fileDate) {
            return false;
        }

        $this->snapshots->push(new Snapshot($archiveFileName, $fileDate, $this));

        return true;
    }

    public function cleanupCount(): int
    {
        $copy = clone $this->snapshots;

        return $copy->splice($this->keepLast)->count();
    }

    public function cleanup(): int
    {
        return $this->snapshots->splice($this->keepLast)
            ->each(fn (Snapshot $snapshot) => $snapshot->remove())
            ->count();
    }

    public function clearCached($keepFileName = null): array
    {
        $clearedFiles = [];

        $localFiles = $this->localDisk->allFiles($this->localPath);

        foreach ($localFiles as $localFile) {
            if (!Str::startsWith($localFile, $this->localPath)) {
                continue;
            }

            $localFileName = Str::substr($localFile, strlen($this->localPath) + 1);

            if ($this->matchFileAndDate($localFileName) === false) {
                continue;
            }

            if ($keepFileName === $localFileName) {
                continue;
            }

            $clearedFiles[] = $localFileName;

            $this->localDisk->delete($localFile);
        }

        return $clearedFiles;
    }

    public function dropLocalTables(): void
    {
        $this->callMessaging('Dropping all tables on connection ' . $this->connection);

        DB::connection($this->connection)->getSchemaBuilder()->dropAllTables();
    }

    public function executePostLoadCommands(): array
    {
        $results = [];

        // Execute global commands first
        $globalCommands = config('db-snapshots.post_load_sqls', []);
        foreach ($globalCommands as $command) {
            try {
                $this->callMessaging('Running SQL: ' . $command);

                DB::connection($this->connection)->statement($command);

                $results[] = [
                    'command' => $command,
                    'type'    => 'global',
                    'success' => true,
                ];
            } catch (Exception $e) {
                $results[] = [
                    'command' => $command,
                    'type'    => 'global',
                    'success' => false,
                    'error'   => $e->getMessage(),
                ];
            }
        }

        // Execute plan-specific commands
        foreach ($this->postLoadSqls as $command) {
            try {
                $this->callMessaging('Running SQL: ' . $command);

                DB::connection($this->connection)->statement($command);

                $results[] = [
                    'command' => $command,
                    'type'    => 'plan',
                    'success' => true,
                ];
            } catch (Exception $e) {
                $results[] = [
                    'command' => $command,
                    'type'    => 'plan',
                    'success' => false,
                    'error'   => $e->getMessage(),
                ];
            }
        }

        return $results;
    }

    public function runCommandWithCredentials($command): void
    {
        $dbConfig = $this->getDatabaseConnectionConfig();
        $driver = $this->getDriver();

        $disk = Storage::disk('local');

        $replacements = $driver->writeCredentials($dbConfig, $disk);

        $command = str_replace(
            array_keys($replacements),
            array_values($replacements),
            $command
        );

        if (config('app.debug')) {
            $this->callMessaging('Using credentials managed by ' . get_class($driver));
        }

        $this->callMessaging('Running: ' . $command);

        $process = Process::fromShellCommandline($command);
        $process->setTimeout(null); // No timeout for database operations
        $process->run();

        $driver->cleanupCredentials($disk);

        $this->callMessaging('Cleaned up credentials');

        if (!$process->isSuccessful()) {
            throw new RuntimeException('Command failed: ' . ($process->getErrorOutput() ?: $process->getOutput() ?: 'Unknown error'));
        }
    }

    public function getDatabaseConnectionConfig()
    {
        $databaseConnectionConfig = config('database.connections.' . $this->connection);

        if (!$databaseConnectionConfig) {
            throw new RuntimeException("A database connection for name {$this->connection} does not exist");
        }

        return $databaseConnectionConfig;
    }
}
