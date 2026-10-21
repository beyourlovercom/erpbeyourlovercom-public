<?php

namespace App\Modules\Kol\Application\ImportPreview;

use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Kol\Infrastructure\Models\Kol;
use App\Modules\Kol\Infrastructure\Models\KolImportPreviewBatch;
use App\Modules\Kol\Infrastructure\Models\KolImportPreviewCandidate;
use App\Modules\Kol\Infrastructure\Models\KolReconciliationBatch;
use App\Modules\Kol\Infrastructure\Models\KolReconciliationRow;
use DateTimeImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Auth\Access\AuthorizationException;

final class KolImportPreviewService
{
    private const EMAIL_HEADERS = ['Contact Email', 'contact email', 'email', 'E-mail'];
    private const PROFILE_HEADERS = ['Influencer`s Link/URL', "Influencer's Link/URL", 'profile_url', 'profile url'];

    public function generate(int $reconciliationBatchId, Authenticatable $actor): KolImportPreviewBatch
    {
        $this->authorize($actor);
        $source = KolReconciliationBatch::query()->with(['rows'])->findOrFail($reconciliationBatchId);
        $rows = $source->rows;
        [$components, $unresolved] = $this->cluster($rows->all());
        $kolIndex = $this->kolIndex();
        $ownerIndex = $this->ownerIndex();

        return DB::transaction(function () use ($source, $rows, $components, $unresolved, $kolIndex, $ownerIndex, $actor): KolImportPreviewBatch {
            $batch = KolImportPreviewBatch::query()->firstOrNew(['reconciliation_batch_id' => $source->id]);
            if ($batch->exists) $batch->candidates()->delete();
            $batch->fill([
                'creator_user_id' => $actor->getAuthIdentifier(),
                'status' => 'preview',
                'total_source_rows' => $rows->count(),
                'candidate_count' => 0,
                'unresolved_source_rows' => $unresolved,
                'preview_summary' => [],
            ])->save();

            $summary = array_fill_keys(['ready_new_kol', 'needs_review', 'existing_kol', 'conflict'], 0);
            foreach ($components as $component) {
                $candidate = $this->candidate($component, $kolIndex, $ownerIndex);
                $summary[$candidate['preview_status']]++;
                KolImportPreviewCandidate::create(array_merge($candidate, ['import_preview_batch_id' => $batch->id]));
            }
            $batch->update(['candidate_count' => count($components), 'preview_summary' => $summary]);
            return $batch->fresh(['reconciliationBatch', 'candidates.existingKol']);
        });
    }

    public function workspace(): array
    {
        return KolReconciliationBatch::query()->latest('id')->limit(20)->get()->map(function (KolReconciliationBatch $batch): array {
            $preview = KolImportPreviewBatch::query()->where('reconciliation_batch_id', $batch->id)->first();
            return [
                'id' => (int) $batch->id,
                'original_filename' => $batch->original_filename,
                'checksum' => $batch->checksum,
                'total_rows' => (int) $batch->total_rows,
                'distinct_source_identities' => (int) $batch->distinct_source_identities,
                'created_at' => $batch->created_at?->toIso8601String(),
                'import_preview' => $preview ? $this->batchView($preview->load('candidates.existingKol'), true) : null,
            ];
        })->values()->all();
    }

    public function batchView(KolImportPreviewBatch $batch, bool $includeCandidates = true): array
    {
        $batch->loadMissing(['reconciliationBatch', 'candidates.existingKol']);
        return [
            'id' => (int) $batch->id,
            'reconciliation_batch_id' => (int) $batch->reconciliation_batch_id,
            'source_filename' => $batch->reconciliationBatch?->original_filename,
            'total_source_rows' => (int) $batch->total_source_rows,
            'candidate_count' => (int) $batch->candidate_count,
            'unresolved_source_rows' => (int) $batch->unresolved_source_rows,
            'summary' => $batch->preview_summary ?? [],
            'created_at' => $batch->created_at?->toIso8601String(),
            'candidates' => $includeCandidates ? $batch->candidates->map(fn (KolImportPreviewCandidate $c): array => [
                'id' => (int) $c->id,
                'source_row_count' => (int) $c->source_row_count,
                'identity' => $c->exact_identity ?? [],
                'proposed_kol' => $c->proposed_kol ?? [],
                'social_accounts' => $c->proposed_social_accounts ?? [],
                'owner_evidence' => $c->owner_evidence ?? [],
                'owner_resolution_status' => $c->owner_resolution_status,
                'matched_owner_id' => $c->matched_owner_id,
                'history_summary' => $c->history_summary ?? [],
                'affiliate_account_ids' => $c->affiliate_account_ids ?? [],
                'existing_kol' => $c->existingKol ? ['id' => (int) $c->existingKol->id, 'display_name' => $c->existingKol->display_name] : null,
                'status' => $c->preview_status,
                'message' => $c->message,
            ])->values()->all() : [],
        ];
    }

