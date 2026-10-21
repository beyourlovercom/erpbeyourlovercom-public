<?php

namespace Tests\Feature\Runtime;

use App\Modules\Runtime\Application\RuntimeHealthService;
use App\Modules\Runtime\Experience\Jobs\RuntimeValidationJob;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class RedisQueueSchedulerRuntimeTest extends TestCase
{
    private string $host;

    private int $port;

    private string $prefix;

    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('BYL_REDIS_RUNTIME_ENABLED') !== '1') {
            $this->markTestSkipped('Disposable Redis runtime validation is disabled.');
        }

        $this->host = (string) getenv('BYL_REDIS_RUNTIME_HOST');
        $this->port = (int) getenv('BYL_REDIS_RUNTIME_PORT');
        $this->prefix = (string) getenv('BYL_REDIS_RUNTIME_PREFIX');
        $this->assertSame('127.0.0.1', $this->host);
        $this->assertGreaterThan(1024, $this->port);
        $this->assertMatchesRegularExpression('/^byl_001fd_[a-z0-9_]+_/', $this->prefix);

        Config::set('database.redis.client', 'predis');
        Config::set('database.redis.options.prefix', $this->prefix);
        foreach (['default', 'cache'] as $connection) {
            Config::set("database.redis.{$connection}.host", $this->host);
            Config::set("database.redis.{$connection}.port", $this->port);
            Config::set("database.redis.{$connection}.database", 0);
            Config::set("database.redis.{$connection}.timeout", 2.0);
            Config::set("database.redis.{$connection}.read_timeout", 6.0);
        }
        Config::set('cache.default', 'redis');
        Config::set('cache.stores.redis.connection', 'cache');
        Config::set('queue.default', 'redis');
        Config::set('queue.connections.redis.connection', 'default');
        Config::set('queue.connections.redis.queue', 'runtime');
        Config::set('queue.connections.redis.retry_after', 90);
        Config::set('queue.connections.redis.block_for', 1);
        Config::set('queue.connections.redis.after_commit', true);
        Redis::purge();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Redis::purge();

        parent::tearDown();
    }

    public function test_disposable_redis_runtime_queue_worker_and_scheduler_are_live(): void
    {
        $key = 'runtime-validation-'.bin2hex(random_bytes(8));
        Cache::put($key, 'live', 60);
        $this->assertSame('live', Cache::get($key));
        Cache::forget($key);
        $this->assertNull(Cache::get($key));

        $health = app(RuntimeHealthService::class)->check();
        $this->assertSame('ok', $health['status']);

        RuntimeValidationJob::dispatch($key)->onQueue('runtime');
        $this->runWorker();
        $this->assertSame('acknowledged', Cache::get($key.':result'));
        $this->assertSame(1, (int) Cache::get($key.':attempts'));

        RuntimeValidationJob::dispatch($key.'-retry', true)->onQueue('runtime');
        $this->runWorker();
        $this->runWorker();
        $this->assertSame('acknowledged', Cache::get($key.'-retry:result'));
        $this->assertSame(2, (int) Cache::get($key.'-retry:attempts'));

        CarbonImmutable::setTestNow('2026-08-05 01:35:00');
        $this->artisan('schedule:run')
            ->expectsOutputToContain('runtime:health')
            ->assertSuccessful();
    }

    private function runWorker(): void
    {
        $worker = new Process([
            PHP_BINARY,
            'artisan',
            'queue:work',
            'redis',
            '--queue=runtime',
            '--once',
            '--sleep=1',
            '--tries=3',
            '--timeout=60',
            '--no-interaction',
        ], base_path(), [
            ...$_ENV,
            'BYL_REDIS_RUNTIME_ENABLED' => '1',
            'BYL_REDIS_RUNTIME_HOST' => $this->host,
            'BYL_REDIS_RUNTIME_PORT' => (string) $this->port,
            'BYL_REDIS_RUNTIME_PREFIX' => $this->prefix,
            'REDIS_CLIENT' => 'predis',
            'REDIS_HOST' => $this->host,
            'REDIS_PORT' => (string) $this->port,
            'REDIS_CACHE_CONNECTION' => 'cache',
            'CACHE_STORE' => 'redis',
            'QUEUE_CONNECTION' => 'redis',
            'REDIS_QUEUE' => 'runtime',
            'REDIS_QUEUE_BLOCK_FOR' => '1',
            'ERP_LOCAL_ADMIN_BYPASS' => 'false',
        ]);
        $worker->setTimeout(70);
        $worker->run();

        $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput());
    }
}
