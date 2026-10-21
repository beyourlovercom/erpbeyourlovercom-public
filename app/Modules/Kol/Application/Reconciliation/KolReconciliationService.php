<?php

namespace App\Modules\Kol\Application\Reconciliation;

use App\Modules\Kol\Infrastructure\Models\Kol;
use App\Modules\Kol\Infrastructure\Models\KolAffiliateAccount;
use App\Modules\Kol\Infrastructure\Models\KolReconciliationBatch;
use App\Modules\Kol\Infrastructure\Models\KolReconciliationRow;
use DOMDocument;
use DOMXPath;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use ZipArchive;

final class KolReconciliationService
{
    private const MAX_FILE = 64 * 1024 * 1024;
    private const MAX_ROWS = 5000;
    private const MAX_ZIP_ENTRIES = 200;
    private const MAX_UNCOMPRESSED = 96 * 1024 * 1024;
    private const MAX_ENTRY_UNCOMPRESSED = 16 * 1024 * 1024;
    private const ALIASES = [
        'email' => ['email', 'e-mail', 'contact_email', 'contact email', 'contact email', '邮箱', '联系邮箱'],
        'platform' => ['platform', 'social_platform', 'social platform', '平台'],
        'handle' => ['handle', 'username', 'user_name', 'social_handle', '账号', '用户名'],
        'profile_url' => ['profile_url', 'profile url', 'social_url', 'social url', 'social_profile_url', 'influencer`s link/url', "influencer's link/url", '主页链接', '社交链接'],
    ];

