<?php

use App\Modules\Identity\Experience\Console\Commands\CreateErpUser;
use App\Modules\Identity\Experience\Console\Commands\StaffAccessReadinessAuditCommand;
use App\Modules\Logistics\Experience\Console\Commands\AutomaticAllocationCommand;
use App\Modules\Runtime\Experience\Console\Commands\RuntimeHealthCommand;
use App\Modules\Identity\Experience\Middleware\ApplyLocalAdminBypass;
use App\Modules\Identity\Experience\Middleware\EnsureActiveAuthenticatedUser;
use App\Modules\Identity\Experience\Middleware\RequireAnyPermission;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\ObserveErpRequest;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands([
        CreateErpUser::class,
        StaffAccessReadinessAuditCommand::class,
        RuntimeHealthCommand::class,
        AutomaticAllocationCommand::class,
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            ApplyLocalAdminBypass::class,
            EnsureActiveAuthenticatedUser::class,
            HandleInertiaRequests::class,
        ]);
        $middleware->prepend(ObserveErpRequest::class);
        $middleware->alias(['any.permission' => RequireAnyPermission::class]);
        $middleware->prependToPriorityList(
            AuthenticatesRequests::class,
            ApplyLocalAdminBypass::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
        $exceptions->render(function (\Throwable $exception, Request $request) {
            // Keep Laravel's native validation, authentication and authorization
            // responses. These are expected client errors, not server outages.
            // In particular, Inertia validation failures must redirect back with
            // session errors rather than rendering Errors/ServerError as HTTP 500.
            if ($exception instanceof \Illuminate\Validation\ValidationException
                || $exception instanceof \Illuminate\Auth\AuthenticationException
                || $exception instanceof \Illuminate\Auth\Access\AuthorizationException
                || $exception instanceof \Illuminate\Database\Eloquent\ModelNotFoundException) {
                return null;
            }

            $status = $exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : 500;
            if ($request->expectsJson() || ! $request->header('X-Inertia') || config('app.debug') || $status < 500) {
                return null;
            }

            $errorId = (string) $request->attributes->get('erp_request_id');

            $response = \Inertia\Inertia::render('Errors/ServerError', [
                'errorId' => $errorId,
            ])->toResponse($request);
            $response->setStatusCode($status);
            $response->headers->set((string) config('observability.request_id_header'), $errorId);

            return $response;
        });
        $exceptions->respond(function (\Symfony\Component\HttpFoundation\Response $response): \Symfony\Component\HttpFoundation\Response {
            $requestId = request()->attributes->get('erp_request_id');
            if (is_string($requestId) && $requestId !== '') {
                $response->headers->set((string) config('observability.request_id_header'), $requestId);
            }

            return $response;
        });
    })->create();
