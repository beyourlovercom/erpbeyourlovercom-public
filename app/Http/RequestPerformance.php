<?php

namespace App\Http;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

final class RequestPerformance
{
    private bool $active = false;

    private int $queryCount = 0;

    private float $queryTimeMs = 0.0;

    private float $startedAt = 0.0;

    public function __construct()
    {
        DB::listen(function (QueryExecuted $query): void {
            $this->recordQuery((float) $query->time);
        });
    }

    public function begin(): void
    {
        $this->active = true;
        $this->queryCount = 0;
        $this->queryTimeMs = 0.0;
        $this->startedAt = microtime(true);
    }

    public function recordQuery(float $timeMs): void
    {
        if (! $this->active) {
            return;
        }

        $this->queryCount++;
        $this->queryTimeMs += $timeMs;
    }

    /** @return array{duration_ms:float,query_count:int,query_time_ms:float,peak_memory_bytes:int} */
    public function finish(): array
    {
        $metrics = [
            'duration_ms' => round((microtime(true) - $this->startedAt) * 1000, 2),
            'query_count' => $this->queryCount,
            'query_time_ms' => round($this->queryTimeMs, 2),
            'peak_memory_bytes' => memory_get_peak_usage(true),
        ];
        $this->active = false;

        return $metrics;
    }

    /** @return array{query_count:int,query_time_ms:float,duration_ms:float} */
    public function budgets(): array
    {
        return [
            'query_count' => (int) config('observability.query_budget'),
            'query_time_ms' => (float) config('observability.query_time_budget_ms'),
            'duration_ms' => (float) config('observability.duration_budget_ms'),
        ];
    }

    /** @return list<string> */
    public function breaches(array $metrics): array
    {
        $budgets = $this->budgets();
        $breaches = [];

        if ($metrics['query_count'] > $budgets['query_count']) {
            $breaches[] = 'query_count';
        }
        if ($metrics['query_time_ms'] > $budgets['query_time_ms']) {
            $breaches[] = 'query_time_ms';
        }
        if ($metrics['duration_ms'] > $budgets['duration_ms']) {
            $breaches[] = 'duration_ms';
        }

        return $breaches;
    }

    public function exceeds(array $metrics): bool
    {
        return $this->breaches($metrics) !== [];
    }
}