    /** @param array<int, KolReconciliationRow> $rows */
    private function cluster(array $rows): array
    {
        $parent = []; $tokenOwner = []; $eligible = []; $unresolved = 0;
        foreach ($rows as $row) {
            $id = (int) $row->id;
            $tokens = $this->tokens($row);
            if ($tokens === []) { $unresolved++; continue; }
            $parent[$id] = $id;
            $eligible[$id] = ['row' => $row, 'tokens' => $tokens];
            foreach ($tokens as $token) {
                if (isset($tokenOwner[$token])) $this->union($parent, $id, $tokenOwner[$token]);
                else $tokenOwner[$token] = $id;
            }
        }
        $groups = [];
        foreach ($eligible as $id => $item) $groups[$this->find($parent, $id)][] = $item;
        return [array_values($groups), $unresolved];
    }

    private function find(array &$parent, int $id): int
    {
        if ($parent[$id] !== $id) $parent[$id] = $this->find($parent, $parent[$id]);
        return $parent[$id];
    }

    private function union(array &$parent, int $a, int $b): void
    {
        $ra = $this->find($parent, $a); $rb = $this->find($parent, $b);
        if ($ra === $rb) return;
        $keep = min($ra, $rb); $move = max($ra, $rb); $parent[$move] = $keep;
    }

    private function tokens(KolReconciliationRow $row): array
    {
        $identity = $row->normalized_identity ?? [];
        $email = $this->normalizeEmail($identity['email'] ?? $this->value($row->raw_source ?? [], self::EMAIL_HEADERS));
        $tokens = $email ? ['email:'.$email] : [];
        foreach ($this->profileUrls($row->raw_source ?? []) as $url) $tokens[] = 'url:'.$url;
        return array_values(array_unique($tokens));
    }

