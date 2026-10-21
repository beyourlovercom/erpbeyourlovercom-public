<?php

namespace App\Modules\Finance\Application;

use App\Modules\Audit\Application\AuditActor;
use App\Modules\Audit\Application\AuditSource;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Kol\Application\Contracts\AffiliateCommissionEvidenceReader;
use App\Modules\Inventory\Application\Contracts\WarehouseCostAllocationEvidenceReader;
use App\Modules\Inventory\Application\Contracts\P9WarehouseStorageCostEvidenceReader;
use App\Modules\Logistics\Application\Contracts\CarrierBillingEvidenceReader;
use App\Modules\Logistics\Application\Contracts\FulfillmentOriginEvidenceReader;
use App\Modules\Logistics\Application\Contracts\LastMileEstimateEvidenceReader;
use App\Modules\Logistics\Application\Contracts\OverseasHandlingEvidenceReader;
use App\Modules\Products\Application\Contracts\ProductCostEvidenceReader;
use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

final class CostMaterializationService
{
    private const STRONG_PROVENANCE = ['actual', 'confirmed_manual', 'provider_import', 'allocated_actual'];
    private const OVERSEAS_WAREHOUSE_SLUGS = ['us-la', 'us-ny', 'de', 'ca', 'us-3sm'];
    private const CHINA_DIRECT_WAREHOUSE_SLUGS = ['factory', '007'];
    private const PAYMENT_FEE_RATE = '0.03500000';

    public function __construct(
        private readonly ProductCostEvidenceReader $products,
        private readonly CarrierBillingEvidenceReader $carrier,
        private readonly LastMileEstimateEvidenceReader $lastMileEstimates,
        private readonly LastMileHistoricalProjectionService $p82LastMileProjection,
        private readonly OrderMaturityService $orderMaturity,
        private readonly FulfillmentOriginEvidenceReader $fulfillmentOrigin,
        private readonly AffiliateCommissionEvidenceReader $affiliate,
        private readonly WarehouseCostAllocationEvidenceReader $warehouseCosts,
        private readonly P9WarehouseStorageCostEvidenceReader $p9WarehouseCosts,
        private readonly AccountingFxSnapshotBridgeService $fxBridge,
        private readonly OrderAnalysisService $analysis,
        private readonly AuditRecorder $audit,
    ) {}

    /** @return array<string, mixed> */
    public function preview(Model $order): array
    {
        $scopeBlocker = $this->operationalBlocker($order);
        $candidates = [
            $this->productCandidate($order),
            $this->carrierCandidate($order),
        ];
        $replacementLoss = $this->replacementLossCandidate($order);
        if ($replacementLoss !== null) $candidates[] = $replacementLoss;
        if ($this->multiShipmentAggregationEnabled() || $this->overseasHandlingAvailable()) {
            $candidates[] = $this->overseasHandlingCandidate($order);
        }
        array_push(
            $candidates,
            $this->affiliateCandidate($order),
            $this->firstMileCandidate($order),
            $this->customsCandidate($order),
            $this->paymentFeeCandidate($order),
        );
        if ($scopeBlocker !== null) {
            $candidates = array_map(fn (array $candidate): array => $this->forceBlocked($candidate, $scopeBlocker), $candidates);
        }

        return [
            'order_id' => (int) $order->getKey(),
            'scope_status' => $scopeBlocker === null ? 'operational' : 'blocked',
            'scope_blocker' => $scopeBlocker,
            'candidates' => array_values(array_map(fn (array $candidate): array => $this->annotateDisposition($order, $candidate), $candidates)),
            'unsupported' => $replacementLoss === null ? ['refund_replacement_loss', 'other_variable_cost'] : ['other_variable_cost'],
        ];
    }

    /** @return array<string, mixed> */
    public function previewExplanation(Model $order): array
    {
        return $this->withMultiShipmentAggregation(function () use ($order): array {
            $scopeBlocker = $this->operationalBlocker($order);
            $candidates = [
                $this->productCandidate($order),
                $this->warehouseCostCandidate($order),
                $this->firstMileCandidate($order),
                $this->overseasHandlingCandidate($order),
                $this->carrierCandidate($order),
                $this->paymentFeeCandidate($order),
                $this->affiliateCandidate($order),
                $this->customsCandidate($order),
            ];
            $replacementLoss = $this->replacementLossCandidate($order);
            if ($replacementLoss !== null) $candidates[] = $replacementLoss;
            if ($scopeBlocker !== null) {
                $candidates = array_map(fn (array $candidate): array => $this->forceBlocked($candidate, $scopeBlocker), $candidates);
            }
            return [
                'order_id' => (int) $order->getKey(),
                'scope_status' => $scopeBlocker === null ? 'operational' : 'blocked',
                'scope_blocker' => $scopeBlocker,
                'candidates' => array_values(array_map(fn (array $candidate): array => $this->annotateDisposition($order, $candidate), $candidates)),
            ];
        });
    }

    /** @return array<string, mixed> */
    public function previewMultiShipmentGate(Model $order): array
    {
        return $this->withMultiShipmentAggregation(function () use ($order): array {
            $scopeBlocker = $this->operationalBlocker($order);
            $candidates = [
                $this->firstMileCandidate($order),
                $this->overseasHandlingCandidate($order),
                $this->carrierCandidate($order),
            ];
            if ($scopeBlocker !== null) {
                $candidates = array_map(fn (array $candidate): array => $this->forceBlocked($candidate, $scopeBlocker), $candidates);
            }
            $annotated = array_values(array_map(
                fn (array $candidate): array => $this->annotateMultiShipmentDelta($order, $this->annotateDisposition($order, $candidate)),
                $candidates,
            ));
            $shipmentCount = 0;
            foreach ($annotated as $candidate) $shipmentCount = max($shipmentCount, (int) ($candidate['shipment_count'] ?? 0));
            return [
                'order_id' => (int) $order->getKey(),
                'scope_status' => $scopeBlocker === null ? 'operational' : 'blocked',
                'scope_blocker' => $scopeBlocker,
                'shipment_count' => $shipmentCount,
                'candidates' => $annotated,
            ];
        });
    }

    /** @return array<string, mixed> */
    public function applyMultiShipmentGate(Model $order): array
    {
        return DB::transaction(function () use ($order): array {
            $locked = $order->newQuery()->lockForUpdate()->findOrFail($order->getKey());
            $preview = $this->previewMultiShipmentGate($locked);
            $result = $preview + ['applied' => [], 'noop' => [], 'blocked' => [], 'review' => []];
            foreach ($preview['candidates'] as $candidate) {
                $disposition = (string) ($candidate['materialization_status'] ?? 'blocked');
                if ($disposition === 'noop') { $result['noop'][] = $candidate; continue; }
                if ($disposition === 'blocked') {
                    if (($candidate['blocker'] ?? null) === 'stronger_or_different_existing_fact') $result['review'][] = $candidate;
                    else $result['blocked'][] = $candidate;
                    continue;
                }
                $outcome = $this->writeCandidate($locked, $candidate);
                $result[$outcome['status']][] = $outcome['entry'];
            }
            if ($result['applied'] !== []) {
                $this->analysis->refreshCompleteness($locked, preserveReconciled: false);
                $this->audit->record(
                    AuditActor::system(AuditSource::InternalSystem),
                    'finance.multi_shipment_cost_aggregation.applied',
                    $locked::class,
                    $locked->getKey(),
                    after: ['applied' => $result['applied'], 'blocked' => $result['blocked'], 'review' => $result['review']],
                    reason: 'multi_shipment_cost_aggregation',
                );
            }
            return $result;
        });
    }

    /** @return array<string,mixed> */
    public function previewReplacementLossGate(Model $order): array
    {
        $scopeBlocker = $this->operationalBlocker($order);
        $candidate = $this->replacementLossCandidate($order);
        if ($candidate === null) {
            return [
                'order_id' => (int) $order->getKey(),
                'scope_status' => $scopeBlocker === null ? 'operational' : 'blocked',
                'scope_blocker' => $scopeBlocker,
                'replacement_count' => 0,
                'candidates' => [],
            ];
        }
        if ($scopeBlocker !== null) $candidate = $this->forceBlocked($candidate, $scopeBlocker);
        return [
            'order_id' => (int) $order->getKey(),
            'scope_status' => $scopeBlocker === null ? 'operational' : 'blocked',
            'scope_blocker' => $scopeBlocker,
            'replacement_count' => (int) ($candidate['replacement_count'] ?? 0),
            'candidates' => [$this->annotateDisposition($order, $candidate)],
        ];
    }

    /** @return array<string,mixed> */
    public function applyReplacementLossGate(Model $order): array
    {
        return DB::transaction(function () use ($order): array {
            $locked = $order->newQuery()->lockForUpdate()->findOrFail($order->getKey());
            $preview = $this->previewReplacementLossGate($locked);
            $result = $preview + ['applied' => [], 'noop' => [], 'blocked' => [], 'review' => []];
            if ($preview['candidates'] === []) return $result;
            $candidate = $preview['candidates'][0];
            $disposition = (string) ($candidate['materialization_status'] ?? 'blocked');
            if ($disposition === 'noop') $result['noop'][] = $candidate;
            elseif ($disposition === 'blocked') {
                if (($candidate['blocker'] ?? null) === 'stronger_or_different_existing_fact') $result['review'][] = $candidate;
                else $result['blocked'][] = $candidate;
            } else {
                $outcome = $this->writeCandidate($locked, $candidate);
                $result[$outcome['status']][] = $outcome['entry'];
            }
            if ($result['applied'] !== []) {
                $this->analysis->refreshCompleteness($locked, preserveReconciled: false);
                $this->audit->record(
                    AuditActor::system(AuditSource::InternalSystem),
                    'finance.replacement_loss.applied',
                    $locked::class,
                    $locked->getKey(),
                    after: ['applied' => $result['applied'], 'blocked' => $result['blocked'], 'review' => $result['review']],
                    reason: 'replacement_fulfillment_incremental_loss',
                );
            }
            return $result;
        });
    }

    /** @return array<string, mixed> */
    public function previewPaymentFeeGate(Model $order): array
    {
        $scopeBlocker = $this->operationalBlocker($order);
        $candidate = $this->paymentFeeCandidate($order);
        if ($scopeBlocker !== null) {
            $candidate = $this->forceBlocked($candidate, $scopeBlocker);
        }

        return [
            'order_id' => (int) $order->getKey(),
            'scope_status' => $scopeBlocker === null ? 'operational' : 'blocked',
            'scope_blocker' => $scopeBlocker,
            'candidates' => [$this->annotateDisposition($order, $candidate)],
        ];
    }

    /** @return array<string, mixed> */
    public function applyPaymentFeeGate(Model $order): array
    {
        return DB::transaction(function () use ($order): array {
            $locked = $order->newQuery()->lockForUpdate()->findOrFail($order->getKey());
            $preview = $this->previewPaymentFeeGate($locked);
            $result = $preview + ['applied' => [], 'noop' => [], 'blocked' => []];
            $candidate = $preview['candidates'][0];
            $disposition = $candidate['materialization_status'];
            if ($disposition === 'blocked') {
                $result['blocked'][] = $candidate;
            } elseif ($disposition === 'noop') {
                $result['noop'][] = $candidate;
            } else {
                $outcome = $this->writeCandidate($locked, $candidate);
                $result[$outcome['status']][] = $outcome['entry'];
            }

            if ($result['applied'] !== []) {
                $this->analysis->refreshCompleteness($locked, preserveReconciled: false);
                $this->audit->record(
                    AuditActor::system(AuditSource::InternalSystem),
                    'finance.payment_fee_materialization.applied',
                    $locked::class,
                    $locked->getKey(),
                    after: ['applied' => $result['applied'], 'blocked' => $result['blocked']],
                    reason: 'payment_fee_materialization',
                );
            }

            return $result;
        });
    }

    /** @return array<string, mixed> */
    public function previewCarrierBillingGate(Model $order): array
    {
        $scopeBlocker = $this->operationalBlocker($order);
        $candidate = $this->carrierCandidate($order);
        if ($scopeBlocker !== null) $candidate = $this->forceBlocked($candidate, $scopeBlocker);

        return [
            'order_id' => (int) $order->getKey(),
            'scope_status' => $scopeBlocker === null ? 'operational' : 'blocked',
            'scope_blocker' => $scopeBlocker,
            'candidates' => [$this->annotateDisposition($order, $candidate)],
        ];
    }

