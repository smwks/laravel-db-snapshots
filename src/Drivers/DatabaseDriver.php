<?php

namespace SMWks\LaravelDbSnapshots\Drivers;

use Illuminate\Filesystem\FilesystemAdapter;

interface DatabaseDriver
{
    /**
     * Build the CLI command string(s) for dumping the database.
     *
     * @return array<string> Array of command strings (some drivers need multiple passes)
     */
    public function buildDumpCommand(
        string $outputFile,
        string $dumpOptions,
        array $tables,
        array $ignoreTables,
        array $schemaOnlyTables,
        string $database,
    ): array;

    /**
     * Build the CLI command string for loading/restoring a dump.
     */
    public function buildLoadCommand(string $inputFile, string $database): string;

    /**
     * Write temporary credentials and return a replacements map for command strings.
     *
     * @return array<string, string> Map of {placeholder} => actual_value
     */
    public function writeCredentials(array $dbConfig, FilesystemAdapter $disk): array;

    /**
     * Clean up temporary credential files.
     */
    public function cleanupCredentials(FilesystemAdapter $disk): void;

    /**
     * Get the list of CLI utilities this driver needs.
     *
     * @return array<string>
     */
    public static function utilities(): array;
}
