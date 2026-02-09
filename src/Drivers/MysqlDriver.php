<?php

namespace SMWks\LaravelDbSnapshots\Drivers;

use Illuminate\Filesystem\FilesystemAdapter;

class MysqlDriver implements DatabaseDriver
{
    protected const CREDENTIALS_FILE = 'db-snapshots-mysql-credentials.txt';

    public function buildDumpCommand(
        string $outputFile,
        string $dumpOptions,
        array $tables,
        array $ignoreTables,
        array $schemaOnlyTables,
        string $database,
    ): array {
        $mysqldumpUtil = config('db-snapshots.utilities.mysql.mysqldump', 'mysqldump');

        $commands = [];

        $ignoreTablesOption = $ignoreTables && !$tables
            ? implode(' ', array_map(fn ($table) => "--ignore-table={$database}.{$table}", $ignoreTables))
            : '';

        $schemaOnlyIgnoreTablesOption = !$tables
            ? implode(' ', array_map(fn ($table) => "--ignore-table={$database}.{$table}", $schemaOnlyTables))
            : '';

        $schemaOnlyIncludeTables = implode(' ', $schemaOnlyTables);

        if ($tables) {
            $dataTables = $schemaOnlyTables ? array_diff($tables, $schemaOnlyTables) : $tables;
        }

        // schema and data tables
        $command = "{$mysqldumpUtil} --defaults-extra-file={credentials_file} ";
        $command .= implode(' ', array_filter([$dumpOptions, $ignoreTablesOption, $schemaOnlyIgnoreTablesOption, $database, implode(' ', $dataTables ?? [])]));
        $command .= " > {$outputFile}";
        $commands[] = $command;

        if ($schemaOnlyIncludeTables) {
            $command = "{$mysqldumpUtil} --defaults-extra-file={credentials_file} ";
            $command .= implode(' ', array_filter([$dumpOptions, $ignoreTablesOption, "--no-data {$database}", $schemaOnlyIncludeTables]));
            $command .= " >> {$outputFile}";
            $commands[] = $command;
        }

        return $commands;
    }

    public function buildLoadCommand(string $inputFile, string $database): string
    {
        $mysqlUtil = config('db-snapshots.utilities.mysql.mysql', 'mysql');

        return "{$mysqlUtil} --defaults-extra-file={credentials_file} {$database}";
    }

    public function writeCredentials(array $dbConfig, FilesystemAdapter $disk): array
    {
        $dbHost = $dbConfig['read']['host'][0] ?? $dbConfig['host'];

        $contents = implode(PHP_EOL, [
            '[client]',
            "user = '{$dbConfig['username']}'",
            "password = '{$dbConfig['password']}'",
            "host = '{$dbHost}'",
            "port = '{$dbConfig['port']}'",
        ]);

        $disk->put(self::CREDENTIALS_FILE, $contents);

        return [
            '{credentials_file}' => $disk->path(self::CREDENTIALS_FILE),
            '{database}' => $dbConfig['database'],
        ];
    }

    public function cleanupCredentials(FilesystemAdapter $disk): void
    {
        $disk->delete(self::CREDENTIALS_FILE);
    }

    public static function utilities(): array
    {
        return ['mysqldump', 'mysql'];
    }
}
