<?php

namespace App\Modules\Backup\Application\Contracts;

interface StorageRestorer
{
    public function restore(string $archive): void;
}
