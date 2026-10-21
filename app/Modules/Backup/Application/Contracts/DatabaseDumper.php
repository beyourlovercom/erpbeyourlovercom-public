<?php

namespace App\Modules\Backup\Application\Contracts;

interface DatabaseDumper
{
    public function dump(string $destination): void;
}
