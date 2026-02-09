<?php

namespace SMWks\LaravelDbSnapshots\Drivers;

use Illuminate\Filesystem\FilesystemAdapter;

class PostgresDriver implements DatabaseDriver
{
    protected const PGPASS_FILE = 'db-snapshots-pgpass.txt';

    public function buildDumpCommand(
        string $outputFile,
        string $dumpOptions,
        array $tables,
        array $ignoreTables,
        array $schemaOnlyTables,
        string $database,
    ): array {
        $pgDumpUtil = config('db-snapshots.utilities.pgsql.pg_dump', 'pg_dump');

        $commands = [];

        $ignoreTablesOption = '';
        if ($ignoreTables && !$tables) {
            $ignoreTablesOption = implode(' ', array_map(fn ($table) => "--exclude-table={$table}", $ignoreTables));
        }

        $schemaOnlyExcludeOption = '';
        if (!$tables && $schemaOnlyTables) {
            $schemaOnlyExcludeOption = implode(' ', array_map(fn ($table) => "--exclude-table={$table}", $schemaOnlyTables));
        }

        if ($tables) {
            $dataTables = $schemaOnlyTables ? array_diff($tables, $schemaOnlyTables) : $tables;
            $tableOptions = implode(' ', array_map(fn ($table) => "-t {$table}", $dataTables));
        }

        // Data + schema dump
        $command = "PGPASSFILE={credentials_file} {$pgDumpUtil}";
        $command .= " -h {host} -p {port} -U {username}";

        $parts = array_filter([$dumpOptions, $ignoreTablesOption, $schemaOnlyExcludeOption, $tableOptions ?? '']);
        if ($parts) {
            $command .= ' ' . implode(' ', $parts);
        }

        $command .= " {$database} > {$outputFile}";
        $commands[] = $command;

        // Schema-only tables (separate pass)
        if ($schemaOnlyTables) {
            $schemaOnlyTableOptions = implode(' ', array_map(fn ($table) => "-t {$table}", $schemaOnlyTables));

            $command = "PGPASSFILE={credentials_file} {$pgDumpUtil}";
            $command .= " -h {host} -p {port} -U {username}";
            $command .= " --schema-only";

            $parts = array_filter([$dumpOptions, $schemaOnlyTableOptions]);
            if ($parts) {
                $command .= ' ' . implode(' ', $parts);
            }

            $command .= " {$database} >> {$outputFile}";
            $commands[] = $command;
        }

        return $commands;
    }

    public function buildLoadCommand(string $inputFile, string $database): string
    {
        $psqlUtil = config('db-snapshots.utilities.pgsql.psql', 'psql');

        return "PGPASSFILE={credentials_file} {$psqlUtil} -h {host} -p {port} -U {username} {$database}";
    }

    public function writeCredentials(array $dbConfig, FilesystemAdapter $disk): array
    {
        $dbHost = $dbConfig['read']['host'][0] ?? $dbConfig['host'];
        $port = $dbConfig['port'] ?? '5432';

        // pgpass format: hostname:port:database:username:password
        $contents = "{$dbHost}:{$port}:{$dbConfig['database']}:{$dbConfig['username']}:{$dbConfig['password']}";

        $disk->put(self::PGPASS_FILE, $contents);

        // pgpass file must have restricted permissions
        $pgpassPath = $disk->path(self::PGPASS_FILE);
        chmod($pgpassPath, 0600);

        return [
            '{credentials_file}' => $pgpassPath,
            '{database}' => $dbConfig['database'],
            '{host}' => $dbHost,
            '{port}' => $port,
            '{username}' => $dbConfig['username'],
        ];
    }

    public function cleanupCredentials(FilesystemAdapter $disk): void
    {
        $disk->delete(self::PGPASS_FILE);
    }

    public static function utilities(): array
    {
        return ['pg_dump', 'psql'];
    }
}
