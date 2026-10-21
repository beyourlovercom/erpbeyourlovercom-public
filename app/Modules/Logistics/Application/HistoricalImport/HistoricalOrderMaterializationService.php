<?php

namespace App\Modules\Logistics\Application\HistoricalImport;

use App\Modules\Audit\Application\AuditActor;
use App\Modules\Audit\Application\AuditSource;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Kol\Infrastructure\Models\Kol;
use App\Modules\Logistics\Infrastructure\Models\HistoricalEvidence;
use App\Modules\Logistics\Infrastructure\Models\HistoricalImportBatch;
use App\Modules\Logistics\Infrastructure\Models\HistoricalOrderMaterializationBatch;
use App\Modules\Logistics\Infrastructure\Models\HistoricalOrderMaterializationRow;
use App\Modules\Orders\Application\OrderIdentityService;
use App\Modules\Orders\Application\OrderKind;
use App\Modules\Orders\Application\OrderService;
use App\Modules\Orders\Application\OrderSourceType;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Orders\Infrastructure\Models\OrderExternalReference;
use App\Modules\Products\Application\SkuIdentityResolver;
use App\Modules\Products\Infrastructure\Models\Product;
use App\Modules\Products\Infrastructure\Models\ProductSkuAlias;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class HistoricalOrderMaterializationService
{
    private const PROVIDER = 'historical_logistics';
    private const HISTORICAL_AS_OF = '2026-09-06';

    public function __construct(
        private readonly SkuIdentityResolver $skuResolver,
        private readonly OrderService $orders,
        private readonly OrderIdentityService $orderIdentities,
        private readonly AuditRecorder $audit,
    ) {}

    public function preview(HistoricalImportBatch $importBatch, Authenticatable $actor): HistoricalOrderMaterializationBatch
    {
        $this->authorize($actor, 'logistics.operate');
        if ($importBatch->status !== 'committed') {
            throw ValidationException::withMessages(['batch' => 'Historical order materialization requires committed historical evidence.']);
        }

        return DB::transaction(function () use ($importBatch, $actor): HistoricalOrderMaterializationBatch {
            $batch = HistoricalOrderMaterializationBatch::firstOrCreate(
                ['historical_import_batch_id' => $importBatch->id, 'source_checksum' => $importBatch->source_checksum],
                ['status' => 'preview', 'created_by' => $actor->getAuthIdentifier()],
            );
            if ($batch->wasRecentlyCreated) {
                $evidence = HistoricalEvidence::query()->where('batch_id', $importBatch->id)->orderBy('source_row_number')->get();
                $counts = ['ready' => 0, 'review' => 0, 'already_applied' => 0];
                $reservedErpOrderIds = [];
                foreach ($evidence as $item) {
                    $decision = $this->decide($item);
                    if ($decision['decision'] === 'ready') {
                        $reference = (string) data_get($item->evidence_snapshot, 'source_order_no');
                        $connection = $this->connectionKey($batch->source_checksum, (int) $batch->historical_import_batch_id);
                        $external = OrderExternalReference::query()
                            ->where('provider', self::PROVIDER)
                            ->where('connection_key', $connection)
                            ->where('reference', $reference)
                            ->first();
                        if ($external) {
                            $decision['decision'] = 'already_applied';
                            $decision['reason'] = 'exact_external_reference';
                            $decision['order_id'] = $external->order_id;
                        } else {
                            $erpOrderId = $this->reserveErpOrderId((int) $decision['manifest']['kol_id'], (string) $decision['manifest']['order_date'], $reservedErpOrderIds);
                            if ($erpOrderId === null) {
                                $decision['decision'] = 'review';
                                $decision['reason'] = 'erp_order_identity_exhausted';
                            } else {
                                $decision['manifest']['erp_order_id'] = $erpOrderId;
                            }
                        }
                    }
                    $counts[$decision['decision']]++;
                    $batch->rows()->create([
                        'historical_evidence_id' => $item->id,
                        'source_row_number' => $item->source_row_number,
                        'source_order_no' => data_get($item->evidence_snapshot, 'source_order_no'),
                        'decision' => $decision['decision'],
                        'decision_reason' => $decision['reason'],
                        'materialization_manifest' => $decision['manifest'],
                        'order_id' => $decision['order_id'] ?? null,
                    ]);
                }
                $batch->update(['total_rows' => array_sum($counts), 'ready_count' => $counts['ready'], 'review_count' => $counts['review'], 'already_applied_count' => $counts['already_applied']]);
            }
            if ($batch->wasRecentlyCreated) {
                $this->audit->record(AuditActor::user($actor, AuditSource::Web), 'logistics.historical_order_materialization.previewed', 'logistics_historical_order_materialization_batch', $batch->id, null, ['source_checksum' => $batch->source_checksum, 'ready_count' => $batch->ready_count, 'review_count' => $batch->review_count], 'historical_order_materialization_preview');
            }
            return $batch->fresh('rows');
        });
    }

    public function apply(HistoricalOrderMaterializationBatch $batch, Authenticatable $actor): HistoricalOrderMaterializationBatch
    {
        $this->authorize($actor, 'logistics.operate');
        if (! config('logistics.historical_order_materialization_apply_enabled', false)) abort(403, 'Historical order materialization is disabled in this environment.');

        return DB::transaction(function () use ($batch, $actor): HistoricalOrderMaterializationBatch {
            $locked = HistoricalOrderMaterializationBatch::query()->whereKey($batch->id)->lockForUpdate()->with(['importBatch', 'rows'])->firstOrFail();
            if ($locked->importBatch->status !== 'committed') throw ValidationException::withMessages(['batch' => 'Committed historical evidence is required before apply.']);
            if ($locked->status === 'applied') return $locked;
            if ($locked->status !== 'preview') throw ValidationException::withMessages(['batch' => 'Historical order materialization batch is not applyable.']);
            foreach ($locked->rows->where('decision', 'ready') as $row) {
                if ($row->order_id) continue;
                $manifest = $row->materialization_manifest;
                $reference = (string) $row->source_order_no;
                $connection = $this->connectionKey($locked->source_checksum, (int) $locked->historical_import_batch_id);
                $external = OrderExternalReference::query()->where('provider', self::PROVIDER)->where('connection_key', $connection)->where('reference', $reference)->lockForUpdate()->first();
                if ($external) {
                    $row->update(['order_id' => $external->order_id, 'decision' => 'already_applied', 'decision_reason' => 'exact_external_reference']);
                    continue;
                }
                $erpOrderId = trim((string) ($manifest['erp_order_id'] ?? ''));
                if ($erpOrderId === '' || Order::query()->where('erp_order_id', $erpOrderId)->exists()) {
                    throw ValidationException::withMessages(['batch' => 'Frozen ERP order identity is missing or no longer available; re-preview is required.']);
                }
                $kol = Kol::query()->findOrFail((int) $manifest['kol_id']);
                $order = $this->orders->create([
                    'source_type' => OrderSourceType::Kol->value,
                    'order_kind' => OrderKind::KolFulfillment->value,
                    'erp_order_id' => $erpOrderId,
                    'external_order_number' => $reference,
                    'order_date' => $manifest['order_date'],
                    'order_note' => 'Historical logistics evidence #'.$row->historical_evidence_id,
                    'currency' => 'USD',
                    ...$this->address($kol),
                ], $manifest['items'], $actor, allowMissingAddress: true);
                $order->externalReferences()->create(['provider' => self::PROVIDER, 'connection_key' => $connection, 'reference' => $reference]);
                $row->update(['order_id' => $order->id, 'decision' => 'applied', 'decision_reason' => 'materialized']);
            }
            $locked->update(['status' => 'applied']);
            $this->audit->record(AuditActor::user($actor, AuditSource::Web), 'logistics.historical_order_materialization.applied', 'logistics_historical_order_materialization_batch', $locked->id, null, ['source_checksum' => $locked->source_checksum], 'historical_order_materialization_apply');
            return $locked->fresh('rows');
        });
    }

    public function evaluateEvidence(HistoricalEvidence $evidence): array
    {
        return $this->decide($evidence);
    }

    private function decide(HistoricalEvidence $evidence): array
    {
        $snapshot = $evidence->evidence_snapshot ?? [];
        $rawSku = trim((string) ($snapshot['source_sku_raw'] ?? ''));
        $tokens = $this->resolveTokens($rawSku);
        $quantity = trim((string) ($snapshot['source_quantity_raw'] ?? ''));
        $date = $this->parseDate($snapshot['source_input_time_raw'] ?? null);
        $reason = null;
        if ($tokens === []) $reason = 'sku_unmatched_or_invalid';
        elseif ($date === null) $reason = 'order_date_unparseable';
        elseif ($date > self::HISTORICAL_AS_OF) $reason = 'order_date_future_requires_review';
        elseif (count($tokens) === 1 && ! preg_match('/^[1-9][0-9]*$/', $quantity)) $reason = 'quantity_not_positive_integer';
        elseif (count($tokens) > 1 && ! $this->validMultiQuantity($quantity, count($tokens))) $reason = 'ambiguous_multi_item_quantity';
        elseif (count(array_unique(array_map(fn ($t) => (int) $t['product_id'], $tokens))) !== count($tokens)) $reason = 'duplicate_product_token';
        if ($reason !== null) return ['decision' => 'review', 'reason' => $reason, 'manifest' => ['source_sku_raw' => $rawSku, 'source_quantity_raw' => $quantity, 'tokens' => $tokens]];
        $quantities = count($tokens) === 1 ? [(int) $quantity] : array_fill(0, count($tokens), 1);
        $items = array_map(fn (array $token, int $itemQuantity): array => [
            'product_id' => $token['product_id'], 'sku' => $token['canonical_sku'],
            'product_summary' => $token['product_summary'], 'quantity' => $itemQuantity, 'unit_price' => '0.00',
        ], $tokens, $quantities);
        return ['decision' => 'ready', 'reason' => 'deterministic_product_quantity_and_date', 'manifest' => ['kol_id' => (int) $evidence->kol_id, 'order_date' => $date, 'items' => $items, 'tokens' => $tokens]];
    }

    private function resolveTokens(string $raw): array
    {
        if ($raw === '') return [];

        $whole = $this->skuResolver->resolve($raw);
        if ($whole->isResolved()) {
            $product = $whole->product;
            return [[
                'product_id' => (int) $product->getKey(),
                'canonical_sku' => $whole->canonicalSku,
                'product_summary' => (string) ($product->name ?: $whole->canonicalSku),
                'token' => $raw,
            ]];
        }

        $segments = preg_split('/[\r\n,，、;；]+/u', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $known = Product::query()->whereNotNull('sku')->get(['sku'])->map(fn ($p) => (string) $p->sku)->all();
        foreach (ProductSkuAlias::query()->get(['alias_sku'])->all() as $alias) $known[] = (string) $alias->alias_sku;
        $known = array_values(array_unique(array_filter(array_map('trim', $known))));
        usort($known, fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        $resolved = [];
        foreach ($segments as $segment) {
            $segment = trim($segment);
            if ($segment === '') continue;
            $found = null;
            foreach ($known as $sku) {
                if (mb_stripos($segment, $sku) !== 0) continue;
                $suffix = trim(mb_substr($segment, mb_strlen($sku)));
                if ($suffix !== '' && preg_match('/^[\p{Han}(（]/u', $suffix) !== 1) continue;
                $match = $this->skuResolver->resolve($sku);
                if (! $match->isResolved()) continue;
                $product = $match->product;
                $found = ['product_id' => (int) $product->getKey(), 'canonical_sku' => $match->canonicalSku, 'product_summary' => (string) ($product->name ?: $match->canonicalSku), 'token' => $sku];
                break;
            }
            if ($found === null) return [];
            $resolved[] = $found;
        }
        return $resolved;
    }

    private function validMultiQuantity(string $quantity, int $count): bool
    {
        $compact = preg_replace('/\s+/u', '', $quantity) ?? $quantity;
        if (preg_match('/^各1个?(?:共([0-9]+))?$/u', $compact, $matches) !== 1) return false;
        return ! isset($matches[1]) || (int) $matches[1] === $count;
    }

    private function parseDate(mixed $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') return null;
        if (ctype_digit($value)) return CarbonImmutable::create(1899, 12, 30)->addDays((int) $value)->toDateString();
        try { return CarbonImmutable::parse($value)->toDateString(); } catch (\Throwable) { return null; }
    }


    /** @param array<string,bool> $reserved */
    private function reserveErpOrderId(int $kolId, string $orderDate, array &$reserved): ?string
    {
        $suffixes = array_merge([null], range('A', 'Z'));
        foreach ($suffixes as $suffix) {
            $candidate = $this->orderIdentities->previewCandidate('k', $kolId, $orderDate, $suffix);
            if (isset($reserved[$candidate])) continue;
            if (Order::query()->where('erp_order_id', $candidate)->exists()) continue;
            $reserved[$candidate] = true;
            return $candidate;
        }

        return null;
    }

    private function connectionKey(string $checksum, int $batchId): string
    { return 'historical:'.$batchId.':'.substr($checksum, 0, 32); }

    private function address(Kol $kol): array
    {
        $address = ['recipient_name' => $kol->shipping_recipient_name, 'phone' => $kol->contact_phone, 'country' => $kol->shipping_country, 'province_state' => $kol->shipping_province_state, 'city' => $kol->shipping_city, 'address_line_1' => $kol->shipping_address_line_1, 'address_line_2' => $kol->shipping_address_line_2, 'postal_code' => $kol->shipping_postal_code];
        return count(array_filter(array_intersect_key($address, array_flip(['recipient_name', 'phone', 'country', 'province_state', 'city', 'address_line_1'])))) === 6 ? $address : [];
    }

    private function authorize(Authenticatable $actor, string $permission): void { if (! $actor->can($permission)) abort(403); }
}
