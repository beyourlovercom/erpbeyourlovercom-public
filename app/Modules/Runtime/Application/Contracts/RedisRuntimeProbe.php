<?php

namespace App\Modules\Runtime\Application\Contracts;

interface RedisRuntimeProbe
{
    public function connectionIsAvailable(string $connection): bool;
}
