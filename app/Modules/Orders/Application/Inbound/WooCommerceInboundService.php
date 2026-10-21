<?php

namespace App\Modules\Orders\Application\Inbound;

use App\Modules\Orders\Application\Contracts\WooCommerceOrderGateway;
use App\Modules\Orders\Application\OrderService;
use App\Modules\Sync\Application\Contracts\SyncRunTracker;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Contracts\Auth\Authenticatable;
use Throwable;

final class WooCommerceInboundService
{
    private const PER_PAGE = 100;

    private const MAX_PAGES = 100;

    private const OVERLAP_MINUTES = 5;

    private const AUTOMATIC_RUN_TYPE = 'woocommerce_order_incremental';

    private const MANUAL_TODAY_RUN_TYPE = 'woocommerce_order_manual_today';

    public function __construct(
        private readonly WooCommerceOrderGateway $gateway,
        private readonly WooCommerceOrderNormalizer $normalizer,
        private readonly OrderService $orders,
        private readonly SyncRunTracker $runs,
    ) {}

    public function descriptor(): array
    {
        $descriptor = $this->gateway->descriptor();
        $descriptor['last_import_at'] = $this->lastImportedAt($descriptor['connection_key']);

        return $descriptor;
    }

    public function probe(): array
    {
        return $this->gateway->probe();
    }

    public function importOne(int $providerOrderId, ?Authenticatable $actor): array
    {
        $payload = $this->gateway->fetchOrder($providerOrderId);

        return $this->importPayloads([$payload], $actor);
    }

    public function importIncremental(?Authenticatable $actor): array
    {
        $connectionKey = $this->gateway->descriptor()['connection_key'];
        $lastImportedAt = $this->lastImportedAt($connectionKey);
        if ($lastImportedAt === null) {
            throw new DomainException('尚无 WooCommerce 增量游标，请先执行单笔导入或历史导入。');
        }

        $modifiedAfter = CarbonImmutable::parse($lastImportedAt)->subMinutes(self::OVERLAP_MINUTES)->utc()->toIso8601String();

        return $this->importPages([
            'modified_after' => $modifiedAfter,
            'status' => 'any',
            'per_page' => self::PER_PAGE,
        ], $actor);
    }

    public function importToday(?Authenticatable $actor): array
    {
        $descriptor = $this->gateway->descriptor();
        $connectionKey = (string) $descriptor['connection_key'];
        $now = CarbonImmutable::now('UTC');
        $after = $now->startOfDay()->toIso8601String();
        $before = $now->toIso8601String();
        $run = $this->runs->start(
            self::MANUAL_TODAY_RUN_TYPE,
            0,
            $actor,
            'woocommerce',
            $connectionKey,
            ['mode' => 'manual_today', 'domain' => 'orders', 'after' => $after, 'before' => $before],
        );

        try {
            $summary = $this->importManualTodayPages([
                'after' => $after,
                'before' => $before,
                'status' => 'any',
                'orderby' => 'date',
                'order' => 'asc',
                'per_page' => self::PER_PAGE,
            ], $run, $connectionKey, $actor);
            $this->runs->setRequestedItems($run, $summary['seen']);
            $completed = $this->runs->complete($run, $actor);

            return [
                ...$summary,
                'run_id' => (int) $completed->getKey(),
                'run_status' => $completed->status instanceof \BackedEnum ? $completed->status->value : (string) $completed->status,
            ];
        } catch (Throwable $e) {
            $this->recordManualBatchFailure($run, $actor, $e);
            throw $e;
        }
    }

