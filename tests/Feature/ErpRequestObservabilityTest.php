<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Mockery;
use Tests\TestCase;

final class ErpRequestObservabilityTest extends TestCase
{
    public function test_valid_request_id_is_reused_and_returned(): void
    {
        Route::get('/testing/observability', fn () => response()->json(['ok' => true]));

        $this->get('/testing/observability', ['X-ERP-Request-ID' => 'test-request-123'])
            ->assertOk()
            ->assertHeader('X-ERP-Request-ID', 'test-request-123');
    }

    public function test_invalid_request_id_is_replaced_with_safe_generated_id(): void
    {
        Route::get('/testing/observability-invalid', fn () => response()->json(['ok' => true]));

        $response = $this->get('/testing/observability-invalid', ['X-ERP-Request-ID' => 'bad id']);

        $response->assertOk();
        $requestId = (string) $response->headers->get('X-ERP-Request-ID');
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9._:-]{8,128}$/', $requestId);
        $this->assertNotSame('bad id', $requestId);
    }

    public function test_inertia_500_is_safe_and_contains_error_id(): void
    {
        config()->set('app.debug', false);
        Route::get('/testing/inertia-error', fn () => throw new \RuntimeException('secret exception details'));

        $response = $this->withHeaders([
            'X-Inertia' => 'true',
            'X-Requested-With' => 'XMLHttpRequest',
            'Accept' => 'text/html, application/xhtml+xml',
            'X-ERP-Request-ID' => 'test-inertia-500',
        ])->get('/testing/inertia-error');

        $response->assertStatus(500)
            ->assertHeader('X-ERP-Request-ID', 'test-inertia-500')
            ->assertJsonPath('component', 'Errors/ServerError')
            ->assertJsonPath('props.errorId', 'test-inertia-500')
            ->assertJsonMissing(['message' => 'secret exception details']);
    }

    public function test_performance_warning_records_breaches_budgets_and_release_revision(): void
    {
        config()->set('observability.query_budget', 0);
        config()->set('observability.query_time_budget_ms', 0);
        config()->set('observability.duration_budget_ms', 0);
        Log::spy();

        Route::get('/testing/observability-budget', function () {
            DB::select('select 1');

            return response()->json(['ok' => true]);
        });

        $this->get('/testing/observability-budget', ['X-ERP-Request-ID' => 'test-budget-123'])
            ->assertOk()
            ->assertHeader('X-ERP-Request-ID', 'test-budget-123');

        Log::shouldHaveReceived('warning')->with(
            'erp.request.performance_budget_exceeded',
            Mockery::on(function (array $context): bool {
                return $context['request_id'] === 'test-budget-123'
                    && is_string($context['revision'] ?? null)
                    && ($context['revision'] ?? '') !== ''
                    && in_array('query_count', $context['breaches'] ?? [], true)
                    && ($context['budgets']['query_count'] ?? null) === 0;
            }),
        )->once();
    }

    public function test_non_500_http_status_is_not_rewritten_as_inertia_500(): void
    {
        config()->set('app.debug', false);
        Route::get('/testing/not-found', fn () => abort(404));

        $this->withHeaders([
            'X-Inertia' => 'true',
            'X-Requested-With' => 'XMLHttpRequest',
            'Accept' => 'text/html, application/xhtml+xml',
        ])->get('/testing/not-found')->assertNotFound();
    }
}
