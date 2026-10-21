<?php

namespace Tests\Unit;

use App\Http\RequestPerformance;
use Tests\TestCase;

final class RequestPerformanceTest extends TestCase
{
    public function test_budget_warning_predicate_uses_query_count_time_and_duration(): void
    {
        config()->set('observability.query_budget', 2);
        config()->set('observability.query_time_budget_ms', 10);
        config()->set('observability.duration_budget_ms', 100);

        $performance = app(RequestPerformance::class);
        $performance->begin();
        $performance->recordQuery(11);
        $metrics = $performance->finish();

        $this->assertSame(1, $metrics['query_count']);
        $this->assertSame([
            'query_count' => 2,
            'query_time_ms' => 10.0,
            'duration_ms' => 100.0,
        ], $performance->budgets());
        $this->assertSame(['query_time_ms'], $performance->breaches($metrics));
        $this->assertTrue($performance->exceeds($metrics));
    }
}
