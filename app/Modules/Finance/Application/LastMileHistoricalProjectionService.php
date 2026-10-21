<?php

namespace App\Modules\Finance\Application;

use App\Modules\Logistics\Application\Contracts\CarrierBillingEvidenceReader;
use App\Modules\Logistics\Application\Contracts\LastMileEstimateEvidenceReader;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Read-only P8.2 last-mile projection service.
 * Priority: strong actual → existing estimate → historical comparable median → overseas fallback USD 10/unit.
 * China-direct warehouses MUST NOT receive the USD 10/unit fallback.
 */
final class LastMileHistoricalProjectionService
{
    private const STRONG_PROVENANCE = ['actual', 'confirmed_manual', 'provider_import', 'allocated_actual'];
    private const OVERSEAS_WAREHOUSE_SLUGS = ['us-la', 'us-ny', 'us-moreplus', 'us-3sm', 'us-sm', 'us-sj', 'usa-sj', 'de', 'de-tianyun', 'ca'];
    private const CHINA_DIRECT_WAREHOUSE_SLUGS = ['factory', '007'];
    private const FALLBACK_USD_PER_UNIT = '10.00';
    private const HISTORICAL_WINDOW_DAYS = 90;

    public function __construct(
        private readonly CarrierBillingEvidenceReader $carrier,
        private readonly LastMileEstimateEvidenceReader $lastMileEstimates,
    ) {}

    /**
     * @return array{
     *   tier: string,
     *   amount: string|null,
     *   currency: string|null,
     *   confidence: string,
     *   sample_count: int|null,
     *   source_detail: array<string,mixed>,
     *   blocked: string|null,
     * }
     */
    public function project(Model $order): array
    {
        // Tier 1: actual strong fact (carrier billing evidence)
        $actual = $this->carrier->read($order->getKey());
        if ($this->hasStrongActual($actual)) {
            return $this->fromActual($actual, $order);
        }

        // Tier 2: existing eligible estimate from routing evidence reader
        $estimate = $this->lastMileEstimates->read($order->getKey());
        if ($estimate !== null && ! isset($estimate['blocked'])) {
            $amount = isset($estimate['amount']) ? DecimalMoney::round((string) $estimate['amount']) : null;
            $currency = strtoupper((string) ($estimate['currency'] ?? ''));
            if ($amount !== null && $currency !== '') {
                return [
                    'tier' => 'existing_estimate',
                    'amount' => $amount,
                    'currency' => $currency,
                    'confidence' => 'moderate',
                    'sample_count' => null,
                    'source_detail' => [
                        'selected_source' => $estimate['selected_source'] ?? null,
                        'source_at' => $estimate['source_at'] ?? null,
                    ],
                    'blocked' => null,
                ];
            }
        }

        // Tier 3: historical comparable actual median (90d window)
        $historical = $this->historicalComparableMedian($order);
        if ($historical !== null) {
            return $historical;
        }

        // Tier 4: overseas-warehouse low-confidence fallback USD 10 × shipped_units (not China-direct)
        return $this->fallbackProjection($order);
    }

    private function hasStrongActual(mixed $actual): bool
    {
        if (! is_array($actual) || isset($actual['blocked'])) {
            return false;
        }
        if (isset($actual['shipments'])) {
            if ($actual['shipments'] === []) {
                return false;
            }
            foreach ($actual['shipments'] as $leg) {
                $billing = $leg['billing'] ?? null;
                if (! is_array($billing) || ($billing['actual_cost'] ?? null) === null || blank($billing['currency'] ?? null)) {
                    return false;
                }
            }
            return true;
        }
        $identity = $actual['billing_document_number'] ?? $actual['provider_reference'] ?? $actual['data_source'] ?? null;
        return ($actual['actual_cost'] ?? null) !== null
            && ! blank($actual['currency'] ?? null)
            && is_string($identity)
            && trim($identity) !== '';
    }

    /** @return array<string,mixed> */
    private function fromActual(array $actual, Model $order): array
    {
        if (isset($actual['shipments'])) {
            $total = '0';
            foreach ($actual['shipments'] as $leg) {
                $total = DecimalMoney::add($total, (string) ($leg['billing']['actual_cost'] ?? '0'), 8);
            }
            $currency = strtoupper((string) ($order->financials?->currency ?? $order->currency ?? 'USD'));
            return [
                'tier' => 'actual_carrier_billing',
                'amount' => DecimalMoney::round($total),
                'currency' => $currency,
                'confidence' => 'high',
                'sample_count' => null,
                'source_detail' => ['shipment_count' => count($actual['shipments'])],
                'blocked' => null,
            ];
        }
        return [
            'tier' => 'actual_carrier_billing',
            'amount' => DecimalMoney::round((string) $actual['actual_cost']),
            'currency' => strtoupper((string) $actual['currency']),
            'confidence' => 'high',
            'sample_count' => null,
            'source_detail' => [
                'billing_document_number' => $actual['billing_document_number'] ?? null,
                'provider_reference' => $actual['provider_reference'] ?? null,
            ],
            'blocked' => null,
        ];
    }

