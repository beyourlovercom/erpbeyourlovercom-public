<?php

namespace App\Modules\Runtime\Experience\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;

final class RuntimeValidationJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    public int $backoff = 0;

    public function __construct(
        private readonly string $key,
        private readonly bool $retryOnce = false,
    ) {}

    public function handle(Repository $cache): void
    {
        $attempts = $cache->increment($this->key.':attempts');

        if ($this->retryOnce && $attempts === 1) {
            throw new RuntimeException('Deterministic runtime validation retry.');
        }

        $cache->put($this->key.':result', 'acknowledged', 60);
    }
}
