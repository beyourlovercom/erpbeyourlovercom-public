<?php

namespace App\Modules\Backup\Application\Contracts;

interface RestoreHealthChecker
{
    public function check(): void;
}