    /** @return array<string, mixed> */
    public function applyCarrierBillingGate(Model $order): array
    {
        return DB::transaction(function () use ($order): array {
            $locked = $order->newQuery()->lockForUpdate()->findOrFail($order->getKey());
            $preview = $this->previewCarrierBillingGate($locked);
            $result = $preview + ['applied' => [], 'noop' => [], 'blocked' => []];
            $candidate = $preview['candidates'][0];
            $disposition = $candidate['materialization_status'];
            if ($disposition === 'blocked') $result['blocked'][] = $candidate;
            elseif ($disposition === 'noop') $result['noop'][] = $candidate;
            else {
                $outcome = $this->writeCandidate($locked, $candidate);
                $result[$outcome['status']][] = $outcome['entry'];
            }

            if ($result['applied'] !== []) {
                $this->analysis->refreshCompleteness($locked, preserveReconciled: false);
                $this->audit->record(
                    AuditActor::system(AuditSource::InternalSystem),
                    'finance.actual_last_mile_materialization.applied',
                    $locked::class, $locked->getKey(),
                    after: ['applied' => $result['applied'], 'blocked' => $result['blocked']],
                    reason: 'actual_last_mile_materialization',
                );
            }
            return $result;
        });
    }

    /** @return array<string, mixed> */
    public function previewOverseasHandlingGate(Model $order): array
    {
        if (! $this->multiShipmentAggregationEnabled() && ! $this->overseasHandlingAvailable()) {
            return ['order_id'=>(int)$order->getKey(),'scope_status'=>'operational','scope_blocker'=>null,'candidates'=>[]];
        }
        $scopeBlocker = $this->operationalBlocker($order);
        $candidate = $this->overseasHandlingCandidate($order);
        if ($scopeBlocker !== null) $candidate = $this->forceBlocked($candidate, $scopeBlocker);
        return ['order_id'=>(int)$order->getKey(),'scope_status'=>$scopeBlocker===null?'operational':'blocked','scope_blocker'=>$scopeBlocker,'candidates'=>[$this->annotateDisposition($order,$candidate)]];
    }

    /** @return array<string, mixed> */
    public function applyOverseasHandlingGate(Model $order): array
    {
        return DB::transaction(function () use ($order): array {
            $locked=$order->newQuery()->lockForUpdate()->findOrFail($order->getKey());
            $preview=$this->previewOverseasHandlingGate($locked);
            $result=$preview+['applied'=>[],'noop'=>[],'blocked'=>[]];
            if (($preview['candidates'] ?? []) === []) return $result;
            $candidate=$preview['candidates'][0];
            $disposition=$candidate['materialization_status'];
            if($disposition==='blocked') $result['blocked'][]=$candidate; elseif($disposition==='noop') $result['noop'][]=$candidate; else { $outcome=$this->writeCandidate($locked,$candidate); $result[$outcome['status']][]=$outcome['entry']; }
            if($result['applied']!==[]){
                $this->analysis->refreshCompleteness($locked,preserveReconciled:false);
                $this->audit->record(AuditActor::system(AuditSource::InternalSystem),'finance.overseas_handling_materialization.applied',$locked::class,$locked->getKey(),after:['applied'=>$result['applied'],'blocked'=>$result['blocked']],reason:'overseas_handling_materialization');
            }
            return $result;
        });
    }

    /** @return array<string, mixed> */
    public function previewWarehouseCostGate(Model $order): array
    {
        $scopeBlocker = $this->operationalBlocker($order);
        $candidate = $this->warehouseCostCandidate($order);
        if ($scopeBlocker !== null) $candidate = $this->forceBlocked($candidate, $scopeBlocker);

        return [
            'order_id' => (int) $order->getKey(),
            'scope_status' => $scopeBlocker === null ? 'operational' : 'blocked',
            'scope_blocker' => $scopeBlocker,
            'candidates' => [$this->annotateDisposition($order, $candidate)],
        ];
    }

    /** @return array<string, mixed> */
    public function applyWarehouseCostGate(Model $order): array
    {
        return DB::transaction(function () use ($order): array {
            $locked = $order->newQuery()->lockForUpdate()->findOrFail($order->getKey());
            $preview = $this->previewWarehouseCostGate($locked);
            $result = $preview + ['applied' => [], 'noop' => [], 'blocked' => []];
            $candidate = $preview['candidates'][0];
            $disposition = $candidate['materialization_status'];
            if ($disposition === 'blocked') $result['blocked'][] = $candidate;
            elseif ($disposition === 'noop') $result['noop'][] = $candidate;
            else {
                $outcome = $this->writeCandidate($locked, $candidate);
                $result[$outcome['status']][] = $outcome['entry'];
            }

            if ($result['applied'] !== []) {
                $this->analysis->refreshCompleteness($locked, preserveReconciled: false);
                $this->audit->record(
                    AuditActor::system(AuditSource::InternalSystem),
                    'finance.warehouse_cost_materialization.applied',
                    $locked::class, $locked->getKey(),
                    after: ['applied' => $result['applied'], 'blocked' => $result['blocked']],
                    reason: 'warehouse_cost_materialization',
                );
            }
            return $result;
        });
    }

    /** @return array<string, mixed> */
    /** @return array<string, mixed> */
    public function previewKolCommissionGate(Model $order): array
    {
        $scopeBlocker = $this->operationalBlocker($order);
        $candidate = $this->affiliateCandidate($order);
        if ($scopeBlocker !== null) $candidate = $this->forceBlocked($candidate, $scopeBlocker);

        return [
            'order_id' => (int) $order->getKey(),
            'scope_status' => $scopeBlocker === null ? 'operational' : 'blocked',
            'scope_blocker' => $scopeBlocker,
            'candidates' => [$this->annotateDisposition($order, $candidate)],
        ];
    }

    /** @return array<string, mixed> */
    public function applyKolCommissionGate(Model $order): array
    {
        return DB::transaction(function () use ($order): array {
            $locked = $order->newQuery()->lockForUpdate()->findOrFail($order->getKey());
            $preview = $this->previewKolCommissionGate($locked);
            $result = $preview + ['applied' => [], 'noop' => [], 'blocked' => []];
            $candidate = $preview['candidates'][0];
            $disposition = $candidate['materialization_status'];
            if ($disposition === 'blocked') $result['blocked'][] = $candidate;
            elseif ($disposition === 'noop') $result['noop'][] = $candidate;
            else {
                $outcome = $this->writeCandidate($locked, $candidate);
                $result[$outcome['status']][] = $outcome['entry'];
            }

            if ($result['applied'] !== []) {
                $this->analysis->refreshCompleteness($locked, preserveReconciled: false);
                $this->audit->record(
                    AuditActor::system(AuditSource::InternalSystem),
                    'finance.kol_commission_materialization.applied',
                    $locked::class, $locked->getKey(),
                    after: ['applied' => $result['applied'], 'blocked' => $result['blocked']],
                    reason: 'kol_commission_materialization',
                );
            }
            return $result;
        });
    }

    public function previewLastMileEstimateGate(Model $order): array
    {
        $scopeBlocker = $this->operationalBlocker($order);
        $candidate = $this->lastMileEstimateCandidate($order);
        if ($scopeBlocker !== null) $candidate = $this->forceBlocked($candidate, $scopeBlocker);

        return [
            'order_id' => (int) $order->getKey(),
            'scope_status' => $scopeBlocker === null ? 'operational' : 'blocked',
            'scope_blocker' => $scopeBlocker,
            'candidates' => [$this->annotateDisposition($order, $candidate)],
        ];
    }

    /** @return array<string, mixed> */
    public function applyLastMileEstimateGate(Model $order): array
    {
        return DB::transaction(function () use ($order): array {
            $locked = $order->newQuery()->lockForUpdate()->findOrFail($order->getKey());
            $preview = $this->previewLastMileEstimateGate($locked);
            $result = $preview + ['applied' => [], 'noop' => [], 'blocked' => []];
            $candidate = $preview['candidates'][0];
            $disposition = $candidate['materialization_status'];
            if ($disposition === 'blocked') $result['blocked'][] = $candidate;
            elseif ($disposition === 'noop') $result['noop'][] = $candidate;
            else {
                $outcome = $this->writeCandidate($locked, $candidate);
                $result[$outcome['status']][] = $outcome['entry'];
            }

            if ($result['applied'] !== []) {
                $this->analysis->refreshCompleteness($locked, preserveReconciled: false);
                $this->audit->record(
                    AuditActor::system(AuditSource::InternalSystem),
                    'finance.last_mile_estimate_materialization.applied',
                    $locked::class, $locked->getKey(),
                    after: ['applied' => $result['applied'], 'blocked' => $result['blocked']],
                    reason: 'last_mile_estimate_materialization',
                );
            }
            return $result;
        });
    }

    /** @return array<string, mixed> */
    public function previewFixedRules(Model $order): array
    {
        $scopeBlocker = $this->operationalBlocker($order);
        $candidates = [$this->firstMileCandidate($order), $this->customsCandidate($order)];
        if ($scopeBlocker !== null) {
            $candidates = array_map(fn (array $candidate): array => $this->forceBlocked($candidate, $scopeBlocker), $candidates);
        }

        return [
            'order_id' => (int) $order->getKey(),
            'scope_status' => $scopeBlocker === null ? 'operational' : 'blocked',
            'scope_blocker' => $scopeBlocker,
            'candidates' => array_values(array_map(fn (array $candidate): array => $this->annotateDisposition($order, $candidate), $candidates)),
        ];
    }

    /** @return array<string, mixed> */
    public function previewProductCostGate(Model $order): array
    {
        $scopeBlocker = $this->operationalBlocker($order);
        $product = $this->productCandidate($order);
        if (($product['status'] ?? null) !== 'blocked'
            && (($product['provenance'] ?? null) !== 'confirmed_manual'
                || ! str_starts_with((string) ($product['source_reference'] ?? ''), 'product-cost-source:v1:'))) {
            $product = $this->blocked('product_cost', 'confirmed_product_cost_required_for_p2_gate');
        }
        $candidates = [$product, $this->firstMileCandidate($order)];
        if ($scopeBlocker !== null) {
            $candidates = array_map(fn (array $candidate): array => $this->forceBlocked($candidate, $scopeBlocker), $candidates);
        }

        return [
            'order_id' => (int) $order->getKey(),
            'scope_status' => $scopeBlocker === null ? 'operational' : 'blocked',
            'scope_blocker' => $scopeBlocker,
            'candidates' => array_values(array_map(fn (array $candidate): array => $this->annotateDisposition($order, $candidate), $candidates)),
        ];
    }

    /** @return array<string, mixed> */
    public function applyProductCostGate(Model $order): array
    {
        return DB::transaction(function () use ($order): array {
            $locked = $order->newQuery()->lockForUpdate()->findOrFail($order->getKey());
            $preview = $this->previewProductCostGate($locked);
            $result = $preview + ['applied' => [], 'noop' => [], 'blocked' => []];

            foreach ($preview['candidates'] as $candidate) {
                $disposition = $candidate['materialization_status'];
                if ($disposition === 'blocked') {
                    $result['blocked'][] = $candidate;
                    continue;
                }
                if ($disposition === 'noop') {
                    $result['noop'][] = $candidate;
                    continue;
                }
                $outcome = $this->writeCandidate($locked, $candidate);
                $result[$outcome['status']][] = $outcome['entry'];
            }

            if ($result['applied'] !== []) {
                $this->analysis->refreshCompleteness($locked, preserveReconciled: false);
                $this->audit->record(
                    AuditActor::system(AuditSource::InternalSystem),
                    'finance.product_cost_materialization.applied',
                    $locked::class,
                    $locked->getKey(),
                    after: ['applied' => $result['applied'], 'blocked' => $result['blocked']],
                    reason: 'product_cost_materialization',
                );
            }

            return $result;
        });
    }

    /** @return array<string, mixed> */
    public function applyFixedRules(Model $order): array
    {
        return DB::transaction(function () use ($order): array {
            $locked = $order->newQuery()->lockForUpdate()->findOrFail($order->getKey());
            $preview = $this->previewFixedRules($locked);
            $result = $preview + ['applied' => [], 'noop' => [], 'blocked' => []];

            foreach ($preview['candidates'] as $candidate) {
                $disposition = $candidate['materialization_status'];
                if ($disposition === 'blocked') {
                    $result['blocked'][] = $candidate;
                    continue;
                }
                if ($disposition === 'noop') {
                    $result['noop'][] = $candidate;
                    continue;
                }
                $outcome = $this->writeCandidate($locked, $candidate);
                $result[$outcome['status']][] = $outcome['entry'];
            }

            if ($result['applied'] !== []) {
                $this->analysis->refreshCompleteness($locked, preserveReconciled: false);
                $this->audit->record(
                    AuditActor::system(AuditSource::InternalSystem),
                    'finance.fixed_cost_materialization.applied',
                    $locked::class,
                    $locked->getKey(),
                    after: ['applied' => $result['applied'], 'blocked' => $result['blocked']],
                    reason: 'fixed_cost_materialization',
                );
            }

            return $result;
        });
    }

