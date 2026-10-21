<?php

namespace Tests\Unit\Runtime;

use App\Support\Runtime\QueueJobConvention;
use App\Support\Runtime\QueueName;
use App\Support\Runtime\RedisClientCapability;
use App\Support\Runtime\RedisRuntimeProbe;
use App\Support\Runtime\RuntimeHealthService;
use Illuminate\Config\Repository;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Redis\RedisManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RuntimeHealthServiceTest extends TestCase
{
    public function test_declared_queue_names_are_unique_non_empty_and_infrastructure_only(): void
    {
        $names = array_map(fn (QueueName $queue): string => $queue->value, QueueName::cases());

        $this->assertSame(['default', 'runtime'], $names);
        $this->assertCount(count(array_unique($names)), $names);
        $this->assertNotContains('', $names);
    }

    public function test_future_job_retry_timeout_and_backoff_conventions_are_bounded(): void
    {
        $this->assertSame(3, QueueJobConvention::Tries);
        $this->assertGreaterThan(QueueJobConvention::TimeoutSeconds, 90);
        $this->assertNotEmpty(QueueJobConvention::BackoffSeconds);
        $this->assertContainsOnly('int', QueueJobConvention::BackoffSeconds);
        $this->assertGreaterThan(0, min(QueueJobConvention::BackoffSeconds));
        $this->assertLessThanOrEqual(60, max(QueueJobConvention::BackoffSeconds));
    }

    public function test_client_capability_reports_only_stable_codes(): void
    {
        $capability = new RedisClientCapability;

        $this->assertSame('unsupported', $capability->code(''));
        $this->assertSame('unsupported', $capability->code('unknown'));
        $this->assertSame(
            extension_loaded('redis') ? 'supported' : 'extension_unavailable',
            $capability->code('phpredis'),
        );
        $this->assertSame(
            class_exists('Predis\\Client') ? 'supported' : 'client_unavailable',
            $capability->code('predis'),
        );
    }

    public function test_fully_healthy_result_uses_the_stable_redacted_schema(): void
    {
        $result = $this->service()->check();

        $this->assertSame('ok', $result['status']);
        $this->assertSame([
            'cache_configuration',
            'queue_configuration',
            'redis_client',
            'redis_default',
            'redis_cache',
            'queue_contract',
        ], array_keys($result['checks']));
        foreach ($result['checks'] as $check) {
            $this->assertSame(['status', 'code'], array_keys($check));
            $this->assertSame('ok', $check['status']);
        }
    }

    #[DataProvider('degradedCases')]
    public function test_each_runtime_failure_is_reported_with_a_stable_code(
        array $overrides,
        array $connections,
        string $check,
        string $code,
    ): void {
        $result = $this->service($overrides, $connections)->check();

        $this->assertSame('degraded', $result['status']);
        $this->assertSame(['status' => 'degraded', 'code' => $code], $result['checks'][$check]);
    }

    public static function degradedCases(): array
    {
        return [
            'default unavailable' => [[], ['default' => false, 'cache' => true], 'redis_default', 'unavailable'],
            'cache unavailable' => [[], ['default' => true, 'cache' => false], 'redis_cache', 'unavailable'],
            'unsupported client' => [['database.redis.client' => 'unknown'], [], 'redis_client', 'unsupported'],
            'queue misconfigured' => [['queue.default' => 'database'], [], 'queue_configuration', 'expected_redis'],
            'cache misconfigured' => [['cache.default' => 'database'], [], 'cache_configuration', 'expected_redis'],
            'retry is unbounded' => [['queue.connections.redis.retry_after' => 0], [], 'queue_contract', 'invalid'],
            'read timeout equals blocking duration' => [['database.redis.default.read_timeout' => 5], [], 'queue_contract', 'invalid'],
            'read timeout is below blocking duration' => [['database.redis.default.read_timeout' => 4.5], [], 'queue_contract', 'invalid'],
            'retry equals job timeout' => [['queue.connections.redis.retry_after' => 60], [], 'queue_contract', 'invalid'],
            'retry is below job timeout' => [['queue.connections.redis.retry_after' => 59], [], 'queue_contract', 'invalid'],
            'after commit disabled' => [['queue.connections.redis.after_commit' => false], [], 'queue_contract', 'invalid'],
            'queue connection missing' => [['queue.connections.redis.connection' => 'missing'], [], 'queue_contract', 'invalid'],
            'queue name empty' => [['queue.connections.redis.queue' => ''], [], 'queue_contract', 'invalid'],
            'queue name undeclared' => [['queue.connections.redis.queue' => 'sync'], [], 'queue_contract', 'invalid'],
        ];
    }

    public function test_probe_catches_connection_exceptions_without_exposing_them(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('command')->willThrowException(
            new \RuntimeException('redis://admin:plain-secret@fixture.invalid:6379/9'),
        );
        $manager = $this->createMock(RedisManager::class);
        $manager->method('connection')->willReturn($connection);
        $probe = new RedisRuntimeProbe($manager);

        $this->assertFalse($probe->connectionIsAvailable('default'));
        $this->assertStringNotContainsString('plain-secret', json_encode([
            'status' => 'degraded',
            'code' => $probe->connectionIsAvailable('default') ? 'reachable' : 'unavailable',
        ]));
    }

    private function service(
        array $overrides = [],
        array $connections = ['default' => true, 'cache' => true],
        ?RedisRuntimeProbe $probe = null,
    ): RuntimeHealthService {
        $config = new Repository(array_replace_recursive([
            'cache' => ['default' => 'redis'],
            'queue' => [
                'default' => 'redis',
                'connections' => ['redis' => [
                    'connection' => 'default',
                    'queue' => 'default',
                    'retry_after' => 90,
                    'block_for' => 5,
                    'after_commit' => true,
                ]],
            ],
            'database' => ['redis' => [
                'client' => 'phpredis',
                'default' => ['read_timeout' => 6.0],
            ]],
        ], $this->nested($overrides)));
        $capability = $this->createMock(RedisClientCapability::class);
        $capability->method('code')->willReturnCallback(
            fn (string $client): string => $client === 'unknown' ? 'unsupported' : 'supported',
        );
        $probe ??= $this->createMock(RedisRuntimeProbe::class);
        $probe->method('connectionIsAvailable')->willReturnCallback(
            fn (string $connection): bool => $connections[$connection] ?? true,
        );

        return new RuntimeHealthService($config, $capability, $probe);
    }

    private function nested(array $overrides): array
    {
        $result = [];
        foreach ($overrides as $key => $value) {
            data_set($result, $key, $value);
        }

        return $result;
    }
}