    /** @param array<int, array{row:KolReconciliationRow,tokens:array<int,string>}> $component */
    private function candidate(array $component, array $kolIndex, array $ownerIndex): array
    {
        $rows = array_map(fn (array $item) => $item['row'], $component);
        $tokens = [];
        foreach ($component as $item) $tokens = array_merge($tokens, $item['tokens']);
        $tokens = array_values(array_unique($tokens)); sort($tokens);
        $emails = array_values(array_map(fn ($t) => substr($t, 6), array_filter($tokens, fn ($t) => str_starts_with($t, 'email:'))));
        $urls = array_values(array_map(fn ($t) => substr($t, 4), array_filter($tokens, fn ($t) => str_starts_with($t, 'url:'))));
        sort($emails); sort($urls);
        [$existingStatus, $existingId] = $this->existingKol($tokens, $kolIndex);
        [$ownerStatus, $ownerId, $ownerEvidence] = $this->owner($rows, $ownerIndex);
        $socials = $this->socials($rows, $urls);
        $latestEmail = $this->latestEmail($rows) ?? ($emails[0] ?? null);
        $display = $this->displayName($socials, $latestEmail, $urls);
        $variations = $this->variations($rows);
        $proposed = array_filter([
            'display_name' => $display,
            'contact_email' => $latestEmail,
            'contact_phone' => $this->latestValue($rows, ['Phone']),
            'tax_id' => $this->latestValue($rows, ['税Tax Number']),
            'shipping_recipient_name' => $this->latestValue($rows, ['Full name']),
            'shipping_country' => $this->countryCode($this->latestValue($rows, ['国 Country'])),
            'country_code' => $this->countryCode($this->latestValue($rows, ['国 Country'])),
            'shipping_province_state' => $this->latestValue($rows, ['State']),
            'shipping_city' => $this->latestValue($rows, ['City']),
            'shipping_postal_code' => $this->latestValue($rows, ['Zipcode']),
            'shipping_address_line_1' => $this->latestValue($rows, ['Address Street']),
            'owner_id' => $ownerId,
        ], fn ($v) => $v !== null && $v !== '');
        $affiliateIds = array_values(array_unique(array_filter(array_map(fn (KolReconciliationRow $r) => $r->matched_affiliate_account_id ? (int) $r->matched_affiliate_account_id : null, $rows))));
        sort($affiliateIds);

        $issues = [];
        if (count($emails) > 1) $issues[] = 'multiple_emails';
        if (in_array($ownerStatus, ['unresolved', 'multiple', 'conflict'], true)) $issues[] = 'owner_'.$ownerStatus;
        if ($variations !== []) $issues[] = 'field_variation';
        if (count($socials) > 10) $issues[] = 'too_many_social_accounts';
        if (count($affiliateIds) > 1) $issues[] = 'multiple_affiliate_accounts';
        if ($display === '') $issues[] = 'missing_display_name';

        if ($existingStatus === 'conflict') $status = 'conflict';
        elseif ($existingStatus === 'matched') $status = 'existing_kol';
        elseif ($issues !== []) $status = 'needs_review';
        else $status = 'ready_new_kol';

        return [
            'cluster_key' => hash('sha256', implode('|', $tokens)),
            'source_row_ids' => array_values(array_map(fn (KolReconciliationRow $r) => (int) $r->id, $rows)),
            'source_row_count' => count($rows),
            'exact_identity' => ['emails' => $emails, 'profile_urls' => $urls, 'tokens' => $tokens, 'issues' => $issues, 'variations' => $variations],
            'proposed_kol' => $proposed,
            'proposed_social_accounts' => $socials,
            'owner_evidence' => $ownerEvidence,
            'owner_resolution_status' => $ownerStatus,
            'matched_owner_id' => $ownerId,
            'history_summary' => $this->history($rows),
            'matched_existing_kol_id' => $existingId,
            'existing_kol_status' => $existingStatus,
            'affiliate_account_ids' => $affiliateIds,
            'preview_status' => $status,
            'message' => $this->message($status, $issues),
        ];
    }

    private function kolIndex(): array
    {
        $index = ['email' => [], 'url' => []];
        foreach (Kol::query()->active()->with(['socialAccounts' => fn ($q) => $q->active()])->get(['id', 'contact_email']) as $kol) {
            $id = (int) $kol->id;
            $email = $this->normalizeEmail($kol->contact_email);
            if ($email) $index['email'][$email][] = $id;
            foreach ($kol->socialAccounts as $social) {
                $url = $this->normalizeUrl($social->profile_url);
                if ($url) $index['url'][$url][] = $id;
            }
        }
        foreach ($index as $type => $values) foreach ($values as $key => $ids) $index[$type][$key] = array_values(array_unique($ids));
        return $index;
    }

    private function ownerIndex(): array
    {
        $users = User::query()->where('is_active', true)->where(function ($query): void {
            $query->whereHas('roles.permissions', fn ($permission): mixed => $permission->where('key', 'kol.view'))
                ->orWhereHas('customPermissions', fn ($permission): mixed => $permission->where('key', 'kol.view'));
        })->get(['id', 'name', 'email']);
        $index = [];
        foreach ($users as $user) {
            foreach ([$user->name, $user->email] as $value) {
                $key = mb_strtolower(trim((string) $value));
                if ($key !== '') $index[$key][] = (int) $user->id;
            }
        }
        foreach ($index as $key => $ids) $index[$key] = array_values(array_unique($ids));
        return $index;
    }