    /** @return array<string, mixed> */
    public function apply(Model $order): array
    {
        return DB::transaction(function () use ($order): array {
            $locked = $order->newQuery()->lockForUpdate()->findOrFail($order->getKey());
            $preview = $this->preview($locked);
            $result = $preview + ['applied' => [], 'noop' => [], 'blocked' => []];

            foreach ($preview['candidates'] as $candidate) {
                $disposition = $candidate['materialization_status'];
                if ($disposition === 'blocked') {
                    $result['blocked'][] = $candidate;
                    continue;
                }
                if ($disposition === 'noop') {
                    $result['noop'][] = $candidate;
                    continue;
                }
                $outcome = $this->writeCandidate($locked, $candidate);
                $result[$outcome['status']][] = $outcome['entry'];
            }

            if ($result['applied'] !== []) {
                $this->analysis->refreshCompleteness($locked, preserveReconciled: false);
                $this->audit->record(
                    AuditActor::system(AuditSource::InternalSystem),
                    'finance.cost_materialization.applied',
                    $locked::class,
                    $locked->getKey(),
                    after: ['applied' => $result['applied'], 'blocked' => $result['blocked']],
                    reason: 'cost_materialization',
                );
            }

            return $result;
        });
    }

    private function productCandidate(Model $order): array
    {
        $rows = $this->products->read($order->getKey());
        $currency = $this->orderCurrency($order);
        if ($rows === []) {
            return $this->blocked('product_cost', 'order_items_missing');
        }

        $confirmedPresence = array_filter($rows, fn (array $row): bool => ($row['confirmed_cost_sources'] ?? []) !== []);
        if ($confirmedPresence !== []) {
            if (count($confirmedPresence) !== count($rows)) {
                return $this->blocked('product_cost', 'confirmed_product_cost_source_incomplete');
            }

            $totalByCurrency = [];
            $sourceIds = [];
            $sourceKeys = [];
            $lineBreakdown = [];
            $sourceAt = null;
            foreach ($rows as $row) {
                $quantity = (string) ($row['quantity'] ?? '');
                if (preg_match('/^[1-9][0-9]*$/D', $quantity) !== 1 || ($row['product_id'] ?? null) === null) {
                    return $this->blocked('product_cost', 'confirmed_product_cost_item_identity_invalid', ['item_id' => $row['item_id'] ?? null]);
                }
                $sources = $row['confirmed_cost_sources'] ?? [];
                $distinct = [];
                foreach ($sources as $source) {
                    $key = strtoupper((string) ($source['currency'] ?? '')).'|'.(string) ($source['unit_cost'] ?? '');
                    $distinct[$key] = $source;
                }
                if (count($distinct) !== 1) {
                    return $this->blocked('product_cost', 'confirmed_product_cost_source_ambiguous', ['item_id' => $row['item_id'] ?? null]);
                }
                $source = array_values($distinct)[0];
                $sourceCurrency = strtoupper((string) $source['currency']);
                $line = DecimalMoney::multiply((string) $source['unit_cost'], $quantity, 8);
                $totalByCurrency[$sourceCurrency] = DecimalMoney::add($totalByCurrency[$sourceCurrency] ?? '0', $line, 8);
                $lineBreakdown[] = [
                    'item_id' => $row['item_id'] ?? null, 'product_id' => $row['product_id'] ?? null,
                    'sku' => $row['sku'] ?? null, 'product_name' => $row['product_name'] ?? null,
                    'quantity' => (int) $quantity, 'unit_cost' => DecimalMoney::round((string) $source['unit_cost']),
                    'currency' => $sourceCurrency, 'subtotal' => DecimalMoney::round($line),
                    'source_key' => $source['source_key'] ?? null, 'source_reference' => $source['source_reference'] ?? null,
                    'provenance' => $source['provenance'] ?? null, 'effective_from' => $source['effective_from'] ?? null,
                    'source_at' => $source['source_at'] ?? null,
                ];
                foreach ($sources as $evidence) {
                    $sourceIds[] = (int) $evidence['id'];
                    $sourceKeys[] = (string) $evidence['source_key'];
                    $candidateSourceAt = $evidence['source_at'] ?? $evidence['confirmed_at'] ?? null;
                    if ($candidateSourceAt !== null && ($sourceAt === null || $candidateSourceAt > $sourceAt)) {
                        $sourceAt = $candidateSourceAt;
                    }
                }
            }
            if (count($totalByCurrency) !== 1) {
                return $this->blocked('product_cost', 'confirmed_product_cost_mixed_source_currency', ['source_currencies' => array_keys($totalByCurrency)]);
            }
            $sourceCurrency = array_key_first($totalByCurrency);
            sort($sourceIds);
            sort($sourceKeys);
            $sourceAmount = DecimalMoney::round($totalByCurrency[$sourceCurrency]);
            $extra = ['source_ids' => array_values(array_unique($sourceIds)), 'source_keys' => array_values(array_unique($sourceKeys)), 'line_breakdown' => $lineBreakdown];
            $amount = $sourceAmount;
            if ($sourceCurrency !== $currency) {
                $fx = $this->fxBridge->preview($order, $sourceCurrency);
                if (! ($fx['available'] ?? false)) {
                    return $this->blocked('product_cost', 'confirmed_product_cost_fx_snapshot_unavailable', $extra + [
                        'source_currency' => $sourceCurrency,
                        'order_currency' => $currency,
                        'source_amount' => $sourceAmount,
                        'fx_reason' => $fx['reason'] ?? 'fx_unavailable',
                    ]);
                }
                $amount = DecimalMoney::round(DecimalMoney::multiply($sourceAmount, (string) $fx['source_to_transaction_rate'], 8));
                $extra += [
                    'source_currency' => $sourceCurrency,
                    'source_amount' => $sourceAmount,
                    'posting_fx' => $fx + [
                        'base_amount' => DecimalMoney::round(DecimalMoney::multiply($sourceAmount, (string) $fx['source_to_usd_rate'], 8)),
                    ],
                ];
            }

            return $this->candidate(
                'product_cost', $amount, $currency, 'confirmed_manual', 'confirmed',
                'product-cost-source:v1:order:'.$order->getKey().':'.hash('sha256', implode(',', $sourceIds)), $sourceAt, $extra,
            );
        }

        $total = '0';
        $productIds = [];
        $lineBreakdown = [];
        $sourceAt = null;
        foreach ($rows as $row) {
            $quantity = (string) ($row['quantity'] ?? '');
            $purchase = $row['standard_purchase_cost'] ?? null;
            $production = $row['standard_production_cost'] ?? null;
            $packaging = $row['standard_packaging_cost'] ?? null;
            $validQuantity = preg_match('/^[1-9][0-9]*$/D', $quantity) === 1;
            $purchasePath = $purchase !== null && $production === null && $packaging === null;
            $productionPath = $purchase === null && $production !== null;
            if (! $validQuantity || ($row['product_id'] ?? null) === null || strtoupper((string) ($row['cost_currency'] ?? '')) !== $currency || (! $purchasePath && ! $productionPath)) {
                return $this->blocked('product_cost', 'unambiguous_same_currency_reference_required', ['item_id' => $row['item_id'] ?? null]);
            }

            $unit = $purchasePath ? (string) $purchase : DecimalMoney::add((string) $production, (string) ($packaging ?? '0'), 8);
            $line = DecimalMoney::multiply($unit, $quantity, 8);
            $total = DecimalMoney::add($total, $line, 8);
            $lineBreakdown[] = [
                'item_id' => $row['item_id'] ?? null, 'product_id' => $row['product_id'] ?? null,
                'sku' => $row['sku'] ?? null, 'product_name' => $row['product_name'] ?? null,
                'quantity' => (int) $quantity, 'unit_cost' => DecimalMoney::round($unit),
                'currency' => $currency, 'subtotal' => DecimalMoney::round($line),
                'source_key' => null, 'source_reference' => 'product-reference',
                'provenance' => 'estimated_reference', 'effective_from' => null,
                'source_at' => $row['updated_at'] ?? null,
            ];
            $productIds[] = (int) $row['product_id'];
            if (($row['updated_at'] ?? null) !== null && ($sourceAt === null || $row['updated_at'] > $sourceAt)) {
                $sourceAt = $row['updated_at'];
            }
        }
        sort($productIds);

        return $this->candidate(
            'product_cost', DecimalMoney::round($total), $currency, 'estimated_reference', 'estimated',
            'product-reference:v1:order:'.$order->getKey(), $sourceAt, ['product_ids' => $productIds, 'line_breakdown' => $lineBreakdown],
        );
    }

    private function lastMileEstimateCandidate(Model $order): array
    {
        $actual = $this->carrier->read($order->getKey());
        if (is_array($actual) && ! isset($actual['blocked'])) {
            if (isset($actual['shipments'])) {
                $legs = $actual['shipments'];
                $complete = $legs !== [];
                foreach ($legs as $leg) {
                    $billing = $leg['billing'] ?? null;
                    $identity = is_array($billing) ? ($billing['billing_document_number'] ?? $billing['provider_reference'] ?? $billing['data_source'] ?? null) : null;
                    if (! is_array($billing) || ($billing['actual_cost'] ?? null) === null || blank($billing['currency'] ?? null) || ! is_string($identity) || trim($identity) === '') { $complete = false; break; }
                }
                if ($complete) return $this->blocked('last_mile_cost', 'actual_carrier_billing_available');
            } else {
                $identity = $actual['billing_document_number'] ?? $actual['provider_reference'] ?? $actual['data_source'] ?? null;
                if (($actual['actual_cost'] ?? null) !== null && ($actual['currency'] ?? null) !== null && is_string($identity) && trim($identity) !== '') {
                    return $this->blocked('last_mile_cost', 'actual_carrier_billing_available');
                }
            }
        }

        $evidence = $this->lastMileEstimates->read($order->getKey());
        if ($evidence === null || isset($evidence['blocked'])) {
            if ($this->p82EstimatedFallbackEnabled()) {
                $maturity = $this->orderMaturity->forOrder($order);
                $ageDays = $maturity['age_days'] ?? null;
                if ($ageDays === null) {
                    return $this->blocked('last_mile_cost', 'p82_automatic_estimate_order_date_missing', [
                        'p82_maturity_state' => $maturity['maturity_state'] ?? null,
                        'p82_reconciliation_due' => (bool) ($maturity['reconciliation_due'] ?? false),
                    ]);
                }
                if (! ($maturity['estimate_allowed'] ?? false)) {
                    return $this->blocked('last_mile_cost', 'p82_automatic_estimate_age_blocked', [
                        'p82_age_days' => (int) $ageDays,
                        'p82_maturity_state' => $maturity['maturity_state'] ?? null,
                        'p82_reconciliation_due' => (bool) ($maturity['reconciliation_due'] ?? true),
                    ]);
                }
                return $this->p82LastMileEstimateCandidate($order);
            }
            return $this->blocked('last_mile_cost', (string) ($evidence['blocked'] ?? 'last_mile_estimate_missing'));
        }
        $sourceAmount = DecimalMoney::round((string) $evidence['amount']);
        $sourceCurrency = strtoupper((string) ($evidence['currency'] ?? ''));
        if ($sourceCurrency === '') return $this->blocked('last_mile_cost', 'last_mile_estimate_currency_missing');

        $currency = $this->orderCurrency($order);
        $amount = $sourceAmount;
        $extra = [
            'source_currency' => $sourceCurrency,
            'source_amount' => $sourceAmount,
            'selected_source' => $evidence['selected_source'] ?? null,
        ];
        if ($sourceCurrency !== $currency) {
            $fx = $this->fxBridge->preview($order, $sourceCurrency);
            if (! ($fx['available'] ?? false)) {
                return $this->blocked('last_mile_cost', 'last_mile_estimate_fx_snapshot_unavailable', $extra + ['fx_reason' => $fx['reason'] ?? 'fx_unavailable']);
            }
            $amount = DecimalMoney::round(DecimalMoney::multiply($sourceAmount, (string) $fx['source_to_transaction_rate'], 8));
            $extra['posting_fx'] = $fx + [
                'base_amount' => DecimalMoney::round(DecimalMoney::multiply($sourceAmount, (string) $fx['source_to_usd_rate'], 8)),
            ];
        }

        return $this->candidate(
            'last_mile_cost', $amount, $currency, 'estimated_reference', 'estimated',
            'last-mile-estimate:v1:order:'.$order->getKey(), $evidence['source_at'] ?? null, $extra,
        );
    }