    public function importAutomaticIncremental(): array
    {
        $descriptor = $this->gateway->descriptor();
        $connectionKey = (string) $descriptor['connection_key'];
        $checkpoint = $this->automaticCheckpoint($connectionKey);
        if ($checkpoint === null) {
            throw new DomainException('尚无 WooCommerce 自动增量游标，请先完成单笔或历史导入。');
        }

        $modifiedAfter = CarbonImmutable::parse($checkpoint)
            ->subMinutes(self::OVERLAP_MINUTES)
            ->utc()
            ->toIso8601String();
        $run = $this->runs->start(
            self::AUTOMATIC_RUN_TYPE,
            0,
            null,
            'woocommerce',
            $connectionKey,
            ['mode' => 'automatic', 'checkpoint_before' => $checkpoint, 'modified_after' => $modifiedAfter],
        );

        try {
            $summary = $this->importAutomaticPages([
                'modified_after' => $modifiedAfter,
                'status' => 'any',
                'per_page' => self::PER_PAGE,
            ], $run, $connectionKey);
            $this->runs->setRequestedItems($run, $summary['seen']);
            $checkpointAfter = $checkpoint;
            if ($summary['failed'] === 0 && $summary['source_max_modified_at'] !== null) {
                $checkpointAfter = $summary['source_max_modified_at'];
                $run = $this->runs->annotateContext($run, ['checkpoint_modified_at' => $checkpointAfter]);
            }
            $completed = $this->runs->complete($run, null);

            return [
                ...$summary,
                'run_id' => (int) $completed->getKey(),
                'run_status' => $completed->status instanceof \BackedEnum ? $completed->status->value : (string) $completed->status,
                'checkpoint_before' => $checkpoint,
                'checkpoint_after' => $summary['failed'] === 0 ? $checkpointAfter : $checkpoint,
            ];
        } catch (Throwable $e) {
            $this->recordAutomaticBatchFailure($run, $e);
            throw $e;
        }
    }

    public function importHistorical(string $from, string $to, ?Authenticatable $actor): array
    {
        $after = CarbonImmutable::parse($from)->startOfDay()->utc()->toIso8601String();
        $before = CarbonImmutable::parse($to)->endOfDay()->utc()->toIso8601String();

        return $this->importPages([
            'after' => $after,
            'before' => $before,
            'status' => 'any',
            'orderby' => 'date',
            'order' => 'asc',
            'per_page' => self::PER_PAGE,
        ], $actor);
    }

    private function importPages(array $baseQuery, ?Authenticatable $actor, bool $skipNonProductAdminShells = false): array
    {
        $summary = $this->emptySummary();
        $page = 1;
        $totalPages = 1;

        do {
            $result = $this->gateway->fetchOrders([...$baseQuery, 'page' => $page]);
            if ((int) $result['total_pages'] > self::MAX_PAGES) {
                throw new DomainException('WooCommerce 导入范围超过单次 10000 单上限，请缩小历史日期范围。');
            }
            $pageSummary = $this->importPayloads($result['items'], $actor, $skipNonProductAdminShells);
            $summary = $this->mergeSummary($summary, $pageSummary);
            $totalPages = max(1, (int) $result['total_pages']);
            $page++;
        } while ($page <= $totalPages);

        $summary['pages'] = $page - 1;

        return $summary;
    }

    private function importManualTodayPages(array $baseQuery, $run, string $connectionKey, ?Authenticatable $actor): array
    {
        $summary = $this->emptySummary();
        $page = 1;
        $totalPages = 1;

        do {
            $result = $this->gateway->fetchOrders([...$baseQuery, 'page' => $page]);
            if ((int) $result['total_pages'] > self::MAX_PAGES) {
                throw new DomainException('WooCommerce 今日手动同步范围超过单次 10000 单上限。');
            }
            $summary = $this->mergeSummary(
                $summary,
                $this->importManualTodayPayloads($result['items'], $run, $connectionKey, $actor),
            );
            $totalPages = max(1, (int) $result['total_pages']);
            $page++;
        } while ($page <= $totalPages);

        $summary['pages'] = $page - 1;

        return $summary;
    }

    /** @param list<array<string,mixed>> $payloads */
    private function importManualTodayPayloads(array $payloads, $run, string $connectionKey, ?Authenticatable $actor): array
    {
        $summary = $this->emptySummary();
        foreach ($payloads as $payload) {
            $summary['seen']++;
            $reference = (string) ($payload['id'] ?? '?');
            $item = $this->runs->addItem($run, [
                'operation' => 'woocommerce.order_inbound.manual_today',
                'provider_reference' => $reference === '?' ? null : $reference,
            ]);
            $item = $this->runs->markProcessing($item);

            if ($this->isDevSandboxOrder($payload)) {
                $summary['skipped']++;
                $this->runs->finishSkipped($item, 'dev_sandbox_order');

                continue;
            }

            if ($this->isNonProductAdminShell($payload)) {
                $summary['skipped']++;
                $this->runs->finishSkipped($item, 'non_product_admin_shell');

                continue;
            }

            try {
                $normalized = $this->normalizer->normalize($payload, $connectionKey);
                $result = $this->orders->upsertInbound($normalized, $actor);
                $outcome = $result['created'] ? 'created' : 'updated';
                $summary[$outcome]++;
                $this->runs->annotateItem($item, ['order_id' => $result['order']->getKey()]);
                $this->runs->finishSucceeded($item, $outcome);
            } catch (Throwable $e) {
                $summary['failed']++;
                if (count($summary['errors']) < 20) {
                    $summary['errors'][] = ['reference' => $reference, 'message' => $e->getMessage()];
                }
                $this->runs->finishFailed($item, 'failed', 'order_inbound_failed', $e->getMessage());
            }
        }

        return $summary;
    }