    private function existingKol(array $tokens, array $index): array
    {
        $ids = [];
        foreach ($tokens as $token) {
            if (str_starts_with($token, 'email:')) $ids = array_merge($ids, $index['email'][substr($token, 6)] ?? []);
            if (str_starts_with($token, 'url:')) $ids = array_merge($ids, $index['url'][substr($token, 4)] ?? []);
        }
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) return ['none', null];
        if (count($ids) === 1) return ['matched', $ids[0]];
        return ['conflict', null];
    }

    private function owner(array $rows, array $index): array
    {
        $names = [];
        foreach ($rows as $row) {
            $value = trim((string) $this->value($row->raw_source ?? [], ['Belongs to']));
            if ($value !== '') $names[mb_strtolower($value)] = $value;
        }
        $values = array_values($names);
        if ($values === []) return ['none', null, ['source_values' => []]];
        if (count($values) > 1) return ['multiple', null, ['source_values' => $values]];
        $ids = $index[mb_strtolower($values[0])] ?? [];
        if ($ids === []) return ['unresolved', null, ['source_values' => $values, 'candidate_user_ids' => []]];
        if (count($ids) > 1) return ['conflict', null, ['source_values' => $values, 'candidate_user_ids' => $ids]];
        return ['matched', $ids[0], ['source_values' => $values, 'candidate_user_ids' => $ids]];
    }

    private function socials(array $rows, array $urls): array
    {
        $observations = [];
        foreach ($rows as $row) {
            $rowUrls = $this->profileUrls($row->raw_source ?? []);
            $followersRaw = trim((string) $this->value($row->raw_source ?? [], ['Followers']));
            foreach ($rowUrls as $url) {
                $social = $this->socialFromUrl($url);
                $rank = $this->rank($row);
                $observation = array_merge($social, ['followers' => null, 'raw_followers_value' => null, 'measured_at' => null, '_rank' => $rank]);
                if (count($rowUrls) === 1 && $followersRaw !== '') {
                    $observation['followers'] = $this->followers($followersRaw);
                    $observation['raw_followers_value'] = mb_substr($followersRaw, 0, 32);
                    $observation['measured_at'] = $this->inputTime($row) ?? now()->toIso8601String();
                }
                if (! isset($observations[$url]) || $rank >= $observations[$url]['_rank']) $observations[$url] = $observation;
            }
        }
        foreach ($urls as $url) if (! isset($observations[$url])) $observations[$url] = array_merge($this->socialFromUrl($url), ['followers' => null, 'raw_followers_value' => null, 'measured_at' => null, '_rank' => 0]);
        ksort($observations);
        return array_values(array_map(function (array $item): array { unset($item['_rank']); return $item; }, $observations));
    }

    private function socialFromUrl(string $url): array
    {
        $parts = parse_url($url); $host = strtolower((string) ($parts['host'] ?? '')); $path = trim((string) ($parts['path'] ?? ''), '/');
        $segments = array_values(array_filter(explode('/', $path), fn ($v) => $v !== ''));
        $platform = 'other'; $handle = null;
        if (str_contains($host, 'instagram.com')) { $platform = 'instagram'; $handle = $segments[0] ?? null; }
        elseif (str_contains($host, 'tiktok.com')) { $platform = 'tiktok'; $handle = isset($segments[0]) ? ltrim($segments[0], '@') : null; }
        elseif ($host === 'x.com' || str_ends_with($host, '.x.com') || str_contains($host, 'twitter.com')) { $platform = 'twitter'; $handle = $segments[0] ?? null; }
        elseif (str_contains($host, 'youtube.com') || $host === 'youtu.be') { $platform = 'youtube'; $handle = isset($segments[0]) && str_starts_with($segments[0], '@') ? ltrim($segments[0], '@') : null; }
        elseif (str_contains($host, 'facebook.com')) { $platform = 'facebook'; $handle = $segments[0] ?? null; }
        if (in_array(strtolower((string) $handle), ['p', 'reel', 'reels', 'tv', 'watch', 'channel'], true)) $handle = null;
        return ['platform' => $platform, 'handle' => $handle ? mb_substr($handle, 0, 255) : null, 'profile_url' => $url, 'is_active' => true];
    }

    private function displayName(array $socials, ?string $email, array $urls): string
    {
        $priority = ['instagram' => 1, 'tiktok' => 2, 'twitter' => 3, 'youtube' => 4, 'facebook' => 5, 'other' => 9];
        usort($socials, fn ($a, $b) => [($priority[$a['platform']] ?? 99), (string) ($a['handle'] ?? '')] <=> [($priority[$b['platform']] ?? 99), (string) ($b['handle'] ?? '')]);
        foreach ($socials as $social) if (! empty($social['handle'])) return mb_substr((string) $social['handle'], 0, 255);
        if ($email) return mb_substr((string) strstr($email, '@', true), 0, 255);
        if ($urls !== []) {
            $parts = parse_url($urls[0]); $path = trim((string) ($parts['path'] ?? ''), '/');
            $last = basename($path); if ($last !== '' && $last !== '.') return mb_substr($last, 0, 255);
            return mb_substr((string) ($parts['host'] ?? 'KOL'), 0, 255);
        }
        return '';
    }

    private function history(array $rows): array
    {
        $orders = $this->distinct($rows, ['单号 Order No.']);
        $published = $this->distinct($rows, ['发布合作的URL']);
        $skus = $this->distinct($rows, ['SKU']);
        $statuses = $this->distinct($rows, ['Status 进度']);
        $times = array_values(array_filter(array_map(fn ($row) => $this->inputTime($row), $rows)));
        sort($times);
        return [
            'source_row_count' => count($rows),
            'source_order_count' => count($orders),
            'published_url_count' => count($published),
            'sku_count' => count($skus),
            'statuses' => array_slice($statuses, 0, 20),
            'first_input_time' => $times[0] ?? null,
            'last_input_time' => $times ? $times[count($times) - 1] : null,
        ];
    }

    private function variations(array $rows): array
    {
        $fields = [
            'country' => ['国 Country'], 'phone' => ['Phone'], 'tax_id' => ['税Tax Number'], 'shipping_name' => ['Full name'],
            'shipping_state' => ['State'], 'shipping_city' => ['City'], 'shipping_postal' => ['Zipcode'], 'shipping_address' => ['Address Street'],
        ];
        $out = [];
        foreach ($fields as $key => $headers) {
            $values = $this->distinct($rows, $headers);
            if (count($values) > 1) $out[$key] = array_slice($values, 0, 10);
        }
        return $out;
    }

    private function latestEmail(array $rows): ?string
    {
        $best = null; $bestRank = -1;
        foreach ($rows as $row) {
            $email = $this->normalizeEmail(($row->normalized_identity ?? [])['email'] ?? $this->value($row->raw_source ?? [], self::EMAIL_HEADERS));
            if ($email !== null && $this->rank($row) >= $bestRank) { $best = $email; $bestRank = $this->rank($row); }
        }
        return $best;
    }

    private function latestValue(array $rows, array $headers): ?string
    {
        $best = null; $bestRank = -1;
        foreach ($rows as $row) {
            $value = trim((string) $this->value($row->raw_source ?? [], $headers));
            if ($value !== '' && $this->rank($row) >= $bestRank) { $best = $value; $bestRank = $this->rank($row); }
        }
        return $best;
    }

    private function distinct(array $rows, array $headers): array
    {
        $values = [];
        foreach ($rows as $row) {
            $value = trim((string) $this->value($row->raw_source ?? [], $headers));
            if ($value !== '') $values[mb_strtolower($value)] = $value;
        }
        $out = array_values($values); sort($out, SORT_NATURAL | SORT_FLAG_CASE); return $out;
    }

    private function profileUrls(array $raw): array
    {
        $value = (string) $this->value($raw, self::PROFILE_HEADERS);
        if ($value === '') return [];
        preg_match_all('~https?://[^\s]+~iu', $value, $matches);
        $urls = [];
        foreach ($matches[0] ?? [] as $match) {
            $url = $this->normalizeUrl(rtrim($match, ",;)]}"));
            if ($url !== null) $urls[$url] = true;
        }
        return array_keys($urls);
    }

    private function normalizeUrl(mixed $value): ?string
    {
        $value = trim((string) $value); if ($value === '') return null;
        $parts = parse_url($value);
        if ($parts === false || ! isset($parts['scheme'], $parts['host']) || ! in_array(strtolower($parts['scheme']), ['http', 'https'], true)) return null;
        $path = rtrim($parts['path'] ?? '', '/');
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $query = array_key_exists('query', $parts) ? '?'.$parts['query'] : '';
        return strtolower($parts['scheme']).'://'.strtolower($parts['host']).$port.$path.$query;
    }

    private function normalizeEmail(mixed $value): ?string
    {
        $value = strtolower(trim((string) $value)); return filter_var($value, FILTER_VALIDATE_EMAIL) ? $value : null;
    }

    private function followers(string $raw): ?int
    {
        $value = strtoupper(str_replace([',', ' '], '', trim($raw)));
        if (preg_match('/^([0-9]+(?:\.[0-9]+)?)([KMB])?$/', $value, $m) !== 1) return null;
        $multiplier = ['' => 1, 'K' => 1000, 'M' => 1000000, 'B' => 1000000000][$m[2] ?? ''] ?? 1;
        return (int) round(((float) $m[1]) * $multiplier);
    }

    private function inputTime(KolReconciliationRow $row): ?string
    {
        $raw = trim((string) $this->value($row->raw_source ?? [], ['Input Time']));
        if ($raw === '') return null;
        if (is_numeric($raw) && (float) $raw > 20000) {
            $seconds = (int) round(((float) $raw - 25569) * 86400);
            return gmdate('c', $seconds);
        }
        try { return (new DateTimeImmutable($raw))->format(DATE_ATOM); } catch (\Throwable) { return null; }
    }

    private function rank(KolReconciliationRow $row): int
    {
        $time = $this->inputTime($row); $timestamp = $time ? (strtotime($time) ?: 0) : 0;
        return ($timestamp * 10000) + (int) $row->source_row_number;
    }

    private function countryCode(?string $country): ?string
    {
        if ($country === null || trim($country) === '') return null;
        $value = mb_strtolower(trim($country));
        $direct = [
            'us' => 'US', 'usa' => 'US', 'united states' => 'US', 'united states of america' => 'US',
            'gb' => 'GB', 'uk' => 'GB', 'united kingdom' => 'GB', 'great britain' => 'GB', 'england' => 'GB',
            'de' => 'DE', 'germany' => 'DE', 'deutschland' => 'DE', 'fr' => 'FR', 'france' => 'FR',
            'ca' => 'CA', 'canada' => 'CA', 'au' => 'AU', 'australia' => 'AU', 'ph' => 'PH', 'philippines' => 'PH',
        ];
        if (isset($direct[$value])) return $direct[$value];
        $eu = ['austria','belgium','bulgaria','croatia','cyprus','czech republic','czechia','denmark','estonia','finland','greece','hungary','ireland','italy','italia','latvia','lithuania','luxembourg','malta','netherlands','poland','portugal','romania','slovakia','slovenia','spain','sweden'];
        return in_array($value, $eu, true) ? 'EU' : 'OTHER';
    }

    private function value(array $raw, array $headers): mixed
    {
        foreach ($headers as $header) if (array_key_exists($header, $raw)) return $raw[$header];
        $wanted = array_map(fn ($h) => mb_strtolower(trim($h)), $headers);
        foreach ($raw as $key => $value) if (in_array(mb_strtolower(trim((string) $key)), $wanted, true)) return $value;
        return null;
    }

    private function authorize(Authenticatable $actor): void
    {
        if (! $actor->can('kol.operate') || ! $actor->can('kol.affiliates.manage')) throw new AuthorizationException('KOL import preview management permission is required.');
    }

    private function message(string $status, array $issues): string
    {
        return match ($status) {
            'ready_new_kol' => '精确身份已收敛，可作为新 KOL 导入候选预览。',
            'needs_review' => '候选身份已收敛，但存在需人工复核的字段或责任人差异：'.implode(', ', $issues),
            'existing_kol' => '精确身份已对应现有 ERP KOL，不生成新 KOL 候选。',
            'conflict' => '精确身份证据指向多个现有 ERP KOL，禁止自动导入。',
            default => '未解析。',
        };
    }
}