    private function p82LastMileEstimateCandidate(Model $order): array
    {
        $projection = $this->p82LastMileProjection->project($order);
        $tier = (string) ($projection['tier'] ?? 'unknown');
        $confidence = (string) ($projection['confidence'] ?? 'none');
        $extra = [
            'p82_tier' => $tier,
            'p82_confidence' => $confidence,
            'p82_sample_count' => $projection['sample_count'] ?? null,
            'p82_source_detail' => $projection['source_detail'] ?? [],
        ];

        if (($projection['blocked'] ?? null) !== null) {
            return $this->blocked('last_mile_cost', (string) $projection['blocked'], $extra);
        }
        if ($tier === 'actual_carrier_billing') {
            return $this->blocked('last_mile_cost', 'actual_carrier_billing_available', $extra);
        }
        if ($tier === 'existing_estimate') {
            return $this->blocked('last_mile_cost', 'existing_last_mile_estimate_available', $extra);
        }
        if (! in_array($tier, ['historical_comparable_median', 'fallback_usd10_per_unit'], true)) {
            return $this->blocked('last_mile_cost', 'p82_projection_not_estimated_fallback', $extra);
        }
        if (($projection['amount'] ?? null) === null || blank($projection['currency'] ?? null)) {
            return $this->blocked('last_mile_cost', 'p82_projection_amount_or_currency_missing', $extra);
        }

        $sourceAmount = DecimalMoney::round((string) $projection['amount']);
        $sourceCurrency = strtoupper((string) $projection['currency']);
        $currency = $this->orderCurrency($order);
        $amount = $sourceAmount;
        $extra += ['source_currency' => $sourceCurrency, 'source_amount' => $sourceAmount];

        if ($sourceCurrency !== $currency) {
            $fx = $this->fxBridge->preview($order, $sourceCurrency);
            if (! ($fx['available'] ?? false)) {
                return $this->blocked('last_mile_cost', 'p82_estimate_fx_snapshot_unavailable', $extra + ['fx_reason' => $fx['reason'] ?? 'fx_unavailable']);
            }
            $amount = DecimalMoney::round(DecimalMoney::multiply($sourceAmount, (string) $fx['source_to_transaction_rate'], 8));
            $extra['posting_fx'] = $fx + [
                'base_amount' => DecimalMoney::round(DecimalMoney::multiply($sourceAmount, (string) $fx['source_to_usd_rate'], 8)),
            ];
        }

        return $this->candidate(
            'last_mile_cost', $amount, $currency, 'estimated_reference', 'estimated',
            'p82-last-mile-estimate:v1:order:'.$order->getKey(), $order->updated_at, $extra,
        );
    }

    public function previewP82LastMileEstimateGate(Model $order): array
    {
        $scopeBlocker = $this->operationalBlocker($order);
        $candidate = $this->p82LastMileEstimateCandidate($order);
        if ($scopeBlocker !== null) $candidate = $this->forceBlocked($candidate, $scopeBlocker);
        return [
            'order_id' => (int) $order->getKey(),
            'scope_status' => $scopeBlocker === null ? 'operational' : 'blocked',
            'scope_blocker' => $scopeBlocker,
            'candidates' => [$this->annotateDisposition($order, $candidate)],
        ];
    }

    public function applyP82LastMileEstimateGate(Model $order): array
    {
        return DB::transaction(function () use ($order): array {
            $locked = $order->newQuery()->lockForUpdate()->findOrFail($order->getKey());
            $preview = $this->previewP82LastMileEstimateGate($locked);
            $result = $preview + ['applied' => [], 'noop' => [], 'blocked' => []];
            $candidate = $preview['candidates'][0];
            $disposition = (string) ($candidate['materialization_status'] ?? 'blocked');
            if ($disposition === 'blocked') $result['blocked'][] = $candidate;
            elseif ($disposition === 'noop') $result['noop'][] = $candidate;
            else {
                $outcome = $this->writeCandidate($locked, $candidate);
                $result[$outcome['status']][] = $outcome['entry'];
            }
            if ($result['applied'] !== []) {
                $this->analysis->refreshCompleteness($locked, preserveReconciled: false);
                $this->audit->record(
                    AuditActor::system(AuditSource::InternalSystem),
                    'finance.p8_2_last_mile_estimate_materialization.applied',
                    $locked::class, $locked->getKey(),
                    after: ['applied' => $result['applied'], 'blocked' => $result['blocked']],
                    reason: 'p8_2_estimated_fallback_materialization',
                );
            }
            return $result;
        });
    }

    private function p82EstimatedFallbackEnabled(): bool
    {
        return (bool) config('erp_accounting.continuous_delta.p82_estimated_fallback_enabled', false);
    }

    private function warehouseCostCandidate(Model $order): array
    {
        $legacy = $this->warehouseCosts->read($order->getKey());

        if ((bool) config('warehouse_storage_aging.order_cost_enabled', false)) {
            $canonicalFrom = trim((string) config('warehouse_storage_aging.canonical_from', ''));
            if ($canonicalFrom === '') {
                return $this->blocked('warehouse_cost', 'p9_warehouse_storage_canonical_from_missing');
            }

            $p9 = $this->p9WarehouseCosts->read($order->getKey());
            $legacyReady = $legacy !== null && ! isset($legacy['blocked']);
            if ($p9 !== null && $legacyReady) {
                return $this->blocked('warehouse_cost', 'p7_p9_warehouse_storage_overlap', [
                    'p9_source_reference' => $p9['source_reference'] ?? null,
                    'p7_batch_id' => $legacy['batch_id'] ?? null,
                    'p7_billing_period_end' => $legacy['billing_period_end'] ?? null,
                ]);
            }

            if ($p9 !== null) {
                $currency = $this->orderCurrency($order);
                $sourceCurrency = strtoupper(trim((string) ($p9['currency'] ?? '')));
                if ($sourceCurrency !== 'CNY' || ! isset($p9['source_amount']) || blank($p9['source_reference'] ?? null)) {
                    return $this->blocked('warehouse_cost', 'complete_p9_storage_consumption_evidence_required');
                }

                $sourceAmount = DecimalMoney::round((string) $p9['source_amount']);
                $amount = $sourceAmount;
                $extra = [
                    'source_currency' => $sourceCurrency,
                    'source_amount' => $sourceAmount,
                    'consumed_units' => $p9['consumed_units'] ?? null,
                    'consumption_ids' => $p9['consumption_ids'] ?? [],
                    'shipment_ids' => $p9['shipment_ids'] ?? [],
                    'warehouse_ids' => $p9['warehouse_ids'] ?? [],
                    'allocation_rule' => 'p9_fifo_lot_consumption_v1',
                ];
                if ($sourceCurrency !== $currency) {
                    $fx = $this->fxBridge->preview($order, $sourceCurrency);
                    if (! ($fx['available'] ?? false)) {
                        return $this->blocked('warehouse_cost', 'p9_warehouse_cost_fx_snapshot_unavailable', $extra + ['fx_reason' => $fx['reason'] ?? 'fx_unavailable']);
                    }
                    $amount = DecimalMoney::round(DecimalMoney::multiply($sourceAmount, (string) $fx['source_to_transaction_rate'], 8));
                    $extra['posting_fx'] = $fx + [
                        'base_amount' => DecimalMoney::round(DecimalMoney::multiply($sourceAmount, (string) $fx['source_to_usd_rate'], 8)),
                    ];
                }

                return $this->candidate(
                    'warehouse_cost', $amount, $currency, 'allocated_actual', 'confirmed',
                    (string) $p9['source_reference'], $p9['source_at'] ?? null, $extra,
                );
            }

            if ($legacyReady) {
                $periodEnd = trim((string) ($legacy['billing_period_end'] ?? ''));
                if ($periodEnd !== '' && $periodEnd >= $canonicalFrom) {
                    return $this->blocked('warehouse_cost', 'p7_storage_superseded_by_p9_missing_fifo_evidence', [
                        'canonical_from' => $canonicalFrom,
                        'p7_batch_id' => $legacy['batch_id'] ?? null,
                        'p7_billing_period_end' => $periodEnd,
                    ]);
                }
            }
        }

        $evidence = $legacy;
        if ($evidence === null || isset($evidence['blocked'])) {
            return $this->blocked('warehouse_cost', (string) ($evidence['blocked'] ?? 'warehouse_storage_allocation_missing'));
        }

        $currency = $this->orderCurrency($order);
        $sourceCurrency = strtoupper(trim((string) ($evidence['currency'] ?? '')));
        $batchId = (int) ($evidence['batch_id'] ?? 0);
        if ($batchId < 1 || $sourceCurrency === '' || ! isset($evidence['source_amount'])) {
            return $this->blocked('warehouse_cost', 'complete_warehouse_storage_allocation_required');
        }

        $sourceAmount = DecimalMoney::round((string) $evidence['source_amount']);
        $amount = $sourceAmount;
        $extra = [
            'source_currency' => $sourceCurrency,
            'source_amount' => $sourceAmount,
            'batch_id' => $batchId,
            'warehouse_id' => $evidence['warehouse_id'] ?? null,
            'billing_period_start' => $evidence['billing_period_start'] ?? null,
            'billing_period_end' => $evidence['billing_period_end'] ?? null,
            'outbound_units' => $evidence['outbound_units'] ?? null,
            'allocation_row_ids' => $evidence['row_ids'] ?? [],
            'source_checksum' => $evidence['source_checksum'] ?? null,
            'plan_checksum' => $evidence['plan_checksum'] ?? null,
        ];
        if ($sourceCurrency !== $currency) {
            $fx = $this->fxBridge->preview($order, $sourceCurrency);
            if (! ($fx['available'] ?? false)) {
                return $this->blocked('warehouse_cost', 'warehouse_cost_fx_snapshot_unavailable', $extra + ['fx_reason' => $fx['reason'] ?? 'fx_unavailable']);
            }
            $amount = DecimalMoney::round(DecimalMoney::multiply($sourceAmount, (string) $fx['source_to_transaction_rate'], 8));
            $extra['posting_fx'] = $fx + [
                'base_amount' => DecimalMoney::round(DecimalMoney::multiply($sourceAmount, (string) $fx['source_to_usd_rate'], 8)),
            ];
        }

        return $this->candidate(
            'warehouse_cost', $amount, $currency, 'allocated_actual', 'confirmed',
            'warehouse-storage-allocation:v1:batch:'.$batchId.':order:'.$order->getKey(),
            $evidence['source_at'] ?? null, $extra,
        );
    }

    private function multiShipmentAggregationEnabled(): bool
    {
        return (bool) config('erp_accounting.multi_shipment.enabled', false);
    }

    private function withMultiShipmentAggregation(callable $callback): mixed
    {
        $key = 'erp_accounting.multi_shipment.enabled';
        $original = (bool) config($key, false);
        config([$key => true]);
        try { return $callback(); }
        finally { config([$key => $original]); }
    }

