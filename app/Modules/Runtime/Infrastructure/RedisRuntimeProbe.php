<?php

namespace App\Modules\Runtime\Infrastructure;

use App\Modules\Runtime\Application\Contracts\RedisRuntimeProbe as RedisRuntimeProbeContract;
use Illuminate\Redis\RedisManager;

class RedisRuntimeProbe implements RedisRuntimeProbeContract
{
    public function __construct(private readonly RedisManager $redis) {}

    public function connectionIsAvailable(string $connection): bool
    {
        try {
            return $this->redis->connection($connection)->command('ping') !== false;
        } catch (\Throwable) {
            return false;
        }
    }
}