    public function preview(UploadedFile $file, string $connectionKey, ?Authenticatable $actor): KolReconciliationBatch
    {
        if (($file->getSize() ?? 0) > self::MAX_FILE) abort(422, '文件超过 64 MiB 限制。');
        $source = strtolower((string) $file->getClientOriginalExtension());
        if (! in_array($source, ['csv', 'xlsx'], true)) abort(422, '仅支持 CSV 或 XLSX 文件。');
        [$headers, $rows] = $source === 'csv' ? $this->csv($file) : $this->xlsx($file);
        if ($headers === []) abort(422, '文件缺少表头或格式无效。');
        if (count($rows) > self::MAX_ROWS) abort(422, '单批次最多 5,000 条非空数据行。');
        $map = $this->mapHeaders($headers);
        if ($map['duplicates'] !== []) abort(422, '存在重复的身份列：'.implode('、', $map['duplicates']));
        if ($map['columns'] === []) abort(422, '文件必须包含 email、platform、handle 或 profile_url 身份列。');
        $indexes = $this->buildIndexes($connectionKey);

        return DB::transaction(function () use ($file, $source, $headers, $rows, $map, $connectionKey, $actor, $indexes): KolReconciliationBatch {
            $batch = KolReconciliationBatch::create([
                'source_type' => $source, 'original_filename' => Str::limit((string) $file->getClientOriginalName(), 255, ''),
                'checksum' => hash_file('sha256', $file->getRealPath()), 'connection_key' => $connectionKey,
                'creator_user_id' => $actor?->getAuthIdentifier(), 'status' => 'preview',
            ]);
            $identityKeys = [];
            $summary = array_fill_keys(['matched_kol_only', 'ready_for_binding', 'already_bound', 'no_affiliate', 'unresolved', 'conflict'], 0);
            foreach ($rows as $offset => $raw) {
                $rawSource = [];
                foreach ($headers as $i => $header) $rawSource[(string) $header] = $raw[$i] ?? null;
                $identity = $this->identity($raw, $map['columns']);
                $sourceKey = $identity['key'];
                if ($sourceKey !== null) $identityKeys[$sourceKey] = true;
                $result = $this->resolve($identity, $indexes);
                $summary[$result['reconciliation_status']]++;
                KolReconciliationRow::create([
                    'batch_id' => $batch->id, 'source_row_number' => $offset + 2,
                    'row_hash' => hash('sha256', json_encode($rawSource, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
                    'raw_source' => $rawSource, 'normalized_identity' => $identity,
                    'source_identity_key' => $sourceKey, 'matched_kol_id' => $result['kol_id'],
                    'matched_affiliate_account_id' => $result['affiliate_account_id'], 'kol_status' => $result['kol_status'],
                    'affiliate_status' => $result['affiliate_status'], 'reconciliation_status' => $result['reconciliation_status'],
                    'exact_match_evidence' => $result['evidence'], 'message' => $result['message'],
                ]);
            }
            $batch->update(['total_rows' => count($rows), 'distinct_source_identities' => count($identityKeys), 'preview_summary' => $summary]);
            return $batch->load(['rows.kol']);
        });
    }

    public function workspace(string $connectionKey): array
    {
        $batches = KolReconciliationBatch::query()->where('connection_key', $connectionKey)->latest('id')->limit(20)->get();
        return $batches->map(fn (KolReconciliationBatch $batch): array => [
            'id' => $batch->id, 'source_type' => $batch->source_type, 'original_filename' => $batch->original_filename,
            'checksum' => $batch->checksum, 'total_rows' => $batch->total_rows, 'distinct_source_identities' => $batch->distinct_source_identities,
            'status' => $batch->status, 'summary' => $batch->preview_summary ?? [], 'created_at' => $batch->created_at?->toIso8601String(),
            'rows' => [],
        ])->values()->all();
    }

    private function buildIndexes(string $connectionKey): array
    {
        $kols = Kol::query()->active()->with(['socialAccounts' => fn ($q) => $q->active()])->get(['id', 'contact_email']);
        $index = ['profile_url' => [], 'platform_handle' => [], 'contact_email' => [], 'affiliate_email' => []];
        foreach ($kols as $kol) {
            $id = (int) $kol->id;
            $email = $this->normalizeEmail($kol->contact_email);
            if ($email !== null) $index['contact_email'][$email][] = $id;
            foreach ($kol->socialAccounts as $account) {
                $url = $this->normalizeUrl($account->profile_url);
                if ($url !== null) $index['profile_url'][$url][] = $id;
                $platform = $this->normalizeText($account->platform);
                $handle = $this->normalizeHandle($account->handle);
                if ($platform !== null && $handle !== null) $index['platform_handle'][$platform.'|'.$handle][] = $id;
            }
        }
        $accounts = KolAffiliateAccount::query()->where('connection_key', $connectionKey)->get(['id', 'affiliate_id', 'kol_id', 'email', 'wordpress_user_email']);
        foreach ($accounts as $account) {
            foreach ([$account->email, $account->wordpress_user_email] as $email) {
                $email = $this->normalizeEmail($email);
                if ($email !== null) $index['affiliate_email'][$email][] = (int) $account->id;
            }
        }
        foreach ($index as $key => $values) foreach ($values as $value => $ids) $index[$key][$value] = array_values(array_unique($ids));
        $index['accounts'] = $accounts->keyBy('id');
        return $index;
    }

    private function resolve(array $identity, array $indexes): array
    {
        $evidence = [];
        $candidateSets = [];
        $social = $identity['social'] ?? [];
        if (($social['profile_url'] ?? null) !== null) $candidateSets['profile_url'] = $indexes['profile_url'][$social['profile_url']] ?? [];
        if (($social['platform'] ?? null) !== null && ($social['handle'] ?? null) !== null) $candidateSets['platform_handle'] = $indexes['platform_handle'][$social['platform'].'|'.$social['handle']] ?? [];
        if (($identity['email'] ?? null) !== null) $candidateSets['contact_email'] = $indexes['contact_email'][$identity['email']] ?? [];
        foreach ($candidateSets as $key => $ids) $evidence[$key] = array_values(array_unique(array_map('intval', $ids)));
        $all = array_values(array_unique(array_merge(...array_values($candidateSets ?: [[]]))));
        $kolStatus = $all === [] ? 'unresolved' : (($candidateSets !== [] && count($all) === 1 && count(array_filter($candidateSets, fn ($ids) => count($ids) > 1)) === 0) ? 'matched' : 'conflict');
        $kolId = $kolStatus === 'matched' ? $all[0] : null;
        $accountIds = ($identity['email'] ?? null) !== null ? ($indexes['affiliate_email'][$identity['email']] ?? []) : [];
        $accounts = collect($accountIds)->map(fn (int $id) => $indexes['accounts']->get($id))->filter()->values();
        $account = $accounts->count() === 1 ? $accounts->first() : null;
        if ($accountIds !== []) $evidence['affiliate_email'] = $accountIds;
        $affiliateStatus = $account ? ($account->kol_id === null ? 'unbound' : ($account->kol_id === $kolId ? 'bound' : 'bound_elsewhere')) : 'missing';
        $affiliateStatus = $accounts->count() > 1 ? 'conflict' : $affiliateStatus;
        if ($kolStatus === 'conflict' || $accounts->count() > 1) {
            $status = 'conflict';
        } elseif (($identity['email'] ?? null) !== null) {
            if ($accounts->count() === 0) {
                $status = 'no_affiliate';
            } elseif ($kolStatus !== 'matched') {
                $status = 'unresolved';
            } elseif ($affiliateStatus === 'unbound') {
                $status = 'ready_for_binding';
            } elseif ($affiliateStatus === 'bound') {
                $status = 'already_bound';
            } else {
                $status = 'conflict';
            }
        } else {
            $status = $kolStatus === 'matched' ? 'matched_kol_only' : 'unresolved';
        }
        return ['kol_id' => $kolId, 'affiliate_account_id' => $account?->id, 'kol_status' => $kolStatus === 'matched' ? 'matched' : $kolStatus, 'affiliate_status' => $affiliateStatus, 'reconciliation_status' => $status, 'evidence' => $evidence, 'message' => $this->message($status)];
    }

    private function identity(array $row, array $columns): array
    {
        $email = isset($columns['email']) ? $this->normalizeEmail($row[$columns['email']] ?? null) : null;
        $platform = isset($columns['platform']) ? $this->normalizeText($row[$columns['platform']] ?? null) : null;
        $handle = isset($columns['handle']) ? $this->normalizeHandle($row[$columns['handle']] ?? null) : null;
        $url = isset($columns['profile_url']) ? trim((string) ($row[$columns['profile_url']] ?? '')) : null;
        if ($url !== '') $url = $this->normalizeUrl($url); else $url = null;
        if ($platform === '') $platform = null; if ($handle === '') $handle = null;
        $social = array_filter(['platform' => $platform, 'handle' => $handle, 'profile_url' => $url], fn ($v) => $v !== null);
        $parts = []; if ($email) $parts[] = 'email:'.$email; if ($url) $parts[] = 'url:'.$url; if ($platform && $handle) $parts[] = 'social:'.$platform.':'.$handle;
        return ['email' => $email, 'social' => $social, 'key' => $parts === [] ? null : implode('|', $parts)];
    }

    private function normalizeUrl(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') return null;
        $parts = parse_url($value);
        if ($parts === false || ! isset($parts['scheme'], $parts['host']) || ! in_array(strtolower($parts['scheme']), ['http', 'https'], true)) return null;
        $path = rtrim($parts['path'] ?? '', '/');
        $port = $parts['port'] ?? null;
        $portSuffix = $port !== null ? ':'.$port : '';
        $querySuffix = array_key_exists('query', $parts) ? '?'.$parts['query'] : '';
        return strtolower($parts['scheme']).'://'.strtolower($parts['host']).$portSuffix.$path.$querySuffix;
    }

    private function normalizeEmail(mixed $value): ?string { $value = strtolower(trim((string) $value)); return filter_var($value, FILTER_VALIDATE_EMAIL) ? $value : null; }
    private function normalizeText(mixed $value): ?string { $value = strtolower(trim((string) $value)); return $value === '' ? null : $value; }
    private function normalizeHandle(mixed $value): ?string { $value = strtolower(ltrim(trim((string) $value), '@')); return $value === '' ? null : $value; }

    private function message(string $status): string { return ['matched_kol_only' => 'KOL 已匹配，但没有可用源邮箱进行 Affiliate 精确核对。', 'ready_for_binding' => '唯一 Affiliate 未绑定，可供人工复核绑定。', 'already_bound' => 'Affiliate 已绑定到该 KOL。', 'no_affiliate' => '当前 AffiliateWP connection 中没有精确匹配的 Affiliate。', 'unresolved' => '没有可用的精确身份证据或 ERP KOL 候选。', 'conflict' => '精确身份证据指向多个或相互冲突的候选。'][$status]; }

    private function mapHeaders(array $headers): array { $columns = []; $duplicates = []; foreach ($headers as $i => $header) { $name = mb_strtolower(trim((string) $header)); $found = null; foreach (self::ALIASES as $key => $aliases) if (in_array($name, $aliases, true)) { $found = $key; break; } if ($found !== null) { if (isset($columns[$found])) $duplicates[] = $found; else $columns[$found] = $i; } } return ['columns' => $columns, 'duplicates' => $duplicates]; }
    private function csv(UploadedFile $file): array { $h = fopen($file->getRealPath(), 'rb'); $headers = fgetcsv($h) ?: []; if (isset($headers[0])) $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', $headers[0]); $rows = []; while (($row = fgetcsv($h)) !== false) if (collect($row)->filter(fn ($v) => trim((string) $v) !== '')->isNotEmpty()) $rows[] = $row; fclose($h); return [$headers, $rows]; }
    private function xlsx(UploadedFile $file): array
    {
        $zip = new ZipArchive();
        if ($zip->open($file->getRealPath()) !== true) abort(422, 'XLSX 文件无效。');
        try {
            if ($zip->numFiles > self::MAX_ZIP_ENTRIES) abort(422, 'XLSX ZIP 条目超限。');
            $total = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $name = str_replace('\\', '/', (string) ($stat['name'] ?? ''));
                $entrySize = (int) ($stat['size'] ?? 0);
                if ($name === '' || str_starts_with($name, '/') || in_array('..', explode('/', $name), true)) abort(422, 'XLSX 包含非法路径。');
                if ($entrySize > self::MAX_ENTRY_UNCOMPRESSED) abort(422, 'XLSX 单个 ZIP 条目解压大小超限。');
                $total += $entrySize;
                if ($total > self::MAX_UNCOMPRESSED) abort(422, 'XLSX 解压大小超限。');
            }
            $sheetPath = $this->firstWorksheetPath($zip);
            if ($sheetPath === null) abort(422, 'XLSX 工作表结构无效。');
            $sheetXml = $zip->getFromName($sheetPath);
            if ($sheetXml === false) abort(422, 'XLSX 第一工作表不存在。');
            $rows = $this->sheetRows($sheetXml, $this->sharedStrings($zip));
            if ($rows === []) abort(422, 'XLSX 第一工作表为空或格式无效。');
            $headers = array_shift($rows) ?? [];
            return [$headers, $rows];
        } finally {
            $zip->close();
        }
    }

    private function firstWorksheetPath(ZipArchive $zip): ?string
    {
        $workbook = $this->xml($zip->getFromName('xl/workbook.xml'));
        $rels = $this->xml($zip->getFromName('xl/_rels/workbook.xml.rels'));
        if ($workbook === null || $rels === null) return null;
        $xp = new DOMXPath($workbook);
        $xp->registerNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $xp->registerNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');
        $sheet = $xp->query('//m:sheets/m:sheet[1]')->item(0);
        $relId = $sheet?->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id');
        if ($relId === null || $relId === '') return null;
        $rxp = new DOMXPath($rels);
        $rxp->registerNamespace('p', 'http://schemas.openxmlformats.org/package/2006/relationships');
        $relationship = null;
        foreach ($rxp->query('//p:Relationship') as $candidate) {
            if ($candidate->getAttribute('Id') === $relId) { $relationship = $candidate; break; }
        }
        if ($relationship === null || strcasecmp($relationship->getAttribute('TargetMode'), 'External') === 0) return null;
        $target = ltrim(str_replace('\\', '/', $relationship->getAttribute('Target')), '/');
        if ($target === '' || in_array('..', explode('/', $target), true)) return null;
        return str_starts_with($target, 'xl/') ? $target : 'xl/'.$target;
    }

    private function sharedStrings(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if ($xml === false) return [];
        $doc = $this->xml($xml);
        if ($doc === null) abort(422, 'XLSX sharedStrings 结构无效。');
        $xp = new DOMXPath($doc);
        $xp->registerNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $result = [];
        foreach ($xp->query('//m:si') as $item) {
            $value = '';
            foreach ($xp->query('.//m:t', $item) as $text) $value .= $text->textContent;
            $result[] = $value;
        }
        return $result;
    }

    private function sheetRows(string $xml, array $sharedStrings): array
    {
        $doc = $this->xml($xml);
        if ($doc === null) abort(422, 'XLSX 工作表 XML 无效。');
        $xp = new DOMXPath($doc);
        $xp->registerNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $rows = [];
        foreach ($xp->query('//m:sheetData/m:row') as $rowNode) {
            $cells = [];
            foreach ($xp->query('./m:c', $rowNode) as $cell) {
                if (! preg_match('/^([A-Z]+)\d+$/i', $cell->getAttribute('r'), $match)) continue;
                $value = $xp->query('./m:v', $cell)->item(0)?->textContent ?? '';
                $type = $cell->getAttribute('t');
                if ($type === 's') $value = $sharedStrings[(int) $value] ?? '';
                if ($type === 'inlineStr') { $value = ''; foreach ($xp->query('./m:is//m:t', $cell) as $text) $value .= $text->textContent; }
                $cells[$this->columnIndex(strtoupper($match[1]))] = $value;
            }
            if ($cells === [] || collect($cells)->filter(fn ($value) => trim((string) $value) !== '')->isEmpty()) continue;
            $rows[] = array_map(fn ($i) => $cells[$i] ?? null, range(0, max(array_keys($cells))));
            if (count($rows) > self::MAX_ROWS + 1) abort(422, '单批次最多 5,000 条非空数据行。');
        }
        return $rows;
    }

    private function xml(string|false $value): ?DOMDocument
    {
        if ($value === false || $value === '') return null;
        $doc = new DOMDocument();
        $old = libxml_use_internal_errors(true);
        try { return $doc->loadXML($value, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING) ? $doc : null; }
        finally { libxml_clear_errors(); libxml_use_internal_errors($old); }
    }

    private function columnIndex(string $letters): int
    {
        $index = 0;
        foreach (str_split($letters) as $letter) $index = $index * 26 + ord($letter) - 64;
        return $index - 1;
    }
}