    private function aggregateRuleCandidate(Model $order, string $costType): array
    {
        $evidence = $this->fulfillmentOrigin->read($order->getKey());
        if ($evidence === null || isset($evidence['blocked'])) {
            return $this->blocked($costType, (string) ($evidence['blocked'] ?? 'fulfillment_origin_missing'));
        }
        $currency = $this->orderCurrency($order);
        $sourceAmount = '0'; $breakdown = []; $fingerprint = []; $sourceAt = null;
        foreach ($evidence['shipments'] ?? [] as $leg) {
            $shipmentId = (int) ($leg['shipment_id'] ?? 0);
            $slug = strtolower(trim((string) ($leg['warehouse_slug'] ?? '')));
            $units = (int) ($leg['shipped_units'] ?? 0);
            if ($shipmentId < 1 || $units <= 0) {
                return $this->blocked($costType, 'shipment_item_quantity_missing', ['shipment_id' => $shipmentId ?: null]);
            }
            if (in_array($slug, self::OVERSEAS_WAREHOUSE_SLUGS, true)) {
                $legSource = DecimalMoney::round(DecimalMoney::multiply((string) $units, '1.00', 8));
                $rule = $costType === 'first_mile_cost' ? 'overseas_usd_one_per_shipped_unit' : 'overseas_handling_usd_one_per_shipped_unit';
            } elseif (in_array($slug, self::CHINA_DIRECT_WAREHOUSE_SLUGS, true)) {
                $legSource = '0.00';
                $rule = $costType === 'first_mile_cost' ? 'china_direct_first_mile_zero' : 'china_direct_handling_zero';
            } else {
                return $this->blocked($costType, 'shipment_warehouse_classification_unknown', [
                    'shipment_id' => $shipmentId, 'warehouse_slug' => $slug,
                ]);
            }
            $sourceAmount = DecimalMoney::add($sourceAmount, $legSource, 8);
            $fingerprint[] = implode('|', [$shipmentId, $slug, $units, $rule]);
            $breakdown[] = [
                'shipment_id' => $shipmentId,
                'fulfillment_order_id' => $leg['fulfillment_order_id'] ?? null,
                'fulfillment_suffix' => $leg['fulfillment_suffix'] ?? null,
                'warehouse_id' => $leg['warehouse_id'] ?? null,
                'warehouse_slug' => $slug,
                'warehouse_code' => $leg['warehouse_code'] ?? null,
                'warehouse_name' => $leg['warehouse_name'] ?? null,
                'tracking_number' => $leg['tracking_number'] ?? null,
                'carrier' => $leg['carrier'] ?? null,
                'shipped_units' => $units,
                'unit_rate' => in_array($slug, self::OVERSEAS_WAREHOUSE_SLUGS, true) ? '1.00' : '0.00',
                'source_amount' => $legSource,
                'source_currency' => 'USD',
                'rule' => $rule,
                'items' => $leg['items'] ?? [],
            ];
            $candidateSourceAt = $leg['updated_at'] ?? null;
            if ($candidateSourceAt !== null && ($sourceAt === null || $candidateSourceAt > $sourceAt)) $sourceAt = $candidateSourceAt;
        }
        if ($breakdown === []) return $this->blocked($costType, 'canonical_shipment_missing');
        sort($fingerprint);
        $sourceAmount = DecimalMoney::round($sourceAmount); $amount = $sourceAmount;
        if ($currency === 'USD') {
            foreach ($breakdown as &$leg) {
                $leg['posted_amount'] = $leg['source_amount'];
                $leg['posted_currency'] = 'USD';
                $leg['fx'] = null;
            }
            unset($leg);
        } else {
            $fx = $this->fxBridge->preview($order, 'USD');
            $extraForBlock = [
                'source_currency' => 'USD', 'source_amount' => $sourceAmount,
                'shipment_count' => count($breakdown), 'shipment_breakdown' => $breakdown,
                'aggregation_rule' => 'parent_sum_of_shipment_legs_v1',
            ];
            if (! ($fx['available'] ?? false)) return $this->blocked($costType, 'multi_shipment_rule_fx_snapshot_unavailable', $extraForBlock + ['fx_reason' => $fx['reason'] ?? 'fx_unavailable']);
            $amount = DecimalMoney::round(DecimalMoney::multiply($sourceAmount, (string) $fx['source_to_transaction_rate'], 8));
            foreach ($breakdown as &$leg) {
                $leg['posted_amount'] = DecimalMoney::round(DecimalMoney::multiply((string) $leg['source_amount'], (string) $fx['source_to_transaction_rate'], 8));
                $leg['posted_currency'] = $currency;
                $leg['fx'] = $fx;
            }
            unset($leg);
        }
        $extra = [
            'source_currency' => 'USD', 'source_amount' => $sourceAmount,
            'shipment_count' => count($breakdown), 'shipment_breakdown' => $breakdown,
            'aggregation_rule' => 'parent_sum_of_shipment_legs_v1',
        ];
        if ($currency !== 'USD') $extra['posting_fx'] = $fx + ['base_amount' => $sourceAmount];
        $family = $costType === 'first_mile_cost' ? 'first-mile' : 'handling';
        $source = 'multi-shipment:v1:'.$family.':order:'.$order->getKey().':'.substr(hash('sha256', implode(';', $fingerprint)), 0, 24);
        return $this->candidate($costType, $amount, $currency, 'confirmed_manual', 'confirmed', $source, $sourceAt, $extra);
    }

    private function aggregateCarrierCandidate(Model $order): array
    {
        $evidence = $this->carrier->read($order->getKey());
        if ($evidence === null || isset($evidence['blocked'])) return $this->blocked('last_mile_cost', (string) ($evidence['blocked'] ?? 'carrier_evidence_missing'));
        $currency = $this->orderCurrency($order); $total = '0'; $breakdown = []; $fingerprint = []; $sourceAt = null;
        $rawBreakdown = array_map(static function (array $leg) use ($currency): array {
            $billing = is_array($leg['billing'] ?? null) ? $leg['billing'] : null;
            return [
                'shipment_id' => (int) ($leg['shipment_id'] ?? 0),
                'fulfillment_order_id' => $leg['fulfillment_order_id'] ?? null, 'fulfillment_suffix' => $leg['fulfillment_suffix'] ?? null,
                'warehouse_slug' => $leg['warehouse_slug'] ?? null, 'warehouse_code' => $leg['warehouse_code'] ?? null,
                'warehouse_name' => $leg['warehouse_name'] ?? null, 'tracking_number' => $leg['tracking_number'] ?? null,
                'carrier' => $leg['carrier'] ?? null, 'shipped_units' => $leg['shipped_units'] ?? null, 'items' => $leg['items'] ?? [],
                'billing_id' => $billing['billing_id'] ?? null, 'source_amount' => $billing['actual_cost'] ?? null,
                'source_currency' => $billing['currency'] ?? null, 'posted_amount' => null, 'posted_currency' => $currency,
                'billing_document_number' => $billing['billing_document_number'] ?? null,
                'provider_reference' => $billing['provider_reference'] ?? null, 'data_source' => $billing['data_source'] ?? null,
                'fx' => null,
            ];
        }, $evidence['shipments'] ?? []);
        foreach ($evidence['shipments'] ?? [] as $leg) {
            $shipmentId = (int) ($leg['shipment_id'] ?? 0); $billing = $leg['billing'] ?? null;
            if (! is_array($billing)) return $this->blocked('last_mile_cost', 'carrier_billing_missing_on_shipment', ['shipment_id' => $shipmentId, 'shipment_count' => (int) ($evidence['shipment_count'] ?? 0), 'shipment_breakdown' => $rawBreakdown]);
            $billingId = (int) ($billing['billing_id'] ?? 0);
            $sourceCurrency = strtoupper(trim((string) ($billing['currency'] ?? '')));
            $identity = $billing['billing_document_number'] ?? $billing['provider_reference'] ?? $billing['data_source'] ?? null;
            if ($billingId < 1 || ($billing['actual_cost'] ?? null) === null || $sourceCurrency === '' || ! is_string($identity) || trim($identity) === '') {
                return $this->blocked('last_mile_cost', 'complete_carrier_billing_evidence_required', ['shipment_id' => $shipmentId]);
            }
            $sourceAmount = DecimalMoney::round((string) $billing['actual_cost']); $postedAmount = $sourceAmount; $legFx = null;
            if ($sourceCurrency !== $currency) {
                $fx = $this->fxBridge->preview($order, $sourceCurrency);
                if (! ($fx['available'] ?? false)) return $this->blocked('last_mile_cost', 'carrier_billing_fx_snapshot_unavailable', ['shipment_id' => $shipmentId, 'source_currency' => $sourceCurrency, 'fx_reason' => $fx['reason'] ?? 'fx_unavailable']);
                $postedAmount = DecimalMoney::round(DecimalMoney::multiply($sourceAmount, (string) $fx['source_to_transaction_rate'], 8));
                $legFx = $fx;
            }
            $total = DecimalMoney::add($total, $postedAmount, 8);
            $fingerprint[] = implode('|', [$shipmentId, $billingId, $sourceCurrency, $sourceAmount, trim($identity)]);
            $breakdown[] = [
                'shipment_id' => $shipmentId, 'billing_id' => $billingId,
                'fulfillment_order_id' => $leg['fulfillment_order_id'] ?? null, 'fulfillment_suffix' => $leg['fulfillment_suffix'] ?? null,
                'warehouse_slug' => $leg['warehouse_slug'] ?? null, 'warehouse_code' => $leg['warehouse_code'] ?? null,
                'warehouse_name' => $leg['warehouse_name'] ?? null, 'tracking_number' => $leg['tracking_number'] ?? null,
                'carrier' => $leg['carrier'] ?? null, 'shipped_units' => $leg['shipped_units'] ?? null,
                'source_amount' => $sourceAmount, 'source_currency' => $sourceCurrency,
                'posted_amount' => $postedAmount, 'posted_currency' => $currency,
                'billing_document_number' => $billing['billing_document_number'] ?? null,
                'provider_reference' => $billing['provider_reference'] ?? null, 'data_source' => $billing['data_source'] ?? null,
                'fx' => $legFx, 'items' => $leg['items'] ?? [],
            ];
            $candidateSourceAt = $billing['billed_at'] ?? $billing['updated_at'] ?? null;
            if ($candidateSourceAt !== null && ($sourceAt === null || $candidateSourceAt > $sourceAt)) $sourceAt = $candidateSourceAt;
        }
        if ($breakdown === []) return $this->blocked('last_mile_cost', 'canonical_shipment_missing');
        sort($fingerprint); $total = DecimalMoney::round($total);
        $extra = ['shipment_count' => count($breakdown), 'shipment_breakdown' => $breakdown, 'aggregation_rule' => 'parent_sum_of_shipment_carrier_billing_v1'];
        if ($currency !== 'USD') {
            $posting = $this->fxBridge->preview($order, $currency);
            if (! ($posting['available'] ?? false)) return $this->blocked('last_mile_cost', 'carrier_billing_transaction_fx_snapshot_unavailable', $extra + ['fx_reason' => $posting['reason'] ?? 'fx_unavailable']);
            $extra['posting_fx'] = $posting + ['base_amount' => DecimalMoney::round(DecimalMoney::multiply($total, (string) $posting['source_to_usd_rate'], 8))];
        }
        $source = 'multi-shipment:v1:last-mile:order:'.$order->getKey().':'.substr(hash('sha256', implode(';', $fingerprint)), 0, 24);
        return $this->candidate('last_mile_cost', $total, $currency, 'provider_import', 'confirmed', $source, $sourceAt, $extra);
    }

    private function carrierCandidate(Model $order): array
    {
        if ($this->multiShipmentAggregationEnabled()) return $this->aggregateCarrierCandidate($order);
        $evidence = $this->carrier->read($order->getKey());
        if ($evidence === null || isset($evidence['blocked'])) {
            return $this->blocked('last_mile_cost', (string) ($evidence['blocked'] ?? 'carrier_evidence_missing'));
        }

        $currency = $this->orderCurrency($order);
        $sourceCurrency = strtoupper(trim((string) ($evidence['currency'] ?? '')));
        $identity = $evidence['billing_document_number'] ?? $evidence['provider_reference'] ?? $evidence['data_source'] ?? null;
        if ($evidence['actual_cost'] === null || $sourceCurrency === '' || ! is_string($identity) || trim($identity) === '') {
            return $this->blocked('last_mile_cost', 'complete_carrier_billing_evidence_required');
        }

        $sourceAmount = DecimalMoney::round((string) $evidence['actual_cost']);
        $amount = $sourceAmount;
        $extra = [
            'source_currency' => $sourceCurrency,
            'source_amount' => $sourceAmount,
            'shipment_id' => $evidence['shipment_id'],
            'billing_id' => $evidence['billing_id'],
            'billing_document_number' => $evidence['billing_document_number'] ?? null,
            'provider_reference' => $evidence['provider_reference'] ?? null,
            'data_source' => $evidence['data_source'] ?? null,
        ];
        if ($sourceCurrency !== $currency) {
            $fx = $this->fxBridge->preview($order, $sourceCurrency);
            if (! ($fx['available'] ?? false)) {
                return $this->blocked('last_mile_cost', 'carrier_billing_fx_snapshot_unavailable', $extra + ['fx_reason' => $fx['reason'] ?? 'fx_unavailable']);
            }
            $amount = DecimalMoney::round(DecimalMoney::multiply($sourceAmount, (string) $fx['source_to_transaction_rate'], 8));
            $extra['posting_fx'] = $fx + [
                'base_amount' => DecimalMoney::round(DecimalMoney::multiply($sourceAmount, (string) $fx['source_to_usd_rate'], 8)),
            ];
        }

        return $this->candidate(
            'last_mile_cost', $amount, $currency, 'provider_import', 'confirmed',
            'carrier-billing:v1:shipment:'.$evidence['shipment_id'].':billing:'.$evidence['billing_id'],
            $evidence['billed_at'] ?? $evidence['updated_at'] ?? null, $extra,
        );
    }

    private function overseasHandlingAvailable(): bool
    {
        return interface_exists(OverseasHandlingEvidenceReader::class)
            && app()->bound(OverseasHandlingEvidenceReader::class);
    }

    private function overseasHandlingReader(): ?object
    {
        return $this->overseasHandlingAvailable() ? app(OverseasHandlingEvidenceReader::class) : null;
    }

