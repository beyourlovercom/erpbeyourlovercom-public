<?php

namespace App\Modules\Customers\Application;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Read-only projection of canonical Marketing subscriber identity and facts. */
final class CustomerSubscriberProjection
{
    public function identityLinkCount(): int
    {
        return DB::table('customers')->whereNull('customers.archived_at')->whereNotNull('customers.email')
            ->whereExists(function ($query): void {
                $query->selectRaw('1')->from('marketing_subscribers')
                    ->whereRaw('marketing_subscribers.email = LOWER(TRIM(customers.email))');
            })->count();
    }

    /** @param iterable<int, object> $customers */
    public function forCustomers(iterable $customers): array
    {
        $customers = $customers instanceof Collection ? $customers : collect($customers);
        $emails = $customers->pluck('email')->filter(fn (mixed $email): bool => is_string($email) && trim($email) !== '')
            ->map(fn (string $email): string => $this->normalizeEmail($email))->unique()->values()->all();
        if ($emails === []) return [];

        $bindings = implode(', ', array_fill(0, count($emails), '?'));
        $rows = DB::table('marketing_subscribers as subscriber')
            ->leftJoin('marketing_subscriptions as subscription', 'subscription.subscriber_id', '=', 'subscriber.id')
            ->whereRaw("subscriber.email IN ({$bindings})", $emails)
            ->select([
                'subscriber.email as subscriber_email', 'subscription.id as subscription_id', 'subscription.channel',
                'subscription.provider', 'subscription.source_site', 'subscription.status', 'subscription.consent_source',
                'subscription.subscribed_at', 'subscription.unsubscribed_at', 'subscription.status_updated_at', 'subscription.last_synced_at',
            ])->orderBy('subscriber.email')->orderBy('subscription.provider')->orderBy('subscription.id')->get();

        $projection = [];
        foreach ($rows->groupBy(fn (object $row): string => $this->normalizeEmail((string) $row->subscriber_email)) as $email => $subscriberRows) {
            $facts = $subscriberRows->filter(fn (object $row): bool => $row->subscription_id !== null)->map(fn (object $row): array => [
                'id' => (int) $row->subscription_id, 'channel' => $row->channel, 'provider' => $row->provider,
                'source_site' => $row->source_site, 'status' => $row->status, 'consent_source' => $row->consent_source,
                'subscribed_at' => $row->subscribed_at, 'unsubscribed_at' => $row->unsubscribed_at,
                'status_updated_at' => $row->status_updated_at, 'last_synced_at' => $row->last_synced_at,
            ])->values()->all();
            $projection[$email] = [
                'matched' => true,
                'subscriber_email' => $subscriberRows->first()->subscriber_email,
                'subscription_facts' => $facts,
                'subscription_statuses' => collect($facts)->pluck('status')->filter()->unique()->sort()->values()->all(),
            ];
        }
        return $projection;
    }

    public function forEmail(?string $email): array
    {
        $email = $email === null ? '' : $this->normalizeEmail($email);
        if ($email === '') return $this->emptyProjection();
        return $this->forCustomers([['email' => $email]])[$email] ?? $this->emptyProjection();
    }

    private function emptyProjection(): array
    {
        return ['matched' => false, 'subscriber_email' => null, 'subscription_facts' => [], 'subscription_statuses' => []];
    }

    private function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }
}