    private function importAutomaticPages(array $baseQuery, $run, string $connectionKey): array
    {
        $summary = $this->automaticEmptySummary();
        $page = 1;
        $totalPages = 1;
        do {
            $result = $this->gateway->fetchOrders([...$baseQuery, 'page' => $page]);
            if ((int) $result['total_pages'] > self::MAX_PAGES) {
                throw new DomainException('WooCommerce 自动增量范围超过单次 10000 单上限。');
            }
            $summary = $this->mergeAutomaticSummary(
                $summary,
                $this->importAutomaticPayloads($result['items'], $run, $connectionKey),
            );
            $totalPages = max(1, (int) $result['total_pages']);
            $page++;
        } while ($page <= $totalPages);
        $summary['pages'] = $page - 1;

        return $summary;
    }

    /** @param list<array<string,mixed>> $payloads */
    private function importAutomaticPayloads(array $payloads, $run, string $connectionKey): array
    {
        $summary = $this->automaticEmptySummary();
        foreach ($payloads as $payload) {
            $summary['seen']++;
            $reference = (string) ($payload['id'] ?? '?');
            $summary['source_max_modified_at'] = $this->laterTimestamp(
                $summary['source_max_modified_at'],
                $this->payloadModifiedAt($payload),
            );
            $item = $this->runs->addItem($run, [
                'operation' => 'woocommerce.order_inbound',
                'provider_reference' => $reference === '?' ? null : $reference,
            ]);
            $item = $this->runs->markProcessing($item);

            if ($this->isDevSandboxOrder($payload)) {
                $summary['skipped']++;
                $this->runs->finishSkipped($item, 'dev_sandbox_order');

                continue;
            }

            if ($this->isNonProductAdminShell($payload)) {
                $summary['skipped']++;
                $this->runs->finishSkipped($item, 'non_product_admin_shell');

                continue;
            }

            try {
                $normalized = $this->normalizer->normalize($payload, $connectionKey);
                $result = $this->orders->upsertInbound($normalized, null);
                $outcome = $result['created'] ? 'created' : 'updated';
                $summary[$outcome]++;
                $this->runs->annotateItem($item, ['order_id' => $result['order']->getKey()]);
                $this->runs->finishSucceeded($item, $outcome);
            } catch (Throwable $e) {
                $summary['failed']++;
                if (count($summary['errors']) < 20) {
                    $summary['errors'][] = ['reference' => $reference, 'message' => $e->getMessage()];
                }
                $this->runs->finishFailed($item, 'failed', 'order_inbound_failed', $e->getMessage());
            }
        }

        return $summary;
    }

    /** @param list<array<string,mixed>> $payloads */
    private function importPayloads(array $payloads, ?Authenticatable $actor, bool $skipNonProductAdminShells = false): array
    {
        $summary = $this->emptySummary();
        $connectionKey = $this->gateway->descriptor()['connection_key'];

        foreach ($payloads as $payload) {
            $summary['seen']++;
            $reference = (string) ($payload['id'] ?? '?');
            // Never persist explicitly marked BYL Dev sandbox checkout orders in ERP.
            if ($this->isDevSandboxOrder($payload)) {
                $summary['skipped']++;

                continue;
            }
            if ($skipNonProductAdminShells && $this->isNonProductAdminShell($payload)) {
                $summary['skipped']++;

                continue;
            }
            try {
                $normalized = $this->normalizer->normalize($payload, $connectionKey);
                $result = $this->orders->upsertInbound($normalized, $actor);
                $summary[$result['created'] ? 'created' : 'updated']++;
            } catch (Throwable $e) {
                $summary['failed']++;
                if (count($summary['errors']) < 20) {
                    $summary['errors'][] = ['reference' => $reference, 'message' => $e->getMessage()];
                }
            }
        }

        return $summary;
    }

    private function automaticCheckpoint(string $connectionKey): ?string
    {
        return $this->runs->latestContextString(
            self::AUTOMATIC_RUN_TYPE,
            'woocommerce',
            $connectionKey,
            'checkpoint_modified_at',
        ) ?? $this->lastImportedAt($connectionKey);
    }