    private function overseasHandlingCandidate(Model $order): array
    {
        if ($this->multiShipmentAggregationEnabled()) return $this->aggregateRuleCandidate($order, 'overseas_handling_cost');
        $reader = $this->overseasHandlingReader();
        if ($reader === null) return $this->blocked('overseas_handling_cost', 'overseas_handling_feature_unavailable');
        $evidence=$reader->read($order->getKey());
        if($evidence===null||isset($evidence['blocked'])) return $this->blocked('overseas_handling_cost',(string)($evidence['blocked']??'overseas_handling_evidence_missing'));
        $currency=$this->orderCurrency($order); $sourceCurrency=strtoupper(trim((string)($evidence['currency']??'')));
        $identity=$evidence['billing_document_number']??$evidence['provider_reference']??$evidence['data_source']??null;
        if($evidence['actual_cost']===null||$sourceCurrency===''||!is_string($identity)||trim($identity)==='') return $this->blocked('overseas_handling_cost','complete_overseas_handling_evidence_required');
        $sourceAmount=DecimalMoney::round((string)$evidence['actual_cost']); $amount=$sourceAmount;
        $extra=['source_currency'=>$sourceCurrency,'source_amount'=>$sourceAmount,'shipment_id'=>$evidence['shipment_id'],'billing_id'=>$evidence['billing_id'],'billing_document_number'=>$evidence['billing_document_number']??null,'provider_reference'=>$evidence['provider_reference']??null,'data_source'=>$evidence['data_source']??null];
        if($sourceCurrency!==$currency){
            $fx=$this->fxBridge->preview($order,$sourceCurrency);
            if(!($fx['available']??false)) return $this->blocked('overseas_handling_cost','overseas_handling_fx_snapshot_unavailable',$extra+['fx_reason'=>$fx['reason']??'fx_unavailable']);
            $amount=DecimalMoney::round(DecimalMoney::multiply($sourceAmount,(string)$fx['source_to_transaction_rate'],8));
            $extra['posting_fx']=$fx+['base_amount'=>DecimalMoney::round(DecimalMoney::multiply($sourceAmount,(string)$fx['source_to_usd_rate'],8))];
        }
        return $this->candidate('overseas_handling_cost',$amount,$currency,'provider_import','confirmed','overseas-handling:v1:shipment:'.$evidence['shipment_id'].':billing:'.$evidence['billing_id'],$evidence['billed_at']??$evidence['updated_at']??null,$extra);
    }

    private function firstMileCandidate(Model $order): array
    {
        if ($this->multiShipmentAggregationEnabled()) return $this->aggregateRuleCandidate($order, 'first_mile_cost');
        $evidence = $this->fulfillmentOrigin->read($order->getKey());
        if ($evidence === null || isset($evidence['blocked'])) {
            return $this->blocked('first_mile_cost', (string) ($evidence['blocked'] ?? 'fulfillment_origin_missing'));
        }

        $slug = strtolower(trim((string) ($evidence['warehouse_slug'] ?? '')));
        $currency = $this->orderCurrency($order);
        $context = [
            'warehouse_id' => $evidence['warehouse_id'] ?? null,
            'warehouse_slug' => $slug,
            'shipment_id' => $evidence['shipment_id'] ?? null,
        ];

        if (in_array($slug, self::CHINA_DIRECT_WAREHOUSE_SLUGS, true)) {
            return $this->candidate(
                'first_mile_cost', '0.00', $currency, 'confirmed_manual', 'confirmed',
                'owner-rule:first-mile:v1:china-direct:'.$slug, null,
                $context + ['rule' => 'china_direct_zero'],
            );
        }

        if (in_array($slug, self::OVERSEAS_WAREHOUSE_SLUGS, true)) {
            $amount = '1.00';
            $extra = $context + ['rule' => 'overseas_warehouse_usd_one_per_order'];
            if ($currency !== 'USD') {
                $fx = $this->fxBridge->preview($order, 'USD');
                if (! ($fx['available'] ?? false)) {
                    return $this->blocked('first_mile_cost', 'first_mile_fx_snapshot_unavailable', $context + [
                        'source_currency' => 'USD',
                        'order_currency' => $currency,
                        'source_amount' => '1.00',
                        'fx_reason' => $fx['reason'] ?? 'fx_unavailable',
                    ]);
                }
                $amount = DecimalMoney::round(DecimalMoney::multiply('1.00', (string) $fx['source_to_transaction_rate'], 8));
                $extra += [
                    'source_currency' => 'USD',
                    'source_amount' => '1.00',
                    'posting_fx' => $fx + ['base_amount' => '1.00'],
                ];
            }

            return $this->candidate(
                'first_mile_cost', $amount, $currency, 'confirmed_manual', 'confirmed',
                'owner-rule:first-mile:v1:overseas:'.$slug, null, $extra,
            );
        }

        return $this->blocked('first_mile_cost', 'first_mile_warehouse_classification_unknown', $context);
    }

    private function paymentFeeCandidate(Model $order): array
    {
        $financials = $order->financials()->first();
        if ($financials === null) {
            return $this->blocked('payment_fee', 'payment_fee_order_financials_missing');
        }
        if ($financials->provider_order_total === null) {
            return $this->blocked('payment_fee', 'payment_fee_provider_order_total_missing');
        }

        $providerTotal = trim((string) $financials->provider_order_total);
        if (! preg_match('/^-?\d+(?:\.\d+)?$/D', $providerTotal)) {
            return $this->blocked('payment_fee', 'payment_fee_provider_order_total_invalid');
        }
        if (bccomp($providerTotal, '0', 8) < 0) {
            return $this->blocked('payment_fee', 'payment_fee_provider_order_total_negative', ['provider_order_total' => $providerTotal]);
        }

        $currency = $this->orderCurrency($order);
        $amount = DecimalMoney::round(DecimalMoney::multiply($providerTotal, self::PAYMENT_FEE_RATE, 8));
        $extra = [
            'rule' => 'provider_order_total_x_3_5_percent',
            'fee_rate' => self::PAYMENT_FEE_RATE,
            'basis' => 'provider_order_total',
            'basis_amount' => DecimalMoney::round($providerTotal),
            'refund_policy' => 'separate_no_fee_reversal',
        ];

        if ($currency !== 'USD') {
            $fx = $this->fxBridge->preview($order, $currency);
            if (! ($fx['available'] ?? false)) {
                return $this->blocked('payment_fee', 'payment_fee_fx_snapshot_unavailable', $extra + [
                    'order_currency' => $currency,
                    'fx_reason' => $fx['reason'] ?? 'fx_unavailable',
                ]);
            }
            $extra['posting_fx'] = $fx + [
                'base_amount' => DecimalMoney::round(DecimalMoney::multiply($amount, (string) $fx['source_to_usd_rate'], 8)),
            ];
        }

        return $this->candidate(
            'payment_fee', $amount, $currency, 'confirmed_manual', 'confirmed',
            'owner-rule:payment-fee:v1:provider-order-total:3.5pct',
            $financials->provider_modified_at ?? $financials->paid_at ?? null,
            $extra,
        );
    }

    private function customsCandidate(Model $order): array
    {
        return $this->candidate(
            'customs_tax', '0.00', $this->orderCurrency($order), 'confirmed_manual', 'confirmed',
            'owner-rule:customs-tax:v1:none', null, ['rule' => 'no_customs_tax'],
        );
    }

