<?php

namespace App\Modules\Audit\Application;

use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Audit\Application\Contracts\AuditWriter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

final class AuditService implements AuditRecorder
{
    /** @var list<string> */
    private const SENSITIVE_KEYS = [
        'password',
        'password_confirmation',
        'current_password',
        'new_password',
        'session',
        'session_id',
        'php_session_id',
        'remember_token',
        'csrf',
        'csrf_token',
        'xsrf_token',
        'token',
        'authorization',
        'authorization_header',
        'cookie',
        'cookies',
        'set_cookie',
        'api_key',
        'api_token',
        'access_token',
        'refresh_token',
        'secret',
        'client_secret',
        'private_key',
        'payload',
        'request',
        'request_payload',
        'request_body',
        'raw_body',
        'body',
        'headers',
    ];

    public function __construct(private readonly AuditWriter $writer) {}

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public function record(
        AuditActor $actor,
        string $action,
        string $entityType,
        int|string|null $entityId = null,
        ?array $before = null,
        ?array $after = null,
        ?string $reason = null,
    ): mixed {
        if (! preg_match('/^[a-z][a-z0-9_.-]*$/', $action)) {
            throw new InvalidArgumentException('Audit action must use a stable machine-readable key.');
        }

        if (trim($entityType) === '') {
            throw new InvalidArgumentException('Audit entity type is required.');
        }

        if ($reason !== null && ! preg_match('/^[a-z][a-z0-9_.-]*$/', $reason)) {
            throw new InvalidArgumentException('Audit reason must use a stable machine-readable key.');
        }

        $request = app()->bound('request') ? request() : null;

        try {
            return $this->writer->write([
                'actor_id' => $actor->userId,
                'action' => $action,
                'entity_type' => $entityType,
                'entity_id' => $entityId === null ? null : (string) $entityId,
                'before' => $this->sanitize($before),
                'after' => $this->sanitize($after),
                'source' => $actor->source->value,
                'request_id' => $request instanceof Request ? $this->requestId($request) : null,
                'ip_address' => $request instanceof Request ? $request->ip() : null,
                'user_agent' => $request instanceof Request ? $request->userAgent() : null,
                'reason' => $reason,
                'created_at' => now(),
            ]);
        } catch (Throwable $exception) {
            Log::error('audit.write_failed', ['exception_type' => $exception::class]);

            throw AuditWriteException::failed();
        }
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     * @return array<string, mixed>|null
     */
    private function sanitize(?array $metadata): ?array
    {
        if ($metadata === null) {
            return null;
        }

        $sanitized = [];

        foreach ($metadata as $key => $value) {
            if (is_string($key) && $this->isSensitiveKey($key)) {
                continue;
            }

            $sanitized[$key] = is_array($value) ? $this->sanitize($value) : $value;
        }

        return $sanitized;
    }

    private function isSensitiveKey(string $key): bool
    {
        $withAcronymBoundaries = preg_replace('/([A-Z]+)([A-Z][a-z])/', '$1_$2', $key);
        $withWordBoundaries = preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $withAcronymBoundaries ?? $key);
        $normalized = Str::of($withWordBoundaries ?? $key)
            ->replace(['.', '-'], '_')
            ->lower()
            ->replaceMatches('/_+/', '_')
            ->trim('_')
            ->toString();

        if (in_array($normalized, self::SENSITIVE_KEYS, true)) {
            return true;
        }

        foreach (['password', 'session', 'csrf', 'xsrf', 'token', 'authorization', 'cookie', 'secret', 'private_key', 'payload'] as $fragment) {
            if (str_contains($normalized, $fragment)) {
                return true;
            }
        }

        return false;
    }

    private function requestId(Request $request): string
    {
        $existing = $request->attributes->get('audit_request_id');

        if (is_string($existing) && Str::isUuid($existing)) {
            return $existing;
        }

        $header = $request->header('X-Request-ID');
        $requestId = is_string($header) && Str::isUuid($header)
            ? $header
            : (string) Str::uuid();

        $request->attributes->set('audit_request_id', $requestId);

        return $requestId;
    }
}
