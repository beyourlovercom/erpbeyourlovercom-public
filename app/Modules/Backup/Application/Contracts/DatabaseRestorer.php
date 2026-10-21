<?php

namespace App\Modules\Backup\Application\Contracts;

interface DatabaseRestorer
{
    public function restore(string $dump): void;
}