    private function replacementLossCandidate(Model $order): ?array
    {
        if (! method_exists($order, 'replacementFulfillments')) return null;
        $order->loadMissing([
            'replacementFulfillments.items.product',
            'replacementFulfillments.shipment.warehouse',
            'replacementFulfillments.shipment.billingEvidence',
        ]);
        $replacements = $order->replacementFulfillments->sortBy('id')->values();
        if ($replacements->isEmpty()) return null;

        $currency = $this->orderCurrency($order);
        $total = '0';
        $breakdown = [];
        $fingerprint = [];
        $sourceAt = null;

        foreach ($replacements as $replacement) {
            $shipment = $replacement->shipment;
            if (! $shipment || ($shipment->shipment_status === 'pending' && blank($shipment->tracking_number) && $shipment->shipped_at === null)) {
                return $this->blocked('refund_replacement_loss', 'replacement_shipment_not_dispatched', [
                    'replacement_count' => $replacements->count(),
                    'replacement_order_id' => (int) $replacement->getKey(),
                    'replacement_suffix' => $replacement->fulfillment_suffix,
                    'replacement_breakdown' => $breakdown,
                ]);
            }
            $warehouse = $shipment->warehouse;
            if (! $warehouse) {
                return $this->blocked('refund_replacement_loss', 'replacement_shipment_warehouse_missing', [
                    'replacement_count' => $replacements->count(),
                    'replacement_order_id' => (int) $replacement->getKey(),
                    'shipment_id' => (int) $shipment->getKey(),
                    'replacement_breakdown' => $breakdown,
                ]);
            }

            $items = $this->products->read($replacement->getKey());
            if ($items === []) {
                return $this->blocked('refund_replacement_loss', 'replacement_product_cost_missing', [
                    'replacement_count' => $replacements->count(),
                    'replacement_order_id' => (int) $replacement->getKey(),
                    'replacement_breakdown' => $breakdown,
                ]);
            }
            $productTotal = '0';
            $productLines = [];
            foreach ($items as $row) {
                $quantity = (int) ($row['quantity'] ?? 0);
                $sources = collect($row['confirmed_cost_sources'] ?? [])->unique(fn (array $source): string => strtoupper((string) ($source['currency'] ?? '')).'|'.(string) ($source['unit_cost'] ?? ''))->values();
                if ($quantity <= 0 || $sources->count() !== 1) {
                    return $this->blocked('refund_replacement_loss', 'replacement_confirmed_product_cost_required', [
                        'replacement_count' => $replacements->count(),
                        'replacement_order_id' => (int) $replacement->getKey(),
                        'item_id' => $row['item_id'] ?? null,
                        'replacement_breakdown' => $breakdown,
                    ]);
                }
                $source = $sources->first();
                $sourceCurrency = strtoupper(trim((string) ($source['currency'] ?? '')));
                if ($sourceCurrency === '' || ! isset($source['unit_cost'])) {
                    return $this->blocked('refund_replacement_loss', 'replacement_confirmed_product_cost_required', [
                        'replacement_count' => $replacements->count(), 'replacement_order_id' => (int) $replacement->getKey(),
                    ]);
                }
                $sourceSubtotal = DecimalMoney::round(DecimalMoney::multiply((string) $source['unit_cost'], (string) $quantity, 8));
                $converted = $this->replacementConvert($order, $sourceSubtotal, $sourceCurrency, 'replacement_product_cost_fx_snapshot_unavailable');
                if (isset($converted['blocked'])) return $this->blocked('refund_replacement_loss', $converted['blocked'], [
                    'replacement_count' => $replacements->count(), 'replacement_order_id' => (int) $replacement->getKey(),
                    'source_currency' => $sourceCurrency, 'replacement_breakdown' => $breakdown,
                ]);
                $productTotal = DecimalMoney::add($productTotal, $converted['amount'], 8);
                $productLines[] = [
                    'item_id' => $row['item_id'] ?? null, 'product_id' => $row['product_id'] ?? null,
                    'sku' => $row['sku'] ?? null, 'quantity' => $quantity,
                    'unit_cost' => DecimalMoney::round((string) $source['unit_cost']),
                    'source_currency' => $sourceCurrency, 'source_subtotal' => $sourceSubtotal,
                    'posted_amount' => $converted['amount'], 'posted_currency' => $currency,
                    'source_id' => $source['id'] ?? null, 'source_reference' => $source['source_reference'] ?? null,
                    'fx' => $converted['fx'],
                ];
                $fingerprint[] = 'product|'.$replacement->getKey().'|'.($source['id'] ?? '').'|'.$sourceCurrency.'|'.$sourceSubtotal;
                $candidateAt = $source['source_at'] ?? $source['confirmed_at'] ?? null;
                if ($candidateAt !== null && ($sourceAt === null || $candidateAt > $sourceAt)) $sourceAt = $candidateAt;
            }
            $productTotal = DecimalMoney::round($productTotal);

            $units = (int) $replacement->items->sum('quantity');
            $slug = strtolower(trim((string) $warehouse->slug));
            if ($units <= 0) {
                return $this->blocked('refund_replacement_loss', 'replacement_item_quantity_missing', [
                    'replacement_count' => $replacements->count(), 'replacement_order_id' => (int) $replacement->getKey(),
                ]);
            }
            if (in_array($slug, self::OVERSEAS_WAREHOUSE_SLUGS, true)) {
                $firstSource = DecimalMoney::round(DecimalMoney::multiply((string) $units, '1.00', 8));
                $handlingSource = $firstSource;
            } elseif (in_array($slug, self::CHINA_DIRECT_WAREHOUSE_SLUGS, true)) {
                $firstSource = '0.00';
                $handlingSource = '0.00';
            } else {
                return $this->blocked('refund_replacement_loss', 'replacement_warehouse_classification_unknown', [
                    'replacement_count' => $replacements->count(), 'replacement_order_id' => (int) $replacement->getKey(), 'warehouse_slug' => $slug,
                ]);
            }
            $first = $this->replacementConvert($order, $firstSource, 'USD', 'replacement_first_mile_fx_snapshot_unavailable');
            $handling = $this->replacementConvert($order, $handlingSource, 'USD', 'replacement_handling_fx_snapshot_unavailable');
            if (isset($first['blocked'])) return $this->blocked('refund_replacement_loss', $first['blocked'], ['replacement_order_id' => (int) $replacement->getKey()]);
            if (isset($handling['blocked'])) return $this->blocked('refund_replacement_loss', $handling['blocked'], ['replacement_order_id' => (int) $replacement->getKey()]);

            if (in_array($slug, self::CHINA_DIRECT_WAREHOUSE_SLUGS, true)) {
                $warehouseComponent = ['amount' => '0.00', 'currency' => $currency, 'source_amount' => '0.00', 'source_currency' => $currency, 'evidence' => 'owner_rule_china_direct_zero', 'fx' => null];
            } else {
                $warehouseEvidence = $this->warehouseCosts->read($replacement->getKey());
                if ($warehouseEvidence === null || isset($warehouseEvidence['blocked'])) {
                    return $this->blocked('refund_replacement_loss', (string) ($warehouseEvidence['blocked'] ?? 'replacement_warehouse_cost_allocation_missing'), [
                        'replacement_count' => $replacements->count(), 'replacement_order_id' => (int) $replacement->getKey(), 'replacement_breakdown' => $breakdown,
                    ]);
                }
                $warehouseCurrency = strtoupper(trim((string) ($warehouseEvidence['currency'] ?? '')));
                $warehouseSource = isset($warehouseEvidence['source_amount']) ? DecimalMoney::round((string) $warehouseEvidence['source_amount']) : null;
                if ($warehouseCurrency === '' || $warehouseSource === null) {
                    return $this->blocked('refund_replacement_loss', 'replacement_warehouse_cost_allocation_incomplete', ['replacement_order_id' => (int) $replacement->getKey()]);
                }
                $convertedWarehouse = $this->replacementConvert($order, $warehouseSource, $warehouseCurrency, 'replacement_warehouse_cost_fx_snapshot_unavailable');
                if (isset($convertedWarehouse['blocked'])) return $this->blocked('refund_replacement_loss', $convertedWarehouse['blocked'], ['replacement_order_id' => (int) $replacement->getKey()]);
                $warehouseComponent = [
                    'amount' => $convertedWarehouse['amount'], 'currency' => $currency,
                    'source_amount' => $warehouseSource, 'source_currency' => $warehouseCurrency,
                    'batch_id' => $warehouseEvidence['batch_id'] ?? null, 'row_ids' => $warehouseEvidence['row_ids'] ?? [],
                    'fx' => $convertedWarehouse['fx'],
                ];
                $fingerprint[] = 'warehouse|'.$replacement->getKey().'|'.($warehouseEvidence['batch_id'] ?? '').'|'.implode(',', $warehouseEvidence['row_ids'] ?? []).'|'.$warehouseCurrency.'|'.$warehouseSource;
                $candidateAt = $warehouseEvidence['source_at'] ?? null;
                if ($candidateAt !== null && ($sourceAt === null || $candidateAt > $sourceAt)) $sourceAt = $candidateAt;
            }

            $billing = $shipment->billingEvidence;
            if (! $billing || $billing->actual_cost === null || blank($billing->currency)) {
                return $this->blocked('refund_replacement_loss', 'replacement_last_mile_billing_missing', [
                    'replacement_count' => $replacements->count(), 'replacement_order_id' => (int) $replacement->getKey(),
                    'shipment_id' => (int) $shipment->getKey(), 'replacement_breakdown' => $breakdown,
                ]);
            }
            $billingIdentity = $billing->billing_document_number ?: ($billing->provider_reference ?: $billing->data_source);
            if (blank($billingIdentity)) {
                return $this->blocked('refund_replacement_loss', 'replacement_last_mile_billing_identity_missing', [
                    'replacement_order_id' => (int) $replacement->getKey(), 'shipment_id' => (int) $shipment->getKey(),
                ]);
            }
            $lastSourceCurrency = strtoupper((string) $billing->currency);
            $lastSourceAmount = DecimalMoney::round((string) $billing->actual_cost);
            $last = $this->replacementConvert($order, $lastSourceAmount, $lastSourceCurrency, 'replacement_last_mile_fx_snapshot_unavailable');
            if (isset($last['blocked'])) return $this->blocked('refund_replacement_loss', $last['blocked'], ['replacement_order_id' => (int) $replacement->getKey()]);
            $fingerprint[] = 'last-mile|'.$replacement->getKey().'|'.$shipment->getKey().'|'.$billing->getKey().'|'.$lastSourceCurrency.'|'.$lastSourceAmount.'|'.trim((string) $billingIdentity);
            $candidateAt = $billing->billed_at ?? $billing->updated_at ?? null;
            if ($candidateAt !== null && ($sourceAt === null || $candidateAt > $sourceAt)) $sourceAt = $candidateAt;

            $components = [
                'product_cost' => $productTotal,
                'first_mile_cost' => $first['amount'],
                'overseas_handling_cost' => $handling['amount'],
                'warehouse_cost' => $warehouseComponent['amount'],
                'last_mile_cost' => $last['amount'],
            ];
            $replacementTotal = '0';
            foreach ($components as $amount) $replacementTotal = DecimalMoney::add($replacementTotal, (string) $amount, 8);
            $replacementTotal = DecimalMoney::round($replacementTotal);
            $total = DecimalMoney::add($total, $replacementTotal, 8);
            $fingerprint[] = 'replacement|'.$replacement->getKey().'|'.$replacement->fulfillment_suffix.'|'.$replacement->fulfillment_reason.'|'.$warehouse->code.'|'.$units;
            $breakdown[] = [
                'replacement_order_id' => (int) $replacement->getKey(),
                'erp_order_id' => $replacement->erp_order_id,
                'suffix' => $replacement->fulfillment_suffix,
                'reason' => $replacement->fulfillment_reason,
                'origin_fulfillment_id' => $replacement->fulfillment_origin_id,
                'shipment_id' => (int) $shipment->getKey(),
                'warehouse_id' => (int) $warehouse->getKey(),
                'warehouse_code' => $warehouse->code,
                'warehouse_name' => $warehouse->name,
                'tracking_number' => $shipment->tracking_number,
                'shipped_units' => $units,
                'components' => [
                    'product_cost' => ['amount' => $productTotal, 'currency' => $currency, 'lines' => $productLines],
                    'first_mile_cost' => ['amount' => $first['amount'], 'currency' => $currency, 'source_amount' => $firstSource, 'source_currency' => 'USD', 'fx' => $first['fx']],
                    'overseas_handling_cost' => ['amount' => $handling['amount'], 'currency' => $currency, 'source_amount' => $handlingSource, 'source_currency' => 'USD', 'fx' => $handling['fx']],
                    'warehouse_cost' => $warehouseComponent,
                    'last_mile_cost' => ['amount' => $last['amount'], 'currency' => $currency, 'source_amount' => $lastSourceAmount, 'source_currency' => $lastSourceCurrency, 'billing_id' => (int) $billing->getKey(), 'billing_document_number' => $billing->billing_document_number, 'provider_reference' => $billing->provider_reference, 'fx' => $last['fx']],
                ],
                'replacement_total' => $replacementTotal,
                'currency' => $currency,
            ];
        }

        sort($fingerprint);
        $total = DecimalMoney::round($total);
        $extra = [
            'replacement_count' => $replacements->count(),
            'replacement_breakdown' => $breakdown,
            'aggregation_rule' => 'parent_incremental_replacement_fulfillment_loss_v1',
            'double_count_guard' => 'replacement_shipments_excluded_from_original_product_and_logistics_costs',
        ];
        if ($currency !== 'USD') {
            $posting = $this->fxBridge->preview($order, $currency);
            if (! ($posting['available'] ?? false)) return $this->blocked('refund_replacement_loss', 'replacement_transaction_fx_snapshot_unavailable', $extra + ['fx_reason' => $posting['reason'] ?? 'fx_unavailable']);
            $extra['posting_fx'] = $posting + ['base_amount' => DecimalMoney::round(DecimalMoney::multiply($total, (string) $posting['source_to_usd_rate'], 8))];
        }
        return $this->candidate(
            'refund_replacement_loss', $total, $currency, 'allocated_actual', 'confirmed',
            'replacement-loss:v1:order:'.$order->getKey().':'.substr(hash('sha256', implode(';', $fingerprint)), 0, 24),
            $sourceAt, $extra,
        );
    }

    /** @return array{amount:string,fx:?array,blocked?:string} */
    private function replacementConvert(Model $order, string $sourceAmount, string $sourceCurrency, string $fxBlocker): array
    {
        $currency = $this->orderCurrency($order);
        $sourceCurrency = strtoupper(trim($sourceCurrency));
        $sourceAmount = DecimalMoney::round($sourceAmount);
        if ($sourceCurrency === $currency) return ['amount' => $sourceAmount, 'fx' => null];
        $fx = $this->fxBridge->preview($order, $sourceCurrency);
        if (! ($fx['available'] ?? false)) return ['amount' => '0.00', 'fx' => null, 'blocked' => $fxBlocker];
        return [
            'amount' => DecimalMoney::round(DecimalMoney::multiply($sourceAmount, (string) $fx['source_to_transaction_rate'], 8)),
            'fx' => $fx,
        ];
    }

    private function affiliateCandidate(Model $order): array
    {
        $rows = $this->affiliate->read($order->getKey());
        $eligible = array_values(array_filter($rows, static function (array $row): bool {
            return in_array(strtolower((string) ($row['provider_status'] ?? '')), ['unpaid', 'paid'], true)
                && ($row['order_resolution_status'] ?? null) === 'resolved'
                && ($row['reconciliation_status'] ?? null) === 'present';
        }));
        if ($eligible === []) {
            return $this->blocked('kol_commission', $rows === [] ? 'referral_snapshot_missing' : 'no_eligible_referrals');
        }

        $currency = $this->orderCurrency($order);
        $currencies = array_values(array_unique(array_map(fn (array $row): string => strtoupper(trim((string) ($row['currency'] ?? ''))), $eligible)));
        if (count($currencies) !== 1 || $currencies[0] === '') {
            return $this->blocked('kol_commission', 'foreign_or_mixed_currency_referrals');
        }
        $sourceCurrency = $currencies[0];
        $total = '0';
        $ids = [];
        $sourceAt = null;
        foreach ($eligible as $row) {
            $total = DecimalMoney::add($total, (string) $row['commission_amount'], 8);
            $ids[] = (int) ($row['referral_id'] ?? $row['id']);
            $candidateSourceAt = $row['last_synced_at'] ?? $row['updated_at'] ?? null;
            if ($candidateSourceAt !== null && ($sourceAt === null || $candidateSourceAt > $sourceAt)) {
                $sourceAt = $candidateSourceAt;
            }
        }
        sort($ids);
        $sourceAmount = DecimalMoney::round($total);
        $amount = $sourceAmount;
        $extra = ['referral_ids' => $ids, 'source_currency' => $sourceCurrency, 'source_amount' => $sourceAmount];
        if ($sourceCurrency !== $currency) {
            $fx = $this->fxBridge->preview($order, $sourceCurrency);
            if (! ($fx['available'] ?? false)) {
                return $this->blocked('kol_commission', 'kol_commission_fx_snapshot_unavailable', $extra + ['fx_reason' => $fx['reason'] ?? 'fx_unavailable']);
            }
            $amount = DecimalMoney::round(DecimalMoney::multiply($sourceAmount, (string) $fx['source_to_transaction_rate'], 8));
            $extra['posting_fx'] = $fx + [
                'base_amount' => DecimalMoney::round(DecimalMoney::multiply($sourceAmount, (string) $fx['source_to_usd_rate'], 8)),
            ];
        }

        return $this->candidate(
            'kol_commission', $amount, $currency, 'provider_import', 'confirmed',
            'affiliatewp-referrals:v1:order:'.$order->getKey(), $sourceAt, $extra,
        );
    }

