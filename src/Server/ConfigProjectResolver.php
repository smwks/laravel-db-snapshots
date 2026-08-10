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

        return new ServerProject(
            name: $project,
            token: $projectConfig['token'],
            archiveDisk: $projectConfig['archive_disk'],
            archivePath: $projectConfig['archive_path'],
        );
    }
}
