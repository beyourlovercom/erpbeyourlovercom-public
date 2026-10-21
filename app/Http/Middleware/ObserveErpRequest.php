<?php

namespace App\Http\Middleware;

use App\Http\RequestPerformance;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

final class ObserveErpRequest
{
    public function __construct(private readonly RequestPerformance $performance) {}

    public function handle(Request $request, Closure $next): Response
    {
        $requestId = $this->requestId($request->header((string) config('observability.request_id_header')));
        $request->attributes->set('erp_request_id', $requestId);
        $this->performance->begin();

        try {
            $response = $next($request);
        } catch (Throwable $exception) {
            if ($this->statusFor($exception) >= 500) {
                $metrics = $this->performance->finish();
                $this->logServerError($request, $exception, $metrics);
            } else {
                $this->performance->finish();
            }
            throw $exception;
        }

        $metrics = $this->performance->finish();
        if ($this->performance->exceeds($metrics)) {
            Log::warning('erp.request.performance_budget_exceeded', [
                ...$this->context($request, $metrics),
                'breaches' => $this->performance->breaches($metrics),
                'budgets' => $this->performance->budgets(),
            ]);
        }

        $response->headers->set((string) config('observability.request_id_header'), $requestId);
        if ((bool) config('observability.expose_diagnostics', false)) {
            $response->headers->set('Server-Timing', sprintf('app;dur=%.2f, db;dur=%.2f', $metrics['duration_ms'], $metrics['query_time_ms']));
            $response->headers->set('X-ERP-Query-Count', (string) $metrics['query_count']);
        }

        return $response;
    }

    private function requestId(?string $incoming): string
    {
        return is_string($incoming) && preg_match('/^[A-Za-z0-9._:-]{8,128}$/D', $incoming)
            ? $incoming
            : (string) Str::uuid();
    }

    private function statusFor(Throwable $exception): int
    {
        return $exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : 500;
    }

    /** @param array<string,mixed> $metrics */
    private function logServerError(Request $request, Throwable $exception, array $metrics): void
    {
        Log::error('erp.request.unhandled_5xx', [
            ...$this->context($request, $metrics),
            'error_id' => $request->attributes->get('erp_request_id'),
            'exception_class' => $exception::class,
            'exception_message' => $exception->getMessage(),
        ]);
    }

    private function revision(): ?string
    {
        $configured = config('observability.revision');
        if (is_string($configured) && trim($configured) !== '') {
            return trim($configured);
        }

        $resolved = realpath(base_path());

        return is_string($resolved) && $resolved !== '' ? basename($resolved) : null;
    }

    /** @param array<string,mixed> $metrics @return array<string,mixed> */
    private function context(Request $request, array $metrics): array
    {
        return [
            'request_id' => $request->attributes->get('erp_request_id'),
            'route' => $request->route()?->getName(),
            'path' => $request->path(),
            'method' => $request->method(),
            'user_id' => $request->user()?->getAuthIdentifier(),
            'revision' => $this->revision(),
            ...$metrics,
        ];
    }
}
