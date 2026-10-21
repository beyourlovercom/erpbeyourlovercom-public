<?php

namespace App\Modules\Backup\Application\Contracts;

interface RestorePackageExtractor
{
    public function extract(string $package, string $destination): void;
}
