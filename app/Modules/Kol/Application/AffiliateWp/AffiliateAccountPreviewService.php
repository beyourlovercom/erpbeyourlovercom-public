<?php

namespace App\Modules\Kol\Application\AffiliateWp;

use App\Modules\Kol\Application\Contracts\AffiliateWpGateway;
use App\Modules\Kol\Infrastructure\Models\Kol;
use App\Modules\Kol\Infrastructure\Models\KolAffiliateAccount;
use Illuminate\Support\Collection;
use UnexpectedValueException;

final class AffiliateAccountPreviewService
{
    public function __construct(private readonly AffiliateWpGateway $gateway) {}

    /** @return array<string,mixed> */
    public function preview(): array
    {
        $connectionKey = trim((string) ($this->gateway->descriptor()['connection_key'] ?? ''));
        if ($connectionKey === '') {
            throw new UnexpectedValueException('Affiliate preview connection key is required.');
        }

        [$items, $pages, $itemKeys] = $this->fetchAll($connectionKey);
        $providerById = collect($items)->keyBy('affiliate_id');
        $accounts = KolAffiliateAccount::query()->where('connection_key', $connectionKey)->get();
        $bound = $accounts->whereNotNull('kol_id')->values();
        $boundAffiliateIds = $bound->pluck('affiliate_id')->filter()->map(fn ($id) => (int) $id)->values();

        $missingBound = $boundAffiliateIds->reject(fn (int $id) => $providerById->has($id))->values();
        if ($missingBound->isNotEmpty()) {
            throw new UnexpectedValueException('Affiliate preview failed: an established binding is missing from provider truth.');
        }

        $this->guardBoundIdentityCollisions($items, $boundAffiliateIds);

        $metadataDrift = 0;
        $statusDrift = 0;
        $wpUserIdDrift = 0;
        $wpEmailDrift = 0;
        foreach ($accounts as $account) {
            $provider = $providerById->get((int) $account->affiliate_id);
            if (! $provider) {
                continue;
            }

            $idDrift = $this->nullableInt($account->wordpress_user_id) !== $provider['wordpress_user_id'];
            $emailDrift = $this->nullableString($account->wordpress_user_email) !== $provider['wordpress_user_email'];
            $displayDrift = $this->nullableString($account->display_name) !== $provider['display_name'];
            $affiliateEmailDrift = $this->nullableString($account->email) !== $provider['email'];
            $providerStatusDrift = $this->nullableString($account->provider_status) !== $provider['status'];

            if ($account->kol_id !== null) {
                $wpUserIdDrift += $idDrift ? 1 : 0;
                $wpEmailDrift += $emailDrift ? 1 : 0;
            }
            $statusDrift += $providerStatusDrift ? 1 : 0;
            $metadataDrift += ($idDrift || $emailDrift || $displayDrift || $affiliateEmailDrift || $providerStatusDrift) ? 1 : 0;
        }

        $boundItems = $boundAffiliateIds->map(fn (int $id) => $providerById->get($id))->filter()->values()->all();
        $providerIds = $providerById->keys()->map(fn ($id) => (int) $id);

        return [
            'ok' => true,
            'connection_key' => $connectionKey,
            'snapshot_pages' => $pages,
            'provider_affiliate_count' => count($items),
            'provider_unique_affiliate_ids' => $providerIds->count(),
            'erp_affiliate_mirror_count' => $accounts->count(),
            'existing_bound_count' => $bound->count(),
            'canonical_kol_count' => ($kolCount = Kol::query()->count()),
            'kols_without_bound_affiliate' => max(0, $kolCount - $bound->pluck('kol_id')->filter()->unique()->count()),
            'metadata_drift_rows' => $metadataDrift,
            'unbound_provider_affiliates' => $providerIds->diff($boundAffiliateIds)->count(),
            'missing_provider_ids_for_existing_bindings' => 0,
            'provider_status_drift_rows' => $statusDrift,
            'bound_wp_user_id_drift_rows' => $wpUserIdDrift,
            'bound_wp_email_drift_rows' => $wpEmailDrift,
            'identity_coverage_all' => $this->coverage($items),
            'identity_coverage_bound' => array_merge(['count' => count($boundItems)], $this->coverage($boundItems)),
            'collision_counts' => $this->collisionCounts($items),
            'provider_status_counts' => $this->statusCounts($items),
            'provider_item_keys' => $itemKeys,
        ];
    }

    /** @return array{0:list<array<string,mixed>>,1:int,2:list<string>} */
    private function fetchAll(string $connectionKey): array
    {
        $items = [];
        $seenIds = [];
        $keys = [];
        $cursor = null;
        $visited = [];
        $pages = 0;

        do {
            if ($cursor !== null) {
                if (isset($visited[$cursor])) {
                    throw new UnexpectedValueException('Affiliate preview cursor loop detected.');
                }
                $visited[$cursor] = true;
            }

            $response = $this->gateway->snapshot('affiliates', $cursor, null);
            $pages++;
            if (! $response->ok || $response->connectionKey !== $connectionKey || ! is_array($response->data)) {
                throw new UnexpectedValueException('Affiliate preview response failed validation.');
            }
            if (! array_key_exists('items', $response->data) || ! is_array($response->data['items'])) {
                throw new UnexpectedValueException('Affiliate preview data.items is required.');
            }

            foreach ($response->data['items'] as $raw) {
                if (! is_array($raw)) {
                    throw new UnexpectedValueException('Affiliate preview item must be an object.');
                }
                foreach (array_keys($raw) as $key) {
                    $keys[$key] = true;
                }

                $affiliateId = $this->positiveInt($raw['affiliate_id'] ?? null, 'affiliate_id');
                if (isset($seenIds[$affiliateId])) {
                    throw new UnexpectedValueException('Affiliate preview contains duplicate affiliate_id.');
                }
                $seenIds[$affiliateId] = true;
                $items[] = $this->normalize($raw, $affiliateId);
            }

            $next = $response->data['next_cursor'] ?? null;
            if ($next === null || $next === '') {
                $cursor = null;
            } elseif (is_string($next)) {
                $cursor = $next;
            } else {
                throw new UnexpectedValueException('Affiliate preview next_cursor must be a string or null.');
            }
        } while ($cursor !== null);

        $itemKeys = array_keys($keys);
        sort($itemKeys);

        return [$items, $pages, $itemKeys];
    }