    /** @return array{status:string,entry:array<string,mixed>} */
    private function writeCandidate(Model $order, array $candidate): array
    {
        $entry = $order->costEntries()->lockForUpdate()->where('cost_type', $candidate['cost_type'])->first();
        $disposition = $this->disposition($candidate, $entry);
        if ($disposition !== 'apply') {
            return ['status' => $disposition, 'entry' => $this->decorateDisposition($candidate, $disposition, $entry)];
        }

        // Capture the pre-write state so we can emit an estimate→actual audit when applicable.
        $previousEstimateSnapshot = ($entry !== null && in_array((string) $entry->provenance, ['estimated_reference'], true))
            ? $entry->toArray()
            : null;

        $values = [
            'cost_type' => $candidate['cost_type'],
            'amount' => $candidate['amount'],
            'currency' => $candidate['currency'],
            'provenance' => $candidate['provenance'],
            'status' => $candidate['status'],
            'source_reference' => $candidate['source_reference'],
            'effective_at' => now(),
            'source_at' => $candidate['source_at'],
            'note' => 'ERP-ACCOUNTING-COST-MATERIALIZATION-V1-1-001',
        ];
        if (isset($candidate['posting_fx'])) {
            $fx = $candidate['posting_fx'];
            $this->fxBridge->freezeTransactionSnapshot($order, $fx);
            $values += [
                'exchange_rate' => $fx['source_to_usd_rate'],
                'exchange_rate_date' => $fx['source_exchange_rate_date'],
                'exchange_rate_source' => $fx['source_exchange_rate_source'],
                'base_amount' => $fx['base_amount'],
                'base_currency' => 'USD',
            ];
        }
        if ($entry) {
            $entry->fill($values)->save();
        } else {
            $entry = $order->costEntries()->create($values);
        }

        // P8.2 audit: emit explicit restatement context when an actual/confirmed fact replaces an estimate.
        if ($previousEstimateSnapshot !== null && in_array((string) $candidate['provenance'], self::STRONG_PROVENANCE, true)) {
            $variance = null;
            $prevCurrency = strtoupper((string) ($previousEstimateSnapshot['currency'] ?? ''));
            $newCurrency = strtoupper((string) $candidate['currency']);
            if ($prevCurrency === $newCurrency) {
                $variance = DecimalMoney::round(bcsub((string) $candidate['amount'], (string) $previousEstimateSnapshot['amount'], 8));
            }
            $this->audit->record(
                AuditActor::system(AuditSource::InternalSystem),
                'finance.estimate_to_actual_restatement',
                $order::class,
                $order->getKey(),
                before: [
                    'cost_type' => $candidate['cost_type'],
                    'estimated_amount' => $previousEstimateSnapshot['amount'],
                    'estimated_currency' => $previousEstimateSnapshot['currency'],
                    'estimated_source_reference' => $previousEstimateSnapshot['source_reference'],
                    'estimated_provenance' => $previousEstimateSnapshot['provenance'],
                ],
                after: [
                    'cost_type' => $candidate['cost_type'],
                    'actual_amount' => $candidate['amount'],
                    'actual_currency' => $candidate['currency'],
                    'actual_source_reference' => $candidate['source_reference'],
                    'actual_provenance' => $candidate['provenance'],
                    'variance' => $variance,
                    'variance_currency' => $variance !== null ? $newCurrency : null,
                ],
                reason: 'finance.p8_2_estimate_to_actual_restatement',
            );
        }

        return ['status' => 'applied', 'entry' => $entry->fresh()->toArray()];
    }

    private function annotateDisposition(Model $order, array $candidate): array
    {
        $entry = $order->costEntries()->where('cost_type', $candidate['cost_type'])->first();
        if (($candidate['status'] ?? null) === 'blocked') {
            $candidate['materialization_status'] = 'blocked';
            if ($entry && $this->isMaterializerOwned($entry)) {
                $candidate['stale_source'] = true;
                $candidate['stale_entry'] = $entry->toArray();
            }
            return $candidate;
        }

        $disposition = $this->disposition($candidate, $entry);
        return $this->decorateDisposition($candidate, $disposition, $entry);
    }

    private function annotateMultiShipmentDelta(Model $order, array $candidate): array
    {
        $entry = $order->costEntries()->where('cost_type', $candidate['cost_type'])->first();
        $candidate['previous_amount'] = $entry?->amount;
        $candidate['previous_currency'] = $entry?->currency;
        $candidate['delta_amount'] = null;
        if (($candidate['status'] ?? null) !== 'blocked'
            && isset($candidate['amount'], $candidate['currency'])
            && ($entry === null || strtoupper((string) $entry->currency) === strtoupper((string) $candidate['currency']))) {
            $candidate['delta_amount'] = DecimalMoney::round(bcsub((string) $candidate['amount'], (string) ($entry?->amount ?? '0'), 8));
        }
        return $candidate;
    }

    private function isMultiShipmentCandidate(array $candidate): bool
    {
        return str_starts_with((string) ($candidate['source_reference'] ?? ''), 'multi-shipment:v1:');
    }

    private function isReplacementLossCandidate(array $candidate): bool
    {
        return str_starts_with((string) ($candidate['source_reference'] ?? ''), 'replacement-loss:v1:');
    }

    private function sameReplacementLossEvidenceSnapshot(array $candidate, Model $entry): bool
    {
        return $this->isReplacementLossCandidate($candidate)
            && (string) $entry->source_reference === (string) ($candidate['source_reference'] ?? '')
            && strtoupper((string) $entry->currency) === strtoupper((string) ($candidate['currency'] ?? ''))
            && (string) $entry->provenance === (string) ($candidate['provenance'] ?? '')
            && (string) $entry->status === (string) ($candidate['status'] ?? '');
    }

    private function multiShipmentMayReplace(array $candidate, Model $entry): bool
    {
        $source = (string) $entry->source_reference;
        if (str_starts_with($source, 'multi-shipment:v1:')) return true;
        return match ((string) ($candidate['cost_type'] ?? '')) {
            'first_mile_cost' => str_starts_with($source, 'owner-rule:first-mile:v1:'),
            'last_mile_cost' => str_starts_with($source, 'carrier-billing:v1:'),
            default => false,
        };
    }

    private function sameMultiShipmentEvidenceSnapshot(array $candidate, Model $entry): bool
    {
        return $this->isMultiShipmentCandidate($candidate)
            && (string) $entry->source_reference === (string) ($candidate['source_reference'] ?? '')
            && strtoupper((string) $entry->currency) === strtoupper((string) ($candidate['currency'] ?? ''))
            && (string) $entry->provenance === (string) ($candidate['provenance'] ?? '')
            && (string) $entry->status === (string) ($candidate['status'] ?? '');
    }

    private function sameAffiliateEvidenceSnapshot(array $candidate, Model $entry): bool
    {
        $source = (string) ($candidate['source_reference'] ?? '');
        $candidateSourceAt = $candidate['source_at'] ?? null;
        $entrySourceAt = $entry->source_at;

        return str_starts_with($source, 'affiliatewp-referrals:v1:')
            && $candidateSourceAt !== null
            && $entrySourceAt !== null
            && strtotime((string) $candidateSourceAt) === $entrySourceAt->getTimestamp()
            && (string) $entry->source_reference === $source
            && (string) $entry->amount === (string) ($candidate['amount'] ?? '')
            && strtoupper((string) $entry->currency) === strtoupper((string) ($candidate['currency'] ?? ''))
            && (string) $entry->provenance === (string) ($candidate['provenance'] ?? '')
            && (string) $entry->status === (string) ($candidate['status'] ?? '');
    }

    private function disposition(array $candidate, ?Model $entry): string
    {
        if ($entry === null) {
            return 'apply';
        }
        // Multi-shipment source_reference is the immutable business-evidence fingerprint.
        // Once that exact evidence has been posted, later Settings FX/reference-date drift
        // must never revalue the historical OrderCostEntry snapshot. Evidence changes
        // (shipment/billing/source amount/etc.) produce a different fingerprint and remain apply-eligible.
        if ($this->sameMultiShipmentEvidenceSnapshot($candidate, $entry)
            || $this->sameAffiliateEvidenceSnapshot($candidate, $entry)
            || $this->sameReplacementLossEvidenceSnapshot($candidate, $entry)) {
            return 'noop';
        }
        if ((string) $entry->source_reference === (string) $candidate['source_reference']
            && (string) $entry->amount === (string) $candidate['amount']
            && strtoupper((string) $entry->currency) === strtoupper((string) $candidate['currency'])
            && (string) $entry->provenance === (string) $candidate['provenance']
            && (string) $entry->status === (string) $candidate['status']
            && $this->fxSnapshotMatches($candidate, $entry)) {
            return ($candidate['posting_fx']['freeze_transaction_fx'] ?? false) ? 'apply' : 'noop';
        }
        if ($this->isMultiShipmentCandidate($candidate)) {
            if ($this->multiShipmentMayReplace($candidate, $entry)) return 'apply';
            if (in_array((string) $entry->provenance, self::STRONG_PROVENANCE, true)) return 'blocked';
        }
        if ($this->isReplacementLossCandidate($candidate)) {
            if (str_starts_with((string) $entry->source_reference, 'replacement-loss:v1:')) return 'apply';
            if (in_array((string) $entry->provenance, self::STRONG_PROVENANCE, true)) return 'blocked';
        }
        if ($candidate['provenance'] === 'estimated_reference' && in_array((string) $entry->provenance, self::STRONG_PROVENANCE, true)) {
            return 'blocked';
        }
        if ($candidate['provenance'] === 'allocated_actual' && in_array((string) $entry->provenance, self::STRONG_PROVENANCE, true)) {
            return 'blocked';
        }
        if ($candidate['provenance'] !== 'estimated_reference' && in_array((string) $entry->provenance, ['actual', 'confirmed_manual', 'allocated_actual'], true)) {
            return 'blocked';
        }
        if ((string) $entry->provenance === (string) $candidate['provenance'] && (string) $entry->source_reference !== (string) $candidate['source_reference']) {
            return 'blocked';
        }
        return 'apply';
    }


    private function fxSnapshotMatches(array $candidate, Model $entry): bool
    {
        if (! isset($candidate['posting_fx'])) {
            return true;
        }
        $fx = $candidate['posting_fx'];
        return (string) $entry->exchange_rate === (string) $fx['source_to_usd_rate']
            && optional($entry->exchange_rate_date)->toDateString() === (string) $fx['source_exchange_rate_date']
            && (string) $entry->exchange_rate_source === (string) $fx['source_exchange_rate_source']
            && (string) $entry->base_amount === (string) $fx['base_amount']
            && strtoupper((string) $entry->base_currency) === 'USD';
    }

    private function decorateDisposition(array $candidate, string $disposition, ?Model $entry): array
    {
        $candidate['materialization_status'] = $disposition;
        if ($disposition === 'blocked' && $entry !== null) {
            $candidate['blocker'] = 'stronger_or_different_existing_fact';
            $candidate['existing_entry'] = $entry->toArray();
        }
        if ($disposition === 'noop' && $entry !== null) {
            $candidate['existing_entry_id'] = (int) $entry->getKey();
            if ($this->sameMultiShipmentEvidenceSnapshot($candidate, $entry)
                || $this->sameAffiliateEvidenceSnapshot($candidate, $entry)
                || $this->sameReplacementLossEvidenceSnapshot($candidate, $entry)) {
                // Keep dry-run plans and explainability anchored to the already-frozen posting amount.
                $candidate['amount'] = (string) $entry->amount;
                $candidate['currency'] = (string) $entry->currency;
                $candidate['posting_snapshot_frozen'] = true;
            }
        }
        return $candidate;
    }

    private function operationalBlocker(Model $order): ?string
    {
        $kind = $order->order_kind;
        $kind = $kind instanceof BackedEnum ? $kind->value : (string) $kind;
        if ($kind !== 'customer') {
            return 'order_kind_not_customer';
        }
        if ($order->externalReferences()->where('provider', 'historical_logistics')->exists()) {
            return 'historical_logistics_excluded';
        }
        return null;
    }

    private function forceBlocked(array $candidate, string $blocker): array
    {
        return ['cost_type' => $candidate['cost_type'], 'status' => 'blocked', 'blocker' => $blocker];
    }

    private function isMaterializerOwned(Model $entry): bool
    {
        $source = (string) $entry->source_reference;
        return str_starts_with($source, 'product-reference:v1:')
            || str_starts_with($source, 'carrier-billing:v1:')
            || str_starts_with($source, 'warehouse-storage-allocation:v1:')
            || str_starts_with($source, 'p9-storage-aging-allocation:v1:')
            || str_starts_with($source, 'overseas-handling:v1:')
            || str_starts_with($source, 'last-mile-estimate:v1:')
            || str_starts_with($source, 'p82-last-mile-estimate:v1:')
            || str_starts_with($source, 'affiliatewp-referrals:v1:')
            || str_starts_with($source, 'product-cost-source:v1:')
            || str_starts_with($source, 'owner-rule:payment-fee:v1:')
            || str_starts_with($source, 'multi-shipment:v1:')
            || str_starts_with($source, 'replacement-loss:v1:');
    }

    private function orderCurrency(Model $order): string
    {
        return strtoupper((string) (($order->financials()->first()?->currency) ?? $order->currency ?? 'USD'));
    }

    private function candidate(string $type, string $amount, string $currency, string $provenance, string $status, string $source, mixed $sourceAt, array $extra = []): array
    {
        return ['cost_type' => $type, 'amount' => $amount, 'currency' => strtoupper($currency), 'provenance' => $provenance, 'status' => $status, 'source_reference' => $source, 'source_at' => $sourceAt] + $extra;
    }

    private function blocked(string $type, string $blocker, array $extra = []): array
    {
        return ['cost_type' => $type, 'status' => 'blocked', 'blocker' => $blocker] + $extra;
    }
}