    /** @return array<string,mixed>|null */
    private function historicalComparableMedian(Model $order): ?array
    {
        $order->loadMissing(['address', 'items', 'shipment.warehouse']);
        $country = strtoupper(trim((string) $order->address?->country));
        if ($country === '') {
            return null;
        }

        $shipment = $order->shipment;
        $warehouseSlug = strtolower(trim((string) ($shipment?->warehouse?->slug ?? '')));
        $carrier = $shipment?->carrier;
        $shippedUnits = (int) $order->items->sum('quantity');

        if ($warehouseSlug === '' || $shippedUnits < 1) {
            return null;
        }

        $currency = strtoupper((string) ($order->financials?->currency ?? $order->currency ?? 'USD'));

        $query = DB::table('order_cost_entries as oce')
            ->join('orders as o', 'o.id', '=', 'oce.order_id')
            ->join('shipments as s', 's.order_id', '=', 'o.id')
            ->join('warehouses as w', 'w.id', '=', 's.warehouse_id')
            ->leftJoin('order_addresses as oa', 'oa.order_id', '=', 'o.id')
            ->where('oce.cost_type', 'last_mile_cost')
            ->whereIn('oce.provenance', self::STRONG_PROVENANCE)
            ->where('w.slug', $warehouseSlug)
            ->where('oa.country', $country)
            ->where('o.id', '!=', $order->getKey())
            ->where('oce.currency', $currency)
            ->whereBetween('oce.effective_at', [now()->subDays(self::HISTORICAL_WINDOW_DAYS)->toDateTimeString(), now()->toDateTimeString()])
            ->whereNotNull('oce.amount');

        if (! blank($carrier)) {
            $query->where('s.carrier', $carrier);
        }

        $amounts = $query->pluck('oce.amount')
            ->map(fn ($a) => (float) $a)
            ->filter(fn (float $a) => $a > 0)
            ->sort()
            ->values();

        $count = $amounts->count();
        if ($count < 1) {
            return null;
        }

        $median = $count % 2 === 0
            ? ($amounts[$count / 2 - 1] + $amounts[$count / 2]) / 2
            : $amounts[(int) ($count / 2)];

        $confidence = match (true) {
            $count >= 10 => 'high',
            $count >= 3  => 'moderate',
            default      => 'low',
        };

        return [
            'tier' => 'historical_comparable_median',
            'amount' => number_format($median, 2, '.', ''),
            'currency' => $currency,
            'confidence' => $confidence,
            'sample_count' => $count,
            'source_detail' => [
                'warehouse_slug' => $warehouseSlug,
                'carrier' => $carrier,
                'destination_country' => $country,
                'window_days' => self::HISTORICAL_WINDOW_DAYS,
            ],
            'blocked' => null,
        ];
    }

    /** @return array<string,mixed> */
    private function fallbackProjection(Model $order): array
    {
        $order->loadMissing(['items', 'shipment.warehouse']);
        $shipment = $order->shipment;
        $warehouseSlug = strtolower(trim((string) ($shipment?->warehouse?->slug ?? '')));

        if (in_array($warehouseSlug, self::CHINA_DIRECT_WAREHOUSE_SLUGS, true)) {
            return [
                'tier' => 'no_projection_china_direct',
                'amount' => null,
                'currency' => null,
                'confidence' => 'none',
                'sample_count' => null,
                'source_detail' => ['warehouse_slug' => $warehouseSlug, 'reason' => 'china_direct_no_fallback'],
                'blocked' => 'china_direct_no_fallback_allowed',
            ];
        }


        if (! in_array($warehouseSlug, self::OVERSEAS_WAREHOUSE_SLUGS, true)) {
            return [
                'tier' => 'no_projection_warehouse_unresolved',
                'amount' => null,
                'currency' => null,
                'confidence' => 'none',
                'sample_count' => null,
                'source_detail' => ['warehouse_slug' => $warehouseSlug ?: null, 'reason' => 'overseas_warehouse_required'],
                'blocked' => 'overseas_warehouse_required_for_fallback',
            ];
        }

        $shippedUnits = (int) $order->items->sum('quantity');
        if ($shippedUnits < 1) {
            return [
                'tier' => 'fallback_usd10_per_unit',
                'amount' => null,
                'currency' => null,
                'confidence' => 'none',
                'sample_count' => null,
                'source_detail' => [],
                'blocked' => 'shipped_units_missing_for_fallback',
            ];
        }

        $amount = DecimalMoney::round(DecimalMoney::multiply(self::FALLBACK_USD_PER_UNIT, (string) $shippedUnits, 8));
        return [
            'tier' => 'fallback_usd10_per_unit',
            'amount' => $amount,
            'currency' => 'USD',
            'confidence' => 'low',
            'sample_count' => 0,
            'source_detail' => [
                'warehouse_slug' => $warehouseSlug ?: null,
                'shipped_units' => $shippedUnits,
                'rate_per_unit' => self::FALLBACK_USD_PER_UNIT,
                'note' => 'overseas_warehouse_low_confidence_fallback',
            ],
            'blocked' => null,
        ];
    }
}
