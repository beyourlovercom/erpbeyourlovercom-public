<?php

namespace App\Modules\Kol\Application\AffiliateWp;

use App\Modules\Kol\Application\Contracts\AffiliateWpGateway;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use UnexpectedValueException;

final class AffiliateSnapshotSyncService
{
    private const ACCOUNT = 'App\\Modules\\Kol\\Infrastructure\\Models\\KolAffiliateAccount';

    public function __construct(private readonly AffiliateWpGateway $gateway) {}

    /** @return array{synced:int, marked_missing:int, errors:list<string>, provider_references:list<string>} */
    public function sync(string $connectionKey, Authenticatable $actor): array
    {
        if (! $actor->can('kol.affiliates.manage')) {
            throw new AuthorizationException;
        }

        $itemsById = [];
        $cursor = null;
        $visitedCursors = [];

        do {
            if ($cursor !== null) {
                if (isset($visitedCursors[$cursor])) {
                    throw new UnexpectedValueException('Affiliate snapshot cursor loop detected.');
                }
                $visitedCursors[$cursor] = true;
            }

            $response = $this->gateway->snapshot('affiliates', $cursor, null);
            if (! $response->ok || $response->connectionKey !== $connectionKey) {
                throw new UnexpectedValueException('Affiliate snapshot response failed validation.');
            }

            $data = $response->data;
            if (! is_array($data) || ! array_key_exists('items', $data) || ! is_array($data['items'])) {
                throw new UnexpectedValueException('Affiliate snapshot data.items is required.');
            }

            foreach ($data['items'] as $item) {
                if (! is_array($item)) {
                    throw new UnexpectedValueException('Affiliate snapshot item must be an object.');
                }

                $affiliateId = $this->positiveInteger($item['affiliate_id'] ?? null, 'affiliate_id');
                $itemsById[$affiliateId] = $this->normalizeItem($item, $affiliateId);
            }

            $nextCursor = $data['next_cursor'] ?? null;
            if ($nextCursor === null || $nextCursor === '') {
                $cursor = null;
            } elseif (is_string($nextCursor)) {
                $cursor = $nextCursor;
            } else {
                throw new UnexpectedValueException('Affiliate snapshot next_cursor must be a string or null.');
            }
        } while ($cursor !== null);

        $accountClass = self::ACCOUNT;
        $seenAffiliateIds = array_keys($itemsById);

        $markedMissing = DB::transaction(function () use ($connectionKey, $itemsById, $seenAffiliateIds, $accountClass): int {
            $now = now();

            foreach ($itemsById as $affiliateId => $fields) {
                $existing = $accountClass::query()
                    ->where('connection_key', $connectionKey)
                    ->where('affiliate_id', $affiliateId)
                    ->first();

                $providerFields = array_merge($fields, [
                    'sync_status' => 'synced',
                    'reconciliation_status' => 'present',
                    'last_synced_at' => $now,
                    'last_successful_sync_at' => $now,
                    'last_error_code' => null,
                    'last_error_message' => null,
                ]);

                if ($existing) {
                    $existing->update($providerFields);

                    continue;
                }

                $accountClass::create(array_merge($providerFields, [
                    'connection_key' => $connectionKey,
                    'affiliate_id' => $affiliateId,
                    'origin' => 'external_sync',
                    'provisioning_status' => 'not_requested',
                ]));
            }

            $missingQuery = $accountClass::query()
                ->where('connection_key', $connectionKey)
                ->whereNotNull('affiliate_id');

            if ($seenAffiliateIds !== []) {
                $missingQuery->whereNotIn('affiliate_id', $seenAffiliateIds);
            }

            return $missingQuery->update([
                'reconciliation_status' => 'missing_from_latest_snapshot',
                'last_synced_at' => $now,
            ]);
        });

        return ['synced' => count($seenAffiliateIds), 'marked_missing' => $markedMissing, 'errors' => [], 'provider_references' => array_map(static fn (int $id): string => (string) $id, $seenAffiliateIds)];
    }

    /** @return list<array<string,mixed>> */
    public function workspaceRows(string $connectionKey): array
    {
        $accountClass = self::ACCOUNT;

        return $accountClass::query()
            ->where('connection_key', $connectionKey)
            ->with('kol:id,display_name')
            ->orderByRaw('affiliate_id IS NULL')
            ->orderBy('affiliate_id')
            ->get()
            ->map(fn ($row): array => [
                'id' => $row->id,
                'affiliate_id' => $row->affiliate_id,
                'wordpress_user_id' => $row->wordpress_user_id,
                'wordpress_user_login' => $row->wordpress_user_login,
                'wordpress_user_email' => $row->wordpress_user_email,
                'display_name' => $row->display_name,
                'email' => $row->email,
                'provider_status' => $row->provider_status,
                'kol' => $row->kol ? ['id' => $row->kol->id, 'display_name' => $row->kol->display_name] : null,
                'origin' => $row->origin,
                'sync_status' => $row->sync_status,
                'reconciliation_status' => $row->reconciliation_status,
                'provisioning_status' => $row->provisioning_status,
                'last_error_code' => $row->last_error_code,
                'last_error_message' => $row->last_error_message,
                'last_successful_sync_at' => $row->last_successful_sync_at?->toIso8601String(),
            ])
            ->all();
    }

    /** @param array<string,mixed> $item @return array<string,mixed> */
    private function normalizeItem(array $item, int $affiliateId): array
    {
        $email = $this->nullableString($item['email'] ?? null, 255, 'email');
        $displayName = $this->nullableString($item['display_name'] ?? $item['username'] ?? null, 255, 'display_name');
        $providerStatus = $this->requiredString($item['status'] ?? null, 32, 'status');
        $wordpressUserIdRaw = $item['user_id'] ?? $item['wordpress_user_id'] ?? null;
        $wordpressUserId = $wordpressUserIdRaw === null ? null : $this->positiveInteger($wordpressUserIdRaw, 'user_id');
        $wordpressUserLogin = $this->nullableString(
            $item['user_login'] ?? $item['username'] ?? null,
            255,
            'username',
        );
        $wordpressUserEmail = $this->nullableString(
            $item['user_email'] ?? $item['wordpress_user_email'] ?? $email,
            255,
            'user_email',
        );

        return [
            'affiliate_id' => $affiliateId,
            'wordpress_user_id' => $wordpressUserId,
            'wordpress_user_login' => $wordpressUserLogin,
            'wordpress_user_email' => $wordpressUserEmail,
            'display_name' => $displayName,
            'email' => $email,
            'provider_status' => $providerStatus,
        ];
    }

    private function positiveInteger(mixed $value, string $field): int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (is_string($value) && ctype_digit($value) && (int) $value > 0) {
            return (int) $value;
        }

        throw new UnexpectedValueException("Affiliate snapshot {$field} must be a positive integer.");
    }

    private function requiredString(mixed $value, int $maxLength, string $field): string
    {
        $normalized = $this->nullableString($value, $maxLength, $field);
        if ($normalized === null) {
            throw new UnexpectedValueException("Affiliate snapshot {$field} is required.");
        }

        return $normalized;
    }

    private function nullableString(mixed $value, int $maxLength, string $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_string($value)) {
            throw new UnexpectedValueException("Affiliate snapshot {$field} must be a string or null.");
        }

        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (mb_strlen($value) > $maxLength) {
            throw new UnexpectedValueException("Affiliate snapshot {$field} exceeds its maximum length.");
        }

        return $value;
    }
}
