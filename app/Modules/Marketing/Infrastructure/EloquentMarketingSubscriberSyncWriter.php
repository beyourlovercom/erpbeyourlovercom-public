<?php

namespace App\Modules\Marketing\Infrastructure;

use App\Modules\Marketing\Application\Contracts\MarketingSubscriberSyncWriter;
use App\Modules\Marketing\Infrastructure\Models\MarketingSubscriber;
use App\Modules\Marketing\Infrastructure\Models\MarketingSubscription;
use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use RuntimeException;

final class EloquentMarketingSubscriberSyncWriter implements MarketingSubscriberSyncWriter
{
    public function __construct(private readonly DatabaseManager $database) {}

    public function import(array $item, ?string $sourceSite): void
    {
        $this->database->transaction(function () use ($item, $sourceSite): void {
            $email = strtolower(trim((string) $item['email']));
            $externalId = trim((string) $item['external_subscription_id']);
            $subscription = MarketingSubscription::query()
                ->where('provider', 'wordpress')->where('external_subscription_id', $externalId)
                ->lockForUpdate()->first();

            if ($subscription !== null) {
                if ($subscription->subscriber?->email !== $email) {
                    throw new RuntimeException('Source identity conflicts with an existing subscription.');
                }
                if ($this->rejectUnsafeUpdate($subscription, $item)) {
                    return; // Safe no-op: a stale record never reactivates an opted-out address.
                }
            }

            $subscriber = MarketingSubscriber::query()->firstOrNew(['email' => $email]);
            $firstName = $this->nullableString($item['first_name'] ?? null);
            $lastName = $this->nullableString($item['last_name'] ?? null);
            if ($firstName !== null) $subscriber->first_name = $firstName;
            if ($lastName !== null) $subscriber->last_name = $lastName;
            $subscriber->save();

            $subscription ??= new MarketingSubscription(['provider' => 'wordpress', 'external_subscription_id' => $externalId]);
            $incomingTime = $this->nullableString($item['status_updated_at'] ?? null);
            // A timestamp-less legacy opt-out should never be undone by an older subscription.
            if ($subscription->exists && $incomingTime === null && in_array($item['status'], ['unsubscribed', 'suppressed'], true)) {
                $incomingTime = CarbonImmutable::now('UTC')->toDateTimeString();
            }
            $subscription->fill([
                'subscriber_id' => $subscriber->id, 'channel' => 'email',
                'source_site' => $sourceSite,
                'external_list_id' => $this->nullableString($item['external_list_id'] ?? null),
                'external_list_name' => $this->nullableString($item['external_list_name'] ?? null),
                'status' => $item['status'],
                'consent_source' => $this->nullableString($item['consent_source'] ?? null),
                'subscribed_at' => $this->nullableString($item['subscribed_at'] ?? null),
                'unsubscribed_at' => $this->nullableString($item['unsubscribed_at'] ?? null),
                'status_updated_at' => $incomingTime,
                'last_synced_at' => CarbonImmutable::now(),
            ]);
            $subscription->save();
        });
    }

    private function rejectUnsafeUpdate(MarketingSubscription $previous, array $item): bool
    {
        $current = (string) $previous->status;
        $incoming = (string) $item['status'];
        if ($current === 'suppressed' && $incoming !== 'suppressed') return true;
        // Following unsubscribe, the next observed opt-in must be pending.
        if ($current === 'unsubscribed' && $incoming === 'subscribed') return true;

        $stored = $previous->getRawOriginal('status_updated_at');
        $latest = $stored ? CarbonImmutable::parse((string) $stored, 'UTC') : null;
        $updated = $this->nullableString($item['status_updated_at'] ?? null);
        $next = $updated ? CarbonImmutable::parse($updated, 'UTC') : null;

        if ($latest && ! $next) {
            return ! in_array($incoming, ['unsubscribed', 'suppressed'], true)
                || $this->priority($incoming) <= $this->priority($current);
        }
        if ($latest && $next) {
            if ($next->lessThan($latest)) return true;
            if ($next->equalTo($latest) && $this->priority($incoming) < $this->priority($current)) return true;
        }
        if (! $latest && ! $next && $this->priority($incoming) < $this->priority($current)) return true;
        return false;
    }

    private function priority(string $status): int
    {
        return match ($status) {
            'suppressed' => 5, 'unsubscribed' => 4, 'pending' => 3,
            'unknown' => 2, 'subscribed' => 1, default => 0,
        };
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null || ! is_scalar($value)) return null;
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }
}
