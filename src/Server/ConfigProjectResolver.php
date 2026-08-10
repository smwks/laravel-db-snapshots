<?php

namespace SMWks\LaravelDbSnapshots\Server;

class ConfigProjectResolver implements ProjectResolver
{
    public function resolve(string $project): ?ServerProject
    {
        $projectConfig = config("db-snapshots.server.projects.{$project}");

        if (! $projectConfig) {
            return null;
        }

        $missingOrInvalid = array_filter(
            ['token', 'archive_disk', 'archive_path'],
            fn (string $key) => ! isset($projectConfig[$key]) || ! is_string($projectConfig[$key])
        );

        if ($missingOrInvalid !== []) {
            throw new \RuntimeException(
                "Snapshot server project '{$project}' is misconfigured: missing or invalid '".
                implode("'/'", $missingOrInvalid).
                "' in db-snapshots.server.projects.{$project}"
            );
        }

        return new ServerProject(
            name: $project,
            token: $projectConfig['token'],
            archiveDisk: $projectConfig['archive_disk'],
            archivePath: $projectConfig['archive_path'],
        );
    }
}
