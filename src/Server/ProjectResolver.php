<?php

namespace SMWks\LaravelDbSnapshots\Server;

interface ProjectResolver
{
    public function resolve(string $project): ?ServerProject;
}
