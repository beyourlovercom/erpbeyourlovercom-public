<?php

namespace App\Modules\Backup\Application\Contracts;

interface RestoreSource
{
    public function materialize(string $source, string $destination, string $expectedSha256): void;
}
