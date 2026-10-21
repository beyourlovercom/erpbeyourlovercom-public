<?php

namespace Tests\Feature\Sync;

use App\Models\AuditRecord;
use App\Models\SyncOutbox;
use App\Support\Audit\AuditService;
use App\Support\Audit\AuditWriteException;
use App\Support\Audit\AuditWriter;
use App\Support\Audit\EloquentAuditWriter;
use App\Support\Sync\SyncOutboxService;
use App\Support\Sync\SyncOutboxStatus;
use App\Support\Sync\SyncOutboxWriteException;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;
use Tests\TestCase;

class SyncOutboxFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_model_casts_are_readable_and_match_the_frozen_contract(): void
    {
        CarbonImmutable::setTestNow('2026-08-01 10:00:00');
        $record = $this->service()->enqueue($this->intent());

        $this->assertSame(['nested' => ['safe' => true], 'sku' => 'SKU-1'], $record->payload);
        $this->assertSame(SyncOutboxStatus::Pending, $record->status);
        $this->assertSame(0, $record->attempts);
        $this->assertSame(3, $record->max_attempts);
        $this->assertInstanceOf(CarbonImmutable::class, $record->available_at);
        $this->assertInstanceOf(CarbonImmutable::class, $record->created_at);
        $this->assertInstanceOf(CarbonImmutable::class, $record->updated_at);
        $this->assertNull($record->locked_at);
        $this->assertNull($record->completed_at);
        $this->assertNull($record->failed_at);
    }

    public function test_direct_model_and_builder_writes_are_blocked(): void
    {
        $record = $this->service()->enqueue($this->intent());

        $this->assertBlocked(fn () => SyncOutbox::create(['operation' => 'bypass']));
        $this->assertBlocked(fn () => SyncOutbox::query()->insert(['operation' => 'bypass']));
        $this->assertBlocked(fn () => $record->update(['status' => SyncOutboxStatus::Completed->value]));
        $this->assertBlocked(function () use ($record): void {
            $record->status = SyncOutboxStatus::Completed;
            $record->save();
        });
        $this->assertBlocked(fn () => $record->delete());
        $this->assertBlocked(fn () => SyncOutbox::query()->whereKey($record->id)->update(['status' => 'completed']));
        $this->assertBlocked(fn () => SyncOutbox::query()->whereKey($record->id)->delete());
        $this->assertBlocked(fn () => SyncOutbox::query()->updateOrInsert(['id' => $record->id], ['status' => 'completed']));
        $this->assertBlocked(fn () => SyncOutbox::query()->truncate());

        $this->assertDatabaseHas('sync_outbox', [
            'id' => $record->id,
            'status' => SyncOutboxStatus::Pending->value,
        ]);
    }

    public function test_quiet_and_event_disabled_writes_are_blocked(): void
    {
        $record = $this->service()->enqueue($this->intent());

        $this->assertBlocked(fn () => (new SyncOutbox)->saveQuietly());
        $this->assertBlocked(fn () => SyncOutbox::query()->forceCreateQuietly(['operation' => 'bypass']));
        $this->assertBlocked(fn () => $record->updateQuietly(['status' => 'completed']));
        $this->assertBlocked(fn () => $record->deleteQuietly());
        $this->assertBlocked(fn () => SyncOutbox::withoutEvents(fn () => SyncOutbox::create(['operation' => 'bypass'])));
        $this->assertBlocked(fn () => SyncOutbox::withoutEvents(fn () => $record->save()));
        $this->assertBlocked(fn () => SyncOutbox::withoutEvents(fn () => $record->delete()));

        $this->assertSame(SyncOutboxStatus::Pending, $record->fresh()->status);
        $this->assertDatabaseCount('sync_outbox', 1);
    }

    public function test_enqueue_is_idempotent_for_the_same_canonical_intent(): void
    {
        $first = $this->service()->enqueue($this->intent());
        $second = $this->service()->enqueue($this->intent([
            'payload' => ['sku' => 'SKU-1', 'nested' => ['safe' => true]],
        ]));

        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('sync_outbox', 1);
        $this->assertDatabaseCount('audit_records', 1);
        $this->assertDatabaseHas('audit_records', ['action' => 'sync.outbox.enqueued']);
    }

    public function test_enqueue_rejects_conflicting_intent_for_the_same_key(): void
    {
        $this->service()->enqueue($this->intent());

        $this->expectException(SyncOutboxWriteException::class);
        $this->expectExceptionMessage('different sync intent');

        $this->service()->enqueue($this->intent(['operation' => 'catalog.product.delete']));
    }

    public function test_idempotency_distinguishes_null_payload_from_empty_array(): void
    {
        $this->service()->enqueue($this->intent([
            'idempotency_key' => 'null-versus-empty',
            'payload' => null,
        ]));

        $this->expectException(SyncOutboxWriteException::class);
        $this->expectExceptionMessage('different sync intent');

        $this->service()->enqueue($this->intent([
            'idempotency_key' => 'null-versus-empty',
            'payload' => [],
        ]));
    }

    public function test_database_unique_constraint_remains_the_final_idempotency_defense(): void
    {
        $record = $this->service()->enqueue($this->intent());

        try {
            DB::table('sync_outbox')->insert([
                'idempotency_key' => $record->idempotency_key,
                'operation' => 'bypass',
                'status' => 'pending',
                'attempts' => 0,
                'max_attempts' => 1,
                'available_at' => now(),
            ]);
            $this->fail('Duplicate idempotency key should be rejected.');
        } catch (QueryException) {
            $this->assertDatabaseCount('sync_outbox', 1);
        }
    }

    public function test_enqueue_rejects_sensitive_payload_recursively(): void
    {
        foreach (['password', 'apiToken', 'Authorization-Header', 'privateKey', 'Cookie', 'requestPayload', 'headers', 'credential'] as $key) {
            try {
                $this->service()->enqueue($this->intent([
                    'idempotency_key' => 'sensitive-'.$key,
                    'payload' => ['safe' => ['value' => true, $key => 'plain-secret']],
                ]));
                $this->fail("Sensitive payload key {$key} should fail.");
            } catch (SyncOutboxWriteException $exception) {
                $this->assertSame('Invalid sync outbox payload.', $exception->getMessage());
            }
        }

        $this->assertDatabaseCount('sync_outbox', 0);
        $this->assertDatabaseCount('audit_records', 0);
    }

    public function test_enqueue_rejects_non_frozen_payload_types_at_every_depth(): void
    {
        $resource = fopen('php://memory', 'r');
        $stringable = new class implements \Stringable
        {
            public function __toString(): string
            {
                return 'implicitly-serializable-secret';
            }
        };
        $generator = (function (): \Generator {
            yield 'safe';
        })();
        $invalidValues = [
            (object) ['token' => 'secret'],
            ['nested' => (object) ['safe' => true]],
            ['nested' => $stringable],
            ['nested' => static fn (): string => 'secret'],
            ['nested' => $resource],
            ['nested' => new \ArrayIterator(['safe'])],
            ['nested' => $generator],
            ['nested' => new \DateTimeImmutable('2026-08-01')],
            ['nested' => SyncOutboxStatus::Pending],
            ['nested' => NAN],
            ['nested' => INF],
            ['nested' => -INF],
        ];

        try {
            foreach ($invalidValues as $index => $value) {
                try {
                    $this->service()->enqueue($this->intent([
                        'idempotency_key' => 'invalid-payload-type-'.$index,
                        'payload' => ['metadata' => $value],
                    ]));
                    $this->fail('A non-frozen nested payload value should be rejected.');
                } catch (SyncOutboxWriteException $exception) {
                    $this->assertSame('Invalid sync outbox payload.', $exception->getMessage());
                    $this->assertDatabaseMissing('sync_outbox', [
                        'idempotency_key' => 'invalid-payload-type-'.$index,
                    ]);
                }
            }
        } finally {
            fclose($resource);
        }

        $this->assertDatabaseCount('sync_outbox', 0);
        $this->assertDatabaseCount('audit_records', 0);
    }

    public function test_enqueue_accepts_deep_payloads_containing_only_frozen_types(): void
    {
        $payload = [
            'level_one' => [
                'level_two' => [
                    'list' => ['value', 42, 1.5, true, false, null],
                    'map' => ['second' => 2, 'first' => 1],
                ],
            ],
        ];

        $record = $this->service()->enqueue($this->intent([
            'idempotency_key' => 'valid-deep-frozen-payload',
            'payload' => $payload,
        ]));

        $this->assertSame([
            'level_one' => [
                'level_two' => [
                    'list' => ['value', 42, 1.5, true, false, null],
                    'map' => ['first' => 1, 'second' => 2],
                ],
            ],
        ], $record->payload);
        $this->assertDatabaseCount('sync_outbox', 1);
        $this->assertDatabaseCount('audit_records', 1);
    }

    public function test_enqueue_validates_lengths_and_attempt_bounds(): void
    {
        $invalid = [
            ['idempotency_key' => ''],
            ['idempotency_key' => str_repeat('a', 192)],
            ['operation' => ''],
            ['operation' => str_repeat('a', 101)],
            ['aggregate_type' => str_repeat('a', 65)],
            ['aggregate_id' => str_repeat('a', 128)],
            ['provider_key' => str_repeat('a', 65)],
            ['connection_key' => str_repeat('a', 128)],
            ['max_attempts' => 0],
            ['max_attempts' => 101],
            ['payload' => 'not-an-array'],
            ['available_at' => 'tomorrow'],
        ];

        foreach ($invalid as $index => $override) {
            try {
                $this->service()->enqueue($this->intent([
                    'idempotency_key' => 'invalid-'.$index,
                    ...$override,
                ]));
                $this->fail('Invalid intent should fail.');
            } catch (SyncOutboxWriteException) {
                $this->assertDatabaseMissing('sync_outbox', ['idempotency_key' => 'invalid-'.$index]);
            }
        }
    }

    public function test_claim_only_claims_an_eligible_pending_record_once(): void
    {
        CarbonImmutable::setTestNow('2026-08-01 10:00:00');
        $record = $this->service()->enqueue($this->intent());
        $claimed = $this->service()->claim($record->id);

        $this->assertSame(SyncOutboxStatus::Processing, $claimed->status);
        $this->assertSame(1, $claimed->attempts);
        $this->assertTrue(Str::isUuid($claimed->lock_token));
        $this->assertTrue($claimed->locked_at->equalTo(CarbonImmutable::now()));
        $this->assertTrue($claimed->last_attempted_at->equalTo(CarbonImmutable::now()));

        $this->expectException(SyncOutboxWriteException::class);
        $this->service()->claim($record->id);
    }

    public function test_claim_rejects_future_exhausted_completed_and_failed_records(): void
    {
        CarbonImmutable::setTestNow('2026-08-01 10:00:00');
        $future = $this->service()->enqueue($this->intent([
            'idempotency_key' => 'future',
            'available_at' => CarbonImmutable::now()->addMinute(),
        ]));
        $exhausted = $this->service()->enqueue($this->intent([
            'idempotency_key' => 'exhausted',
            'max_attempts' => 1,
        ]));
        DB::table('sync_outbox')->where('id', $exhausted->id)->update(['attempts' => 1]);

        $completed = $this->service()->enqueue($this->intent(['idempotency_key' => 'completed']));
        $completed = $this->service()->claim($completed->id);
        $this->service()->complete($completed->id, $completed->lock_token);

        $failed = $this->service()->enqueue($this->intent(['idempotency_key' => 'failed']));
        $failed = $this->service()->claim($failed->id);
        $this->service()->fail($failed->id, $failed->lock_token, 'remote_failure', 'Remote failure.');

        foreach ([$future->id, $exhausted->id, $completed->id, $failed->id] as $id) {
            try {
                $this->service()->claim($id);
                $this->fail('Ineligible record should not be claimed.');
            } catch (SyncOutboxWriteException $exception) {
                $this->assertSame('The requested sync outbox transition is not allowed.', $exception->getMessage());
            }
        }
    }

    public function test_complete_requires_matching_lock_and_preserves_immutable_intent(): void
    {
        $record = $this->service()->enqueue($this->intent());
        $claimed = $this->service()->claim($record->id);

        try {
            $this->service()->complete($record->id, (string) Str::uuid());
            $this->fail('Wrong lock should fail.');
        } catch (SyncOutboxWriteException $exception) {
            $this->assertSame('The sync outbox lock does not match.', $exception->getMessage());
        }

        $completed = $this->service()->complete($record->id, $claimed->lock_token);

        $this->assertSame(SyncOutboxStatus::Completed, $completed->status);
        $this->assertNotNull($completed->completed_at);
        $this->assertNull($completed->locked_at);
        $this->assertNull($completed->lock_token);
        $this->assertNull($completed->last_error_code);
        $this->assertSame($record->payload, $completed->payload);
        $this->assertSame($record->operation, $completed->operation);
        $this->assertSame($record->idempotency_key, $completed->idempotency_key);
    }

    public function test_retry_uses_deterministic_bounded_backoff_and_sanitizes_errors(): void
    {
        CarbonImmutable::setTestNow('2026-08-01 10:00:00');
        $record = $this->service()->enqueue($this->intent(['max_attempts' => 5]));
        $expectedBackoffs = [5, 15, 30, 30];

        foreach ($expectedBackoffs as $index => $seconds) {
            $claimed = $this->service()->claim($record->id);
            $lockToken = $claimed->lock_token;
            $retried = $this->service()->retry(
                $record->id,
                $lockToken,
                str_repeat('REMOTE ERROR ', 20),
                "password=plain-secret\nBearer bearer-secret {$lockToken} Stack trace: #0 secret-frame ".str_repeat('x', 600),
            );

            $this->assertSame(SyncOutboxStatus::Pending, $retried->status);
            $this->assertSame($index + 1, $retried->attempts);
            $this->assertTrue($retried->available_at->equalTo(CarbonImmutable::now()->addSeconds($seconds)));
            $this->assertNull($retried->locked_at);
            $this->assertNull($retried->lock_token);
            $this->assertLessThanOrEqual(100, mb_strlen($retried->last_error_code));
            $this->assertLessThanOrEqual(500, mb_strlen($retried->last_error_message));
            foreach (['plain-secret', 'bearer-secret', $lockToken, 'secret-frame', 'Stack trace:', "\n"] as $secret) {
                $this->assertStringNotContainsString($secret, $retried->last_error_message);
            }

            CarbonImmutable::setTestNow($retried->available_at);
        }
    }

    public function test_failure_summaries_conservatively_redact_high_risk_credentials(): void
    {
        $cases = [
            ['Authorization: Basic abc123', ['abc123', 'Basic']],
            ['Authorization=Basic equal-secret', ['equal-secret']],
            ['Bearer bearer-secret', ['bearer-secret']],
            ['password="two word secret"', ['two word secret']],
            ["password='single quoted secret'", ['single quoted secret']],
            ['password: two word secret', ['two word secret']],
            ['cookie=session=abc; user=1', ['session=abc', 'user=1']],
            ['Set-Cookie: session=set-cookie-secret; Path=/; HttpOnly', ['set-cookie-secret']],
            ['api_key=api-secret', ['api-secret']],
            ['client_secret: very secret value', ['very secret value']],
            ['access_token=access-secret', ['access-secret']],
            ['refresh_token=refresh-secret', ['refresh-secret']],
            ['private_key=private-secret', ['private-secret']],
            ['https://user:url-password@example.com/path', ['user', 'url-password']],
            ['mysql:host=localhost;password=db-secret;database=erp', ['db-secret']],
        ];

        foreach ($cases as $index => [$message, $secrets]) {
            $record = $this->service()->enqueue($this->intent([
                'idempotency_key' => 'redaction-case-'.$index,
            ]));
            $claimed = $this->service()->claim($record->id);
            $retried = $this->service()->retry($record->id, $claimed->lock_token, 'remote_error', $message);

            $this->assertSame('Sensitive error details were redacted.', $retried->last_error_message);
            foreach ($secrets as $secret) {
                $this->assertStringNotContainsString($secret, $retried->last_error_message);
            }
        }
    }

    public function test_failure_summaries_remove_lock_tokens_stack_frames_and_controls(): void
    {
        $record = $this->service()->enqueue($this->intent(['idempotency_key' => 'redact-lock-token']));
        $claimed = $this->service()->claim($record->id);
        $retried = $this->service()->retry(
            $record->id,
            $claimed->lock_token,
            'remote_error',
            "Remote failure {$claimed->lock_token}",
        );

        $this->assertSame('Sensitive error details were redacted.', $retried->last_error_message);
        $this->assertStringNotContainsString($claimed->lock_token, $retried->last_error_message);

        $record = $this->service()->enqueue($this->intent(['idempotency_key' => 'redact-stack-trace']));
        $claimed = $this->service()->claim($record->id);
        $retried = $this->service()->retry(
            $record->id,
            $claimed->lock_token,
            'remote_error',
            "Remote failure\nStack trace:\n#0 /app/Secret.php(1): secret-frame\n#1 {main}\x00",
        );

        $this->assertSame('Remote failure', $retried->last_error_message);
        foreach (['Stack trace', 'Secret.php', 'secret-frame', '#0', "\n", "\x00"] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $retried->last_error_message);
        }
    }

    public function test_retry_is_rejected_after_attempts_are_exhausted(): void
    {
        $record = $this->service()->enqueue($this->intent(['max_attempts' => 1]));
        $claimed = $this->service()->claim($record->id);

        $this->expectException(SyncOutboxWriteException::class);
        $this->expectExceptionMessage('transition is not allowed');

        $this->service()->retry($record->id, $claimed->lock_token, 'failed', 'Failed.');
    }

    public function test_fail_requires_lock_and_records_a_safe_terminal_failure(): void
    {
        $record = $this->service()->enqueue($this->intent());
        $claimed = $this->service()->claim($record->id);

        try {
            $this->service()->fail($record->id, 'wrong-lock', 'failed', 'Failed.');
            $this->fail('Wrong lock should fail.');
        } catch (SyncOutboxWriteException $exception) {
            $this->assertSame('The sync outbox lock does not match.', $exception->getMessage());
        }

        $failed = $this->service()->fail(
            $record->id,
            $claimed->lock_token,
            'REMOTE FAILURE',
            'authorization=plain-secret'.str_repeat('x', 600),
        );

        $this->assertSame(SyncOutboxStatus::Failed, $failed->status);
        $this->assertNotNull($failed->failed_at);
        $this->assertNull($failed->locked_at);
        $this->assertNull($failed->lock_token);
        $this->assertSame('remote_failure', $failed->last_error_code);
        $this->assertStringNotContainsString('plain-secret', $failed->last_error_message);
        $this->assertLessThanOrEqual(500, mb_strlen($failed->last_error_message));
    }

    public function test_recover_stale_returns_pending_or_failed_never_completed(): void
    {
        CarbonImmutable::setTestNow('2026-08-01 09:00:00');
        $retryable = $this->service()->enqueue($this->intent([
            'idempotency_key' => 'stale-retryable',
            'max_attempts' => 2,
        ]));
        $retryable = $this->service()->claim($retryable->id);
        $exhausted = $this->service()->enqueue($this->intent([
            'idempotency_key' => 'stale-exhausted',
            'max_attempts' => 1,
        ]));
        $exhausted = $this->service()->claim($exhausted->id);

        CarbonImmutable::setTestNow('2026-08-01 10:00:00');
        $recovered = $this->service()->recoverStale(CarbonImmutable::now()->subMinutes(30), 10);

        $this->assertCount(2, $recovered);
        $this->assertSame(SyncOutboxStatus::Pending, $retryable->fresh()->status);
        $this->assertSame(SyncOutboxStatus::Failed, $exhausted->fresh()->status);
        $this->assertNotSame(SyncOutboxStatus::Completed, $retryable->fresh()->status);
        $this->assertNotSame(SyncOutboxStatus::Completed, $exhausted->fresh()->status);
        $this->assertNull($retryable->fresh()->lock_token);
        $this->assertNull($exhausted->fresh()->lock_token);
        $this->assertDatabaseCount('audit_records', 6);
        $this->assertSame(2, AuditRecord::where('action', 'sync.outbox.stale_recovered')->count());
    }

    public function test_recover_stale_respects_batch_limit_and_rejects_unbounded_limits(): void
    {
        CarbonImmutable::setTestNow('2026-08-01 09:00:00');
        foreach (range(1, 3) as $index) {
            $record = $this->service()->enqueue($this->intent(['idempotency_key' => 'stale-'.$index]));
            $this->service()->claim($record->id);
        }

        CarbonImmutable::setTestNow('2026-08-01 10:00:00');
        $this->assertCount(1, $this->service()->recoverStale(CarbonImmutable::now()->subMinutes(30), 1));
        $this->assertSame(2, SyncOutbox::where('status', SyncOutboxStatus::Processing->value)->count());

        foreach ([0, 101] as $limit) {
            try {
                $this->service()->recoverStale(CarbonImmutable::now(), $limit);
                $this->fail('Unbounded stale recovery limit should fail.');
            } catch (SyncOutboxWriteException $exception) {
                $this->assertSame('Invalid sync outbox recovery limit.', $exception->getMessage());
            }
        }
    }

    public function test_illegal_skip_and_reverse_transitions_are_rejected(): void
    {
        $pending = $this->service()->enqueue($this->intent());

        foreach (['complete', 'retry', 'fail'] as $transition) {
            try {
                $this->invokeTransition($transition, $pending->id, 'not-locked');
                $this->fail("Pending {$transition} should fail.");
            } catch (SyncOutboxWriteException $exception) {
                $this->assertSame('The requested sync outbox transition is not allowed.', $exception->getMessage());
            }
        }

        $claimed = $this->service()->claim($pending->id);
        $completed = $this->service()->complete($pending->id, $claimed->lock_token);

        foreach (['complete', 'retry', 'fail'] as $transition) {
            try {
                $this->invokeTransition($transition, $completed->id, 'not-locked');
                $this->fail("Completed {$transition} should fail.");
            } catch (SyncOutboxWriteException $exception) {
                $this->assertSame('The requested sync outbox transition is not allowed.', $exception->getMessage());
            }
        }
    }

    public function test_every_legal_transition_uses_system_actor_sync_source_and_safe_metadata(): void
    {
        $complete = $this->service()->enqueue($this->intent(['idempotency_key' => 'audit-complete']));
        $complete = $this->service()->claim($complete->id);
        $this->service()->complete($complete->id, $complete->lock_token);

        $retry = $this->service()->enqueue($this->intent(['idempotency_key' => 'audit-retry']));
        $retry = $this->service()->claim($retry->id);
        $this->service()->retry($retry->id, $retry->lock_token, 'remote_error', 'password=plain-secret');

        $failed = $this->service()->enqueue($this->intent(['idempotency_key' => 'audit-fail']));
        $failed = $this->service()->claim($failed->id);
        $this->service()->fail($failed->id, $failed->lock_token, 'remote_error', 'token=plain-secret');

        $actions = AuditRecord::query()->pluck('action')->all();
        foreach (['sync.outbox.enqueued', 'sync.outbox.claimed', 'sync.outbox.completed', 'sync.outbox.retry_scheduled', 'sync.outbox.failed'] as $action) {
            $this->assertContains($action, $actions);
        }

        foreach (AuditRecord::where('source', 'sync:outbox')->get() as $audit) {
            $this->assertNull($audit->actor_id);
            $this->assertSame(SyncOutbox::class, $audit->entity_type);
            $serialized = json_encode($audit->toArray(), JSON_THROW_ON_ERROR);
            foreach (['payload', 'lock_token', 'plain-secret', 'SKU-1'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $serialized);
            }
        }
    }

    public function test_audit_failure_rolls_back_enqueue_and_claim_state(): void
    {
        $this->failAuditWrites();

        try {
            $this->service()->enqueue($this->intent(['idempotency_key' => 'audit-failed-enqueue']));
            $this->fail('Enqueue should roll back when Audit fails.');
        } catch (AuditWriteException) {
            $this->assertDatabaseMissing('sync_outbox', ['idempotency_key' => 'audit-failed-enqueue']);
        }

        $this->restoreAuditWrites();
        $record = $this->service()->enqueue($this->intent(['idempotency_key' => 'audit-failed-claim']));
        $this->failAuditWrites();

        try {
            $this->service()->claim($record->id);
            $this->fail('Claim should roll back when Audit fails.');
        } catch (AuditWriteException) {
            $record = $record->fresh();
            $this->assertSame(SyncOutboxStatus::Pending, $record->status);
            $this->assertSame(0, $record->attempts);
            $this->assertNull($record->lock_token);
        }
    }

    public function test_audit_failure_rolls_back_completion_and_stale_recovery(): void
    {
        CarbonImmutable::setTestNow('2026-08-01 09:00:00');
        $complete = $this->service()->enqueue($this->intent(['idempotency_key' => 'rollback-complete']));
        $complete = $this->service()->claim($complete->id);
        $stale = $this->service()->enqueue($this->intent(['idempotency_key' => 'rollback-stale']));
        $stale = $this->service()->claim($stale->id);
        $this->failAuditWrites();

        try {
            $this->service()->complete($complete->id, $complete->lock_token);
            $this->fail('Completion should roll back when Audit fails.');
        } catch (AuditWriteException) {
            $this->assertSame(SyncOutboxStatus::Processing, $complete->fresh()->status);
            $this->assertSame($complete->lock_token, $complete->fresh()->lock_token);
        }

        CarbonImmutable::setTestNow('2026-08-01 10:00:00');
        try {
            $this->service()->recoverStale(CarbonImmutable::now()->subMinutes(30), 10);
            $this->fail('Stale recovery should roll back when Audit fails.');
        } catch (AuditWriteException) {
            $this->assertSame(SyncOutboxStatus::Processing, $stale->fresh()->status);
            $this->assertSame($stale->lock_token, $stale->fresh()->lock_token);
        }
    }

    public function test_audit_failure_rolls_back_every_retry_field(): void
    {
        $record = $this->service()->enqueue($this->intent(['idempotency_key' => 'rollback-retry']));
        $claimed = $this->service()->claim($record->id)->fresh();
        $auditCount = AuditRecord::count();
        $this->failAuditWrites();

        try {
            $this->service()->retry(
                $claimed->id,
                $claimed->lock_token,
                'remote_failure',
                'Remote failure.',
            );
            $this->fail('Retry should roll back when Audit fails.');
        } catch (AuditWriteException) {
            $after = $claimed->fresh();
            $this->assertSame(SyncOutboxStatus::Processing, $after->status);
            $this->assertSame($claimed->attempts, $after->attempts);
            $this->assertSame($claimed->lock_token, $after->lock_token);
            $this->assertTrue($claimed->locked_at->equalTo($after->locked_at));
            $this->assertTrue($claimed->available_at->equalTo($after->available_at));
            $this->assertSame($claimed->last_error_code, $after->last_error_code);
            $this->assertSame($claimed->last_error_message, $after->last_error_message);
            $this->assertNull($after->failed_at);
            $this->assertNull($after->completed_at);
            $this->assertSame($auditCount, AuditRecord::count());
        }
    }

    public function test_audit_failure_rolls_back_every_fail_field(): void
    {
        $record = $this->service()->enqueue($this->intent(['idempotency_key' => 'rollback-fail']));
        $claimed = $this->service()->claim($record->id)->fresh();
        $auditCount = AuditRecord::count();
        $this->failAuditWrites();

        try {
            $this->service()->fail(
                $claimed->id,
                $claimed->lock_token,
                'remote_failure',
                'Remote failure.',
            );
            $this->fail('Failure transition should roll back when Audit fails.');
        } catch (AuditWriteException) {
            $after = $claimed->fresh();
            $this->assertSame(SyncOutboxStatus::Processing, $after->status);
            $this->assertSame($claimed->lock_token, $after->lock_token);
            $this->assertTrue($claimed->locked_at->equalTo($after->locked_at));
            $this->assertNull($after->failed_at);
            $this->assertSame($claimed->last_error_code, $after->last_error_code);
            $this->assertSame($claimed->last_error_message, $after->last_error_message);
            $this->assertSame($auditCount, AuditRecord::count());
        }
    }

    private function service(): SyncOutboxService
    {
        return app(SyncOutboxService::class);
    }

    /** @return array<string, mixed> */
    private function intent(array $overrides = []): array
    {
        return [
            'idempotency_key' => 'catalog-product-1-upsert',
            'operation' => 'catalog.product.upsert',
            'aggregate_type' => 'product',
            'aggregate_id' => '1',
            'provider_key' => 'future-provider',
            'connection_key' => 'connection-1',
            'payload' => ['nested' => ['safe' => true], 'sku' => 'SKU-1'],
            'available_at' => CarbonImmutable::now()->startOfSecond(),
            'max_attempts' => 3,
            ...$overrides,
        ];
    }

    private function assertBlocked(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Direct SyncOutbox write should be blocked.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('SyncOutboxService', $exception->getMessage());
        }
    }

    private function invokeTransition(string $transition, int $id, string $lockToken): void
    {
        match ($transition) {
            'complete' => $this->service()->complete($id, $lockToken),
            'retry' => $this->service()->retry($id, $lockToken, 'failed', 'Failed.'),
            'fail' => $this->service()->fail($id, $lockToken, 'failed', 'Failed.'),
        };
    }

    private function failAuditWrites(): void
    {
        $this->app->bind(AuditWriter::class, fn () => new class implements AuditWriter
        {
            public function write(array $attributes): AuditRecord
            {
                throw new RuntimeException('audit unavailable');
            }
        });
        $this->app->forgetInstance(AuditService::class);
        $this->app->forgetInstance(SyncOutboxService::class);
    }

    private function restoreAuditWrites(): void
    {
        $this->app->bind(AuditWriter::class, EloquentAuditWriter::class);
        $this->app->forgetInstance(AuditService::class);
        $this->app->forgetInstance(SyncOutboxService::class);
    }
}
