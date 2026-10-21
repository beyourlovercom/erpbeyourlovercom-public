<?php

namespace App\Modules\Runtime\Application;

use App\Modules\Runtime\Application\Contracts\RedisRuntimeProbe;
use Illuminate\Contracts\Config\Repository;

class RuntimeHealthService
{
    public function __construct(
        private readonly Repository $config,
        private readonly RedisClientCapability $clientCapability,
        private readonly RedisRuntimeProbe $redis,
    ) {}

    /**
     * @return array{status: 'ok'|'degraded', checks: array<string, array{status: 'ok'|'degraded', code: string}>}
     */
    public function check(): array
    {
        $clientCode = $this->clientCapability->code((string) $this->config->get('database.redis.client'));
        $checks = [
            'cache_configuration' => $this->result(
                $this->config->get('cache.default') === 'redis',
                'configured',
                'expected_redis',
            ),
            'queue_configuration' => $this->result(
                $this->config->get('queue.default') === 'redis',
                'configured',
                'expected_redis',
            ),
            'redis_client' => $this->result($clientCode === 'supported', 'supported', $clientCode),
            'redis_default' => $this->connectionResult('default', $clientCode),
            'redis_cache' => $this->connectionResult('cache', $clientCode),
            'queue_contract' => $this->queueContractResult(),
        ];

        return [
            'status' => collect($checks)->every(fn (array $check): bool => $check['status'] === 'ok') ? 'ok' : 'degraded',
            'checks' => $checks,
        ];
    }

    /** @return array{status: 'ok'|'degraded', code: string} */
    private function connectionResult(string $connection, string $clientCode): array
    {
        if ($clientCode !== 'supported') {
            return $this->result(false, 'reachable', 'client_unavailable');
        }

        return $this->result(
            $this->redis->connectionIsAvailable($connection),
            'reachable',
            'unavailable',
        );
    }

    /** @return array{status: 'ok'|'degraded', code: string} */
    private function queueContractResult(): array
    {
        $connection = $this->config->get('queue.connections.redis.connection');
        $queue = $this->config->get('queue.connections.redis.queue');
        $retryAfter = $this->config->get('queue.connections.redis.retry_after');
        $blockFor = $this->config->get('queue.connections.redis.block_for');
        $afterCommit = $this->config->get('queue.connections.redis.after_commit');
        $connectionConfiguration = is_string($connection)
            ? $this->config->get("database.redis.{$connection}")
            : null;
        $readTimeout = is_array($connectionConfiguration)
            ? ($connectionConfiguration['read_timeout'] ?? null)
            : null;
        $names = array_map(fn (QueueName $queue): string => $queue->value, QueueName::cases());
        $backoff = QueueJobConvention::BackoffSeconds;
        $valid = is_array($connectionConfiguration)
            && is_string($queue)
            && trim($queue) !== ''
            && in_array($queue, $names, true)
            && is_int($retryAfter)
            && is_int($blockFor)
            && $retryAfter > 0
            && $blockFor > 0
            && is_numeric($readTimeout)
            && (float) $readTimeout > $blockFor
            && $retryAfter > QueueJobConvention::TimeoutSeconds
            && QueueJobConvention::Tries > 0
            && $backoff !== []
            && collect($backoff)->every(
                fn (mixed $seconds): bool => is_int($seconds)
                    && $seconds > 0
                    && $seconds <= QueueJobConvention::TimeoutSeconds,
            )
            && $afterCommit === true
            && count($names) === count(array_unique($names))
            && collect($names)->every(fn (string $name): bool => trim($name) !== '');

        return $this->result($valid, 'valid', 'invalid');
    }

    /** @return array{status: 'ok'|'degraded', code: string} */
    private function result(bool $healthy, string $healthyCode, string $degradedCode): array
    {
        return [
            'status' => $healthy ? 'ok' : 'degraded',
            'code' => $healthy ? $healthyCode : $degradedCode,
        ];
    }
}