    /** @param array<string,mixed> $raw @return array<string,mixed> */
    private function normalize(array $raw, int $affiliateId): array
    {
        $status = $this->requiredString($raw['status'] ?? null, 32, 'status');
        $userIdRaw = $raw['user_id'] ?? $raw['wordpress_user_id'] ?? null;
        $userId = $userIdRaw === null ? null : $this->positiveInt($userIdRaw, 'user_id');
        $explicitUserEmail = $this->nullableString($raw['user_email'] ?? $raw['wordpress_user_email'] ?? null);
        $email = $this->nullableString($raw['email'] ?? null);
        $displayName = $this->nullableString($raw['display_name'] ?? $raw['username'] ?? null);

        return [
            'affiliate_id' => $affiliateId,
            'wordpress_user_id' => $userId,
            'explicit_user_email' => $explicitUserEmail,
            'wordpress_user_email' => $explicitUserEmail ?? $email,
            'email' => $email,
            'display_name' => $displayName,
            'status' => $status,
            'user_login' => $this->nullableString($raw['user_login'] ?? null),
            'username' => $this->nullableString($raw['username'] ?? null),
        ];
    }

    /** @param list<array<string,mixed>> $items */
    private function guardBoundIdentityCollisions(array $items, Collection $boundAffiliateIds): void
    {
        $boundLookup = array_fill_keys($boundAffiliateIds->all(), true);
        foreach (['wordpress_user_id', 'explicit_user_email'] as $field) {
            $groups = [];
            foreach ($items as $item) {
                $value = $item[$field] ?? null;
                if ($value === null || $value === '') {
                    continue;
                }
                $key = is_string($value) ? mb_strtolower($value) : (string) $value;
                $groups[$key][] = (int) $item['affiliate_id'];
            }
            foreach ($groups as $affiliateIds) {
                if (count($affiliateIds) < 2) {
                    continue;
                }
                foreach ($affiliateIds as $affiliateId) {
                    if (isset($boundLookup[$affiliateId])) {
                        throw new UnexpectedValueException('Affiliate preview failed: provider WordPress identity collision affects an established binding.');
                    }
                }
            }
        }
    }

    /** @param list<array<string,mixed>> $items @return array<string,int> */
    private function coverage(array $items): array
    {
        return [
            'user_id' => $this->countPresent($items, 'wordpress_user_id'),
            'explicit_user_email' => $this->countPresent($items, 'explicit_user_email'),
            'email' => $this->countPresent($items, 'email'),
            'user_login' => $this->countPresent($items, 'user_login'),
            'username' => $this->countPresent($items, 'username'),
        ];
    }

    /** @param list<array<string,mixed>> $items @return array<string,int> */
    private function collisionCounts(array $items): array
    {
        return [
            'affiliate_id' => $this->countCollisionGroups($items, 'affiliate_id'),
            'wordpress_user_id' => $this->countCollisionGroups($items, 'wordpress_user_id'),
            'explicit_user_email' => $this->countCollisionGroups($items, 'explicit_user_email'),
            'user_login' => $this->countCollisionGroups($items, 'user_login'),
            'username' => $this->countCollisionGroups($items, 'username'),
        ];
    }

    /** @param list<array<string,mixed>> $items @return array<string,int> */
    private function statusCounts(array $items): array
    {
        $counts = [];
        foreach ($items as $item) {
            $status = (string) $item['status'];
            $counts[$status] = ($counts[$status] ?? 0) + 1;
        }
        ksort($counts);
        return $counts;
    }

    /** @param list<array<string,mixed>> $items */
    private function countPresent(array $items, string $field): int
    {
        return count(array_filter($items, fn (array $item) => ($item[$field] ?? null) !== null && ($item[$field] ?? '') !== ''));
    }

    /** @param list<array<string,mixed>> $items */
    private function countCollisionGroups(array $items, string $field): int
    {
        $counts = [];
        foreach ($items as $item) {
            $value = $item[$field] ?? null;
            if ($value === null || $value === '') {
                continue;
            }
            $key = is_string($value) ? mb_strtolower($value) : (string) $value;
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }
        return count(array_filter($counts, fn (int $count) => $count > 1));
    }

    private function positiveInt(mixed $value, string $field): int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (is_string($value) && ctype_digit($value) && (int) $value > 0) {
            return (int) $value;
        }
        throw new UnexpectedValueException("Affiliate preview {$field} must be a positive integer.");
    }

    private function requiredString(mixed $value, int $max, string $field): string
    {
        $value = $this->nullableString($value);
        if ($value === null || mb_strlen($value) > $max) {
            throw new UnexpectedValueException("Affiliate preview {$field} is invalid.");
        }
        return $value;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_string($value)) {
            throw new UnexpectedValueException('Affiliate preview string field is invalid.');
        }
        $value = trim($value);
        return $value === '' ? null : $value;
    }

    private function nullableInt(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }
}
