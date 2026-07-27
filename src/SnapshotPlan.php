<?php

namespace SMWks\LaravelDbSnapshots;

use Carbon\Carbon;
use Exception;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Application;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use SMWks\LaravelDbSnapshots\Commands\Concerns\HasOutputCallbacks;
use SMWks\LaravelDbSnapshots\Drivers\DatabaseDriver;
use SMWks\LaravelDbSnapshots\Drivers\MysqlDriver;
use SMWks\LaravelDbSnapshots\Drivers\PostgresDriver;
use SMWks\LaravelDbSnapshots\Stores\FilesystemSnapshotStore;
use SMWks\LaravelDbSnapshots\Stores\RemoteSnapshotStore;
use SMWks\LaravelDbSnapshots\Stores\SnapshotStore;
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

    public array $tags = [];

    public bool $captureRowCounts = false;

    /** @var Collection<Snapshot> */
    public readonly Collection $snapshots;

    public readonly SnapshotStore $archiveStore;

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
            throw new RuntimeException('db-snapshots.plans does not contain any configured snapshot plans');
        }

        if (isset($snapshotPlanConfigs['cached'])) {
            throw new RuntimeException('You cannot use "cached" as a plan name in your db-snapshots.php config');
        }

        $snapshotPlans = collect($snapshotPlanConfigs)
            ->map(fn ($config, $name) => new SnapshotPlan($name, $config));

        if (config('db-snapshots.filesystem.archive_disk') === 'remote') {
            foreach ($snapshotPlans as $snapshotPlan) {
                foreach ($snapshotPlan->archiveStore->allFiles() as $archiveFileName) {
                    $snapshotPlan->accept($archiveFileName);
                }
            }
        } else {
            foreach ($snapshotPlans->first()->archiveStore->allFiles() as $archiveFileName) {
                $accepted = false;

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
                    static::$unacceptedFiles[] = $archiveFileName;
                }
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

        if (! strpos($this->fileTemplate, '{date')) {
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
                if (! in_array($schemaOnlyTable, $this->tables)) {
                    throw new InvalidArgumentException('When using tables configuration, schema_only_tables that are configured must appear in tables as well');
                }
            }
        }

        $this->keepLast = (int) ($config['keep_last'] ?? 1);
        $this->environmentLocks = $config['environment_locks'] ?? ['create' => 'production', 'load' => 'local'];
        $this->postLoadSqls = $config['post_load_sqls'] ?? [];
        $this->tags = $config['tags'] ?? [];
        $this->captureRowCounts = (bool) ($config['capture_row_counts'] ?? false);

        $this->snapshots = new Collection;

        $this->archiveStore = static::makeArchiveStore($this->name);

        $this->localDisk = Storage::disk(config('db-snapshots.filesystem.local_disk'));

        $this->localPath = rtrim(config('db-snapshots.filesystem.local_path'), '/');
    }

    protected static function makeArchiveStore(string $planName): SnapshotStore
    {
        $archiveDiskConfig = config('db-snapshots.filesystem.archive_disk');

        if ($archiveDiskConfig === 'remote') {
            $endpoint = config('db-snapshots.remote.endpoint');
            $token = config('db-snapshots.remote.token');

            if (empty($endpoint)) {
                throw new RuntimeException('db-snapshots.remote.endpoint must be set when filesystem.archive_disk is "remote"');
            }

            if (empty($token)) {
                throw new RuntimeException('db-snapshots.remote.token must be set when filesystem.archive_disk is "remote"');
            }

            return new RemoteSnapshotStore(
                endpoint: $endpoint,
                project: config('db-snapshots.remote.project') ?? config('app.name'),
                plan: $planName,
                token: $token,
                timeout: (int) config('db-snapshots.remote.timeout', 300),
            );
        }

        $disk = $archiveDiskConfig === 'cloud'
            ? Storage::cloud()
            : Storage::disk($archiveDiskConfig);

        $archivePath = rtrim(config('db-snapshots.filesystem.archive_path'), '/');

        return new FilesystemSnapshotStore($disk, $archivePath);
    }

    public function getDriver(): DatabaseDriver
    {
        if ($this->driver === null) {
            $driverName = config("database.connections.{$this->connection}.driver", 'mysql');

            $this->driver = match ($driverName) {
                'mysql', 'mariadb' => new MysqlDriver,
                'pgsql' => new PostgresDriver,
                default => throw new RuntimeException("Unsupported database driver: {$driverName}"),
            };
        }

        return $this->driver;
    }

    public function getSettings(): array
    {
        return [
            'name' => $this->name,
            'connection' => $this->connection,
            'file_template' => $this->fileTemplate,
            'dump_options' => $this->dumpOptions,
            'keep_last' => $this->keepLast,
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

        $fileName = $this->fileTemplateParts['prefix'].$dateAsTitle.$this->fileTemplateParts['postfix'].'.sql';

        $driver = $this->getDriver();
        $dbConfig = $this->getDatabaseConnectionConfig();

        if (! $this->localDisk->exists($this->localPath)) {
            $this->localDisk->makeDirectory($this->localPath);
        }

        $localFileFullPath = $this->localDisk->path("{$this->localPath}/{$fileName}");

        $startedAt = microtime(true);

        $dataTables = $this->resolveDataTables();

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
                $this->callMessaging('Running: '.$command);

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

            $this->callMessaging('Running: '.$command);

            $process = Process::fromShellCommandline($command);
            $process->setTimeout(null); // No timeout for gzip operations
            $process->run();

            if (! $process->isSuccessful()) {
                $this->localDisk->delete("{$this->localPath}/{$fileName}");
                $this->localDisk->delete("{$this->localPath}/{$fileName}.gz");

                throw new RuntimeException('gzip command failed: '.($process->getErrorOutput() ?: $process->getOutput() ?: 'Unknown error'));
            }

            // tack on .gz as that is what the above command does
            $fileName .= '.gz';
            $localFileFullPath .= '.gz';
        }

        $durationSeconds = (int) round(microtime(true) - $startedAt);

        $metadata = $this->buildMetadata($localFileFullPath, $date, $dataTables, $durationSeconds, $driver);

        // store in archive (filesystem or remote) and remove from local
        try {
            $this->archiveStore->publish($fileName, $localFileFullPath, $metadata);
        } catch (RuntimeException $e) {
            $this->localDisk->delete("{$this->localPath}/{$fileName}");

            throw $e;
        }

        $this->localDisk->delete("{$this->localPath}/{$fileName}");

        $snapshot = new Snapshot($fileName, $date, $this);

        // don't put in list if it matches something that was overwritten
        if (! $this->snapshots->firstWhere('fileName', $snapshot->fileName)) {
            $this->snapshots->prepend($snapshot);
        }

        return $snapshot;
    }

    /**
     * @return array<int, string>
     */
    protected function resolveDataTables(): array
    {
        if ($this->tables) {
            return $this->schemaOnlyTables
                ? array_values(array_diff($this->tables, $this->schemaOnlyTables))
                : $this->tables;
        }

        if (! $this->captureRowCounts) {
            return [];
        }

        $allTables = collect(Schema::connection($this->connection)->getTables())
            ->pluck('name')
            ->all();

        return array_values(array_diff($allTables, $this->ignoreTables, $this->schemaOnlyTables));
    }

    /**
     * @param  array<int, string>  $dataTables
     * @return array<string, int>|null
     */
    protected function resolveRowCounts(array $dataTables): ?array
    {
        if (! $this->captureRowCounts) {
            return null;
        }

        $connection = DB::connection($this->connection);

        return collect($dataTables)
            ->mapWithKeys(fn (string $table) => [$table => $connection->table($table)->count()])
            ->all();
    }

    /**
     * @param  array<int, string>  $dataTables
     * @return array<string, mixed>
     */
    protected function buildMetadata(string $localFileFullPath, Carbon $date, array $dataTables, int $durationSeconds, DatabaseDriver $driver): array
    {
        $tablesKnown = (bool) $this->tables || $this->captureRowCounts;

        return [
            'size' => filesize($localFileFullPath),
            'checksum' => 'sha256:'.hash_file('sha256', $localFileFullPath),
            'duration_seconds' => $durationSeconds,
            'driver' => $driver::class,
            'tables' => $dataTables,
            'schema_only_tables' => $this->schemaOnlyTables,
            'table_count' => $tablesKnown ? count($dataTables) + count($this->schemaOnlyTables) : null,
            'row_counts' => $this->resolveRowCounts($dataTables),
            'app' => config('db-snapshots.identity.app') ?? config('app.name'),
            'environment' => app()->environment(),
            'app_version' => config('db-snapshots.identity.app_version'),
            'php_version' => PHP_VERSION,
            'laravel_version' => Application::VERSION,
            'tags' => $this->tags,
            'created_at' => $date->toIso8601String(),
        ];
    }

    public function matchFileAndDate(string $testFileName): false|Carbon
    {
        $fileName = Str::of($testFileName)->before('.');

        if (($this->fileTemplateParts['prefix'] && ! $fileName->startsWith($this->fileTemplateParts['prefix']))
            || ($this->fileTemplateParts['postfix'] && ! $fileName->endsWith($this->fileTemplateParts['postfix']))) {
            return false;
        }

        if (($this->fileTemplateParts['prefix'] && ! $fileName->startsWith($this->fileTemplateParts['prefix']))
            || ($this->fileTemplateParts['postfix'] && ! $fileName->endsWith($this->fileTemplateParts['postfix']))) {
            return false;
        }

        if (! $this->fileTemplateParts['postfix']) {
            $fileDatePart = $fileName->after($this->fileTemplateParts['prefix']);
        } elseif (! $this->fileTemplateParts['prefix']) {
            $fileDatePart = $fileName->before($this->fileTemplateParts['postfix']);
        } else {
            $fileDatePart = $fileName->betweenFirst($this->fileTemplateParts['prefix'], $this->fileTemplateParts['postfix']);
        }

        try {
            return Carbon::createFromFormat($this->fileTemplateParts['date_format'].'|', (string) $fileDatePart);
        } catch (Exception $e) {
            // If Carbon cannot parse the date format (e.g., file from a removed plan with different naming),
            // return false to indicate this file doesn't match this plan's pattern
            return false;
        }
    }

    public function accept(string $archiveFileName)
    {
        $fileDate = $this->matchFileAndDate($archiveFileName);

        if (! $fileDate) {
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
            if (! Str::startsWith($localFile, $this->localPath)) {
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
        $this->callMessaging('Dropping all tables on connection '.$this->connection);

        DB::connection($this->connection)->getSchemaBuilder()->dropAllTables();
    }

    public function executePostLoadCommands(): array
    {
        $results = [];

        // Execute global commands first
        $globalCommands = config('db-snapshots.post_load_sqls', []);
        foreach ($globalCommands as $command) {
            try {
                $this->callMessaging('Running SQL: '.$command);

                DB::connection($this->connection)->statement($command);

                $results[] = [
                    'command' => $command,
                    'type' => 'global',
                    'success' => true,
                ];
            } catch (Exception $e) {
                $results[] = [
                    'command' => $command,
                    'type' => 'global',
                    'success' => false,
                    'error' => $e->getMessage(),
                ];
            }
        }

        // Execute plan-specific commands
        foreach ($this->postLoadSqls as $command) {
            try {
                $this->callMessaging('Running SQL: '.$command);

                DB::connection($this->connection)->statement($command);

                $results[] = [
                    'command' => $command,
                    'type' => 'plan',
                    'success' => true,
                ];
            } catch (Exception $e) {
                $results[] = [
                    'command' => $command,
                    'type' => 'plan',
                    'success' => false,
                    'error' => $e->getMessage(),
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
            $this->callMessaging('Using credentials managed by '.get_class($driver));
        }

        $this->callMessaging('Running: '.$command);

        $process = Process::fromShellCommandline($command);
        $process->setTimeout(null); // No timeout for database operations
        $process->run();

        $driver->cleanupCredentials($disk);

        $this->callMessaging('Cleaned up credentials');

        if (! $process->isSuccessful()) {
            throw new RuntimeException('Command failed: '.($process->getErrorOutput() ?: $process->getOutput() ?: 'Unknown error'));
        }
    }

    public function getDatabaseConnectionConfig()
    {
        $databaseConnectionConfig = config('database.connections.'.$this->connection);

        if (! $databaseConnectionConfig) {
            throw new RuntimeException("A database connection for name {$this->connection} does not exist");
        }

        return $databaseConnectionConfig;
    }
}
