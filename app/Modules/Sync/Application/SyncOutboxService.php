<?php

namespace App\Modules\Sync\Application;

use App\Modules\Audit\Application\AuditActor;
use App\Modules\Audit\Application\AuditSource;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Runtime\Application\QueueJobConvention;
use App\Modules\Sync\Application\Contracts\SyncOutboxRepository;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use JsonException;

final class SyncOutboxService
{
    private const int MaximumAttempts = 100;

    private const int MaximumRecoveryBatch = 100;

    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly SyncOutboxRepository $outbox,
    ) {}

    /**
     * @param  array<string, mixed>  $intent
     */
    public function enqueue(array $intent): Model
    {
        $normalized = $this->normalizeIntent($intent);

        return DB::transaction(function () use ($normalized): Model {
            $existing = $this->outbox->findByIdempotencyKeyForUpdate($normalized['idempotency_key']);

            if ($existing) {
                $this->assertSameIntent($existing, $normalized);

                return $existing;
            }

            $now = CarbonImmutable::now()->startOfSecond();
            $inserted = DB::table('sync_outbox')->insertOrIgnore([
                'idempotency_key' => $normalized['idempotency_key'],
                'operation' => $normalized['operation'],
                'aggregate_type' => $normalized['aggregate_type'],
                'aggregate_id' => $normalized['aggregate_id'],
                'provider_key' => $normalized['provider_key'],
                'connection_key' => $normalized['connection_key'],
                'payload' => $normalized['payload_json'],
                'status' => SyncOutboxStatus::Pending->value,
                'attempts' => 0,
                'max_attempts' => $normalized['max_attempts'],
                'available_at' => $normalized['available_at'],
                'locked_at' => null,
                'lock_token' => null,
                'last_attempted_at' => null,
                'completed_at' => null,
                'failed_at' => null,
                'last_error_code' => null,
                'last_error_message' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $record = $this->outbox->findByIdempotencyKeyForUpdate($normalized['idempotency_key'])
                ?? throw new \LogicException('Sync outbox record was not created.');

            if ($inserted === 0) {
                $this->assertSameIntent($record, $normalized);

                return $record;
            }

            $this->auditTransition(
                $record,
                'sync.outbox.enqueued',
                null,
                'intent_enqueued',
            );

            return $record;
        });
    }

    public function claim(int $id): Model
    {
        return DB::transaction(function () use ($id): Model {
            $record = $this->lockedRecord($id);
            $now = CarbonImmutable::now()->startOfSecond();

            if ($record->status !== SyncOutboxStatus::Pending
                || $record->available_at->isAfter($now)
                || $record->attempts >= $record->max_attempts) {
                throw SyncOutboxWriteException::transitionNotAllowed();
            }

            $before = $this->auditMetadata($record);
            $lockToken = (string) Str::uuid();
            $affected = DB::table('sync_outbox')
                ->where('id', $record->id)
                ->where('status', SyncOutboxStatus::Pending->value)
                ->where('attempts', $record->attempts)
                ->where('available_at', '<=', $now)
                ->update([
                    'status' => SyncOutboxStatus::Processing->value,
                    'attempts' => $record->attempts + 1,
                    'lock_token' => $lockToken,
                    'locked_at' => $now,
                    'last_attempted_at' => $now,
                    'updated_at' => $now,
                ]);

            $this->assertSingleMutation($affected);
            $updated = $this->readRecord($record->id);
            $this->auditTransition($updated, 'sync.outbox.claimed', $before, 'eligible_intent_claimed');

            return $updated;
        });
    }

    public function complete(int $id, string $lockToken): Model
    {
        return DB::transaction(function () use ($id, $lockToken): Model {
            $record = $this->lockedProcessingRecord($id, $lockToken);
            $before = $this->auditMetadata($record);
            $now = CarbonImmutable::now()->startOfSecond();
            $affected = DB::table('sync_outbox')
                ->where('id', $record->id)
                ->where('status', SyncOutboxStatus::Processing->value)
                ->where('lock_token', $lockToken)
                ->update([
                    'status' => SyncOutboxStatus::Completed->value,
                    'completed_at' => $now,
                    'locked_at' => null,
                    'lock_token' => null,
                    'last_error_code' => null,
                    'last_error_message' => null,
                    'updated_at' => $now,
                ]);

            $this->assertSingleMutation($affected);
            $updated = $this->readRecord($record->id);
            $this->auditTransition($updated, 'sync.outbox.completed', $before, 'intent_completed');

            return $updated;
        });
    }

    public function retry(int $id, string $lockToken, string $errorCode, string $errorMessage): Model
    {
        return DB::transaction(function () use ($id, $lockToken, $errorCode, $errorMessage): Model {
            $record = $this->lockedProcessingRecord($id, $lockToken);

            if ($record->attempts >= $record->max_attempts) {
                throw SyncOutboxWriteException::transitionNotAllowed();
            }

            $before = $this->auditMetadata($record);
            $now = CarbonImmutable::now()->startOfSecond();
            $availableAt = $now->addSeconds($this->backoffSeconds($record->attempts));
            $affected = DB::table('sync_outbox')
                ->where('id', $record->id)
                ->where('status', SyncOutboxStatus::Processing->value)
                ->where('lock_token', $lockToken)
                ->update([
                    'status' => SyncOutboxStatus::Pending->value,
                    'available_at' => $availableAt,
                    'locked_at' => null,
                    'lock_token' => null,
                    'completed_at' => null,
                    'failed_at' => null,
                    'last_error_code' => $this->sanitizeErrorCode($errorCode),
                    'last_error_message' => $this->sanitizeErrorMessage($errorMessage, $lockToken),
                    'updated_at' => $now,
                ]);

            $this->assertSingleMutation($affected);
            $updated = $this->readRecord($record->id);
            $this->auditTransition($updated, 'sync.outbox.retry_scheduled', $before, 'bounded_retry_scheduled');

            return $updated;
        });
    }

    public function fail(int $id, string $lockToken, string $errorCode, string $errorMessage): Model
    {
        return DB::transaction(function () use ($id, $lockToken, $errorCode, $errorMessage): Model {
            $record = $this->lockedProcessingRecord($id, $lockToken);
            $before = $this->auditMetadata($record);
            $now = CarbonImmutable::now()->startOfSecond();
            $affected = DB::table('sync_outbox')
                ->where('id', $record->id)
                ->where('status', SyncOutboxStatus::Processing->value)
                ->where('lock_token', $lockToken)
                ->update([
                    'status' => SyncOutboxStatus::Failed->value,
                    'failed_at' => $now,
                    'locked_at' => null,
                    'lock_token' => null,
                    'last_error_code' => $this->sanitizeErrorCode($errorCode),
                    'last_error_message' => $this->sanitizeErrorMessage($errorMessage, $lockToken),
                    'updated_at' => $now,
                ]);

            $this->assertSingleMutation($affected);
            $updated = $this->readRecord($record->id);
            $this->auditTransition($updated, 'sync.outbox.failed', $before, 'terminal_failure_recorded');

            return $updated;
        });
    }

    /** @return Collection<int, Model> */
    public function recoverStale(DateTimeInterface $cutoff, int $limit): Collection
    {
        if ($limit < 1 || $limit > self::MaximumRecoveryBatch) {
            throw SyncOutboxWriteException::invalid('recovery limit');
        }

        $staleCutoff = CarbonImmutable::instance($cutoff)->startOfSecond();
        $ids = DB::table('sync_outbox')
            ->where('status', SyncOutboxStatus::Processing->value)
            ->where('locked_at', '<', $staleCutoff)
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        return $ids->map(function (int $id) use ($staleCutoff): ?Model {
            return DB::transaction(function () use ($id, $staleCutoff): ?Model {
                $record = $this->outbox->findByIdForUpdate($id);

                if (! $record
                    || $record->status !== SyncOutboxStatus::Processing
                    || ! $record->locked_at
                    || ! $record->locked_at->isBefore($staleCutoff)) {
                    return null;
                }

                $before = $this->auditMetadata($record);
                $now = CarbonImmutable::now()->startOfSecond();
                $exhausted = $record->attempts >= $record->max_attempts;
                $status = $exhausted ? SyncOutboxStatus::Failed : SyncOutboxStatus::Pending;
                $affected = DB::table('sync_outbox')
                    ->where('id', $record->id)
                    ->where('status', SyncOutboxStatus::Processing->value)
                    ->where('locked_at', $record->locked_at)
                    ->update([
                        'status' => $status->value,
                        'available_at' => $exhausted ? $record->available_at : $now,
                        'locked_at' => null,
                        'lock_token' => null,
                        'failed_at' => $exhausted ? $now : null,
                        'last_error_code' => $exhausted ? 'stale_attempts_exhausted' : 'stale_processing_recovered',
                        'last_error_message' => $exhausted
                            ? 'Processing lock expired after maximum attempts.'
                            : 'Processing lock expired and was released.',
                        'updated_at' => $now,
                    ]);

                $this->assertSingleMutation($affected);
                $updated = $this->readRecord($record->id);
                $this->auditTransition(
                    $updated,
                    'sync.outbox.stale_recovered',
                    $before,
                    $exhausted ? 'stale_attempts_exhausted' : 'stale_lock_released',
                );

                return $updated;
            });
        })->filter()->values();
    }

    /**
     * @param  array<string, mixed>  $intent
     * @return array<string, mixed>
     */
    private function normalizeIntent(array $intent): array
    {
        $idempotencyKey = $this->requiredString($intent['idempotency_key'] ?? null, 'idempotency key', 191);
        $operation = $this->requiredString($intent['operation'] ?? null, 'operation', 100);
        $aggregateType = $this->optionalString($intent['aggregate_type'] ?? null, 'aggregate type', 64);
        $aggregateId = $this->optionalString($intent['aggregate_id'] ?? null, 'aggregate id', 127);
        $providerKey = $this->optionalString($intent['provider_key'] ?? null, 'provider key', 64);
        $connectionKey = $this->optionalString($intent['connection_key'] ?? null, 'connection key', 127);
        $maxAttempts = $intent['max_attempts'] ?? 3;

        if (! is_int($maxAttempts) || $maxAttempts < 1 || $maxAttempts > self::MaximumAttempts) {
            throw SyncOutboxWriteException::invalid('max attempts');
        }

        $payload = $intent['payload'] ?? null;
        if ($payload !== null && ! is_array($payload)) {
            throw SyncOutboxWriteException::invalid('payload');
        }
        $this->assertFrozenPayloadTypes($payload);
        $payload = $payload === null ? null : $this->canonicalizePayload($payload);
        $this->assertSafePayload($payload);

        try {
            $payloadJson = $payload === null ? null : json_encode($payload, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw SyncOutboxWriteException::invalid('payload');
        }

        $availableInput = $intent['available_at'] ?? null;
        if ($availableInput !== null && ! $availableInput instanceof DateTimeInterface) {
            throw SyncOutboxWriteException::invalid('available at');
        }
        $availableAt = $availableInput instanceof DateTimeInterface
            ? CarbonImmutable::instance($availableInput)->startOfSecond()
            : CarbonImmutable::now()->startOfSecond();

        return [
            'idempotency_key' => $idempotencyKey,
            'operation' => $operation,
            'aggregate_type' => $aggregateType,
            'aggregate_id' => $aggregateId,
            'provider_key' => $providerKey,
            'connection_key' => $connectionKey,
            'payload' => $payload,
            'payload_json' => $payloadJson,
            'max_attempts' => $maxAttempts,
            'available_at' => $availableAt,
            'compare_available_at' => $availableInput !== null,
        ];
    }

    /** @param array<string, mixed> $intent */
    private function assertSameIntent(Model $record, array $intent): void
    {
        $matches = $record->operation === $intent['operation']
            && $record->aggregate_type === $intent['aggregate_type']
            && $record->aggregate_id === $intent['aggregate_id']
            && $record->provider_key === $intent['provider_key']
            && $record->connection_key === $intent['connection_key']
            && $this->payloadsMatch($record->payload, $intent['payload'])
            && $record->max_attempts === $intent['max_attempts']
            && (! $intent['compare_available_at'] || $record->available_at->equalTo($intent['available_at']));

        if (! $matches) {
            throw SyncOutboxWriteException::idempotencyConflict();
        }
    }

    private function payloadsMatch(?array $stored, ?array $requested): bool
    {
        if ($stored === null || $requested === null) {
            return $stored === $requested;
        }

        return $this->canonicalizePayload($stored) === $requested;
    }

    private function lockedRecord(int $id): Model
    {
        $record = $this->outbox->findByIdForUpdate($id);

        if (! $record) {
            throw SyncOutboxWriteException::transitionNotAllowed();
        }

        return $record;
    }

    private function lockedProcessingRecord(int $id, string $lockToken): Model
    {
        $record = $this->lockedRecord($id);

        if ($record->status !== SyncOutboxStatus::Processing) {
            throw SyncOutboxWriteException::transitionNotAllowed();
        }

        if ($lockToken === '' || ! is_string($record->lock_token) || ! hash_equals($record->lock_token, $lockToken)) {
            throw SyncOutboxWriteException::lockMismatch();
        }

        return $record;
    }

    private function readRecord(int $id): Model
    {
        return $this->outbox->findOrFail($id);
    }

    private function assertSingleMutation(int $affected): void
    {
        if ($affected !== 1) {
            throw SyncOutboxWriteException::concurrentMutation();
        }
    }

    private function auditTransition(Model $record, string $action, ?array $before, string $reason): void
    {
        $this->audit->record(
            AuditActor::system(AuditSource::SyncOutbox),
            $action,
            \App\Models\SyncOutbox::class,
            $record->id,
            before: $before,
            after: $this->auditMetadata($record),
            reason: $reason,
        );
    }

    /** @return array<string, mixed> */
    private function auditMetadata(Model $record): array
    {
        return [
            'status' => $record->status->value,
            'attempts' => $record->attempts,
            'max_attempts' => $record->max_attempts,
            'available_at' => $record->available_at?->toIso8601String(),
            'locked' => $record->locked_at !== null,
            'operation' => $record->operation,
            'provider_key' => $record->provider_key,
            'aggregate_type' => $record->aggregate_type,
            'error_code' => $record->last_error_code,
        ];
    }

    private function backoffSeconds(int $attempts): int
    {
        $index = min(max($attempts - 1, 0), count(QueueJobConvention::BackoffSeconds) - 1);

        return QueueJobConvention::BackoffSeconds[$index];
    }

    private function requiredString(mixed $value, string $field, int $maximum): string
    {
        if (! is_string($value) || trim($value) === '' || mb_strlen(trim($value)) > $maximum) {
            throw SyncOutboxWriteException::invalid($field);
        }

        return trim($value);
    }

    private function optionalString(mixed $value, string $field, int $maximum): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value) || mb_strlen(trim($value)) > $maximum) {
            throw SyncOutboxWriteException::invalid($field);
        }

        $normalized = trim($value);

        return $normalized === '' ? null : $normalized;
    }

    private function sanitizeErrorCode(string $errorCode): string
    {
        $normalized = Str::of($errorCode)
            ->lower()
            ->replaceMatches('/[^a-z0-9_.-]+/', '_')
            ->trim('_')
            ->limit(100, '')
            ->toString();

        return $normalized === '' ? 'unknown_error' : $normalized;
    }

    private function sanitizeErrorMessage(string $errorMessage, string $lockToken): string
    {
        $withoutStack = preg_replace('/(?:\bStack trace\s*:|(?:^|\R)\s*#\d+\s).*/isu', '', $errorMessage) ?? '';

        if ($this->containsSensitiveErrorDetails($withoutStack, $lockToken)) {
            return 'Sensitive error details were redacted.';
        }

        $sanitized = $withoutStack;
        $sanitized = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $sanitized) ?? '';
        $sanitized = preg_replace('/\s+/u', ' ', trim($sanitized)) ?? '';

        return mb_substr($sanitized, 0, 500);
    }

    private function containsSensitiveErrorDetails(string $message, string $lockToken): bool
    {
        if ($lockToken !== '' && str_contains($message, $lockToken)) {
            return true;
        }

        $patterns = [
            '/\bauthorization\s*[:=]/iu',
            '/\bbearer\s+\S+/iu',
            '/\b(?:set-)?cookie\s*[:=]/iu',
            '/\b(?:password(?:_confirmation)?|token|secret|api[_-]?key|client[_-]?secret|access[_-]?token|refresh[_-]?token|private[_-]?key|credential)\s*[:=]/iu',
            '~\b[a-z][a-z0-9+.-]*://[^\s/:@]+:[^\s/@]+@~iu',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $message) === 1) {
                return true;
            }
        }

        return false;
    }

    private function assertFrozenPayloadTypes(mixed $value): void
    {
        if (is_array($value)) {
            foreach ($value as $key => $nested) {
                if (! is_int($key) && ! is_string($key)) {
                    throw SyncOutboxWriteException::invalid('payload');
                }

                $this->assertFrozenPayloadTypes($nested);
            }

            return;
        }

        if (is_float($value) && ! is_finite($value)) {
            throw SyncOutboxWriteException::invalid('payload');
        }

        if (! is_string($value) && ! is_int($value) && ! is_float($value) && ! is_bool($value) && $value !== null) {
            throw SyncOutboxWriteException::invalid('payload');
        }
    }

    /** @param array<mixed> $payload */
    private function assertSafePayload(?array $payload): void
    {
        if ($payload === null) {
            return;
        }

        foreach ($payload as $key => $value) {
            if (is_string($key) && $this->isSensitiveKey($key)) {
                throw SyncOutboxWriteException::invalid('payload');
            }

            if (is_array($value)) {
                $this->assertSafePayload($value);
            }
        }
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

        foreach (['password', 'session', 'csrf', 'xsrf', 'token', 'authorization', 'cookie', 'secret', 'private_key', 'api_key', 'credential', 'request_payload', 'request_body', 'headers'] as $fragment) {
            if (str_contains($normalized, $fragment)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<mixed> $payload */
    private function canonicalizePayload(array $payload): array
    {
        if (array_is_list($payload)) {
            return array_map(
                fn (mixed $value): mixed => is_array($value) ? $this->canonicalizePayload($value) : $value,
                $payload,
            );
        }

        ksort($payload);

        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = $this->canonicalizePayload($value);
            }
        }

        return $payload;
    }
}