    /**
     * Fail closed for the exact synthetic checkout identity and native Dev UAT marker.
     * Woo REST may omit private meta keys; the dedicated .invalid billing identity
     * is therefore independently sufficient to exclude a sandbox order.
     */
    private function isDevSandboxOrder(array $payload): bool
    {
        $email = $payload['billing']['email'] ?? null;
        if (is_string($email) && strtolower(trim($email)) === 'byl-p13@example.invalid') {
            return true;
        }

        foreach (($payload['meta_data'] ?? []) as $meta) {
            if (! is_array($meta) || ($meta['key'] ?? null) !== '_byl_dev_sandbox_p13') {
                continue;
            }

            $value = $meta['value'] ?? null;
            if (is_string($value) && strtolower(trim($value)) === 'yes') {
                return true;
            }
        }

        return false;
    }

    private function isNonProductAdminShell(array $payload): bool
    {
        return strtolower(trim((string) ($payload['created_via'] ?? ''))) === 'admin'
            && is_array($payload['line_items'] ?? null)
            && $payload['line_items'] === [];
    }

    private function payloadModifiedAt(array $payload): ?string
    {
        $value = trim((string) ($payload['date_modified_gmt'] ?? $payload['date_modified'] ?? ''));
        if ($value === '') {
            return null;
        }
        try {
            return CarbonImmutable::parse($value)->utc()->toIso8601String();
        } catch (Throwable) {
            return null;
        }
    }

    private function laterTimestamp(?string $current, ?string $candidate): ?string
    {
        if ($candidate === null) {
            return $current;
        }
        if ($current === null) {
            return $candidate;
        }

        return CarbonImmutable::parse($candidate)->greaterThan(CarbonImmutable::parse($current)) ? $candidate : $current;
    }

    private function recordManualBatchFailure($run, ?Authenticatable $actor, Throwable $error): void
    {
        try {
            $this->runs->setRequestedItems($run, 1);
            $item = $this->runs->addItem($run, ['operation' => 'woocommerce.order_inbound.manual_today.batch']);
            $item = $this->runs->markProcessing($item);
            $this->runs->finishFailed($item, 'failed', 'batch_failed', $error->getMessage());
            $this->runs->complete($run, $actor);
        } catch (Throwable) {
            // Preserve the original provider/runtime exception for the controller response.
        }
    }

    private function recordAutomaticBatchFailure($run, Throwable $error): void
    {
        try {
            $this->runs->setRequestedItems($run, 1);
            $item = $this->runs->addItem($run, ['operation' => 'woocommerce.order_inbound.batch']);
            $item = $this->runs->markProcessing($item);
            $this->runs->finishFailed($item, 'failed', 'batch_failed', $error->getMessage());
            $this->runs->complete($run, null);
        } catch (Throwable) {
            // Preserve the original provider/runtime exception for the command exit path.
        }
    }

    private function lastImportedAt(string $connectionKey): ?string
    {
        $referenceClass = 'App\\Modules\\Orders\\Infrastructure\\Models\\OrderExternalReference';
        $value = $referenceClass::query()
            ->where('provider', 'woocommerce')
            ->where('connection_key', $connectionKey)
            ->max('provider_modified_at');

        return $value ? CarbonImmutable::parse($value)->toIso8601String() : null;
    }

    private function emptySummary(): array
    {
        return ['seen' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0, 'pages' => 0, 'errors' => []];
    }

    private function automaticEmptySummary(): array
    {
        return [
            'seen' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0,
            'pages' => 0, 'errors' => [], 'source_max_modified_at' => null,
        ];
    }

    private function mergeSummary(array $left, array $right): array
    {
        foreach (['seen', 'created', 'updated', 'skipped', 'failed'] as $key) {
            $left[$key] += $right[$key];
        }
        $left['errors'] = array_slice([...$left['errors'], ...$right['errors']], 0, 20);

        return $left;
    }

    private function mergeAutomaticSummary(array $left, array $right): array
    {
        foreach (['seen', 'created', 'updated', 'skipped', 'failed'] as $key) {
            $left[$key] += $right[$key];
        }
        $left['errors'] = array_slice([...$left['errors'], ...$right['errors']], 0, 20);
        $left['source_max_modified_at'] = $this->laterTimestamp(
            $left['source_max_modified_at'],
            $right['source_max_modified_at'],
        );

        return $left;
    }
}
