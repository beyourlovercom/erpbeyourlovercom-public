<?php

namespace App\Modules\Backup\Application\Contracts;

interface StorageArchiver
{
    public function archive(string $destination): void;
}
