<?php

namespace App\Modules\Logistics\Application;

use InvalidArgumentException;

final class WooHistoricalFulfillmentEvidenceAdapter
{
    /** @param list<array<string,mixed>>|null $carrierCatalog */
    public function __construct(private readonly ?array $carrierCatalog = null) {}

    /**
     * @param  array<string,mixed>  $canonicalOrder
     * @param  array<string,mixed>  $wooOrder
     * @param  array<string,mixed>  $context
     * @return list<array<string,mixed>>
     */
    public function adapt(array $canonicalOrder, array $wooOrder, array $context = []): array
    {
        $wooId = (int) ($wooOrder['id'] ?? 0);
        if ($wooId <= 0) {
            throw new InvalidArgumentException('Woo historical evidence requires a provider Order id.');
        }

        $orderReference = trim((string) ($canonicalOrder['woocommerce_reference'] ?? ''));
        $identityExact = $orderReference !== '' && $orderReference === (string) $wooId;
        $boundOrder = $identityExact ? $this->order($canonicalOrder) : null;
        $packages = $this->packages($wooOrder);

        if ($packages === []) {
            return [$this->candidate(
                $wooId,
                'no-tracking',
                $boundOrder,
                $identityExact,
                [],
                $context,
                false,
            )];
        }

        $multiPackage = count($packages) > 1;
        $targets = is_array($context['package_targets_by_tracking'] ?? null) ? $context['package_targets_by_tracking'] : [];
        $results = [];
        foreach ($packages as $package) {
            $tracking = $package['tracking_number'];
            $target = is_array($targets[$tracking] ?? null) ? $this->order($targets[$tracking]) : $boundOrder;
            $hasExactTarget = ! $multiPackage || is_array($targets[$tracking] ?? null);
            $ambiguousTargets = $tracking !== '' && in_array($tracking, $this->trackingList($context, 'ambiguous_package_tracking_numbers'), true);
            $packageContext = $context;
            $existing = is_array($context['existing_shipment'] ?? null) ? $context['existing_shipment'] : null;
            if ($multiPackage && $existing !== null && strtoupper(trim((string) ($existing['tracking_number'] ?? ''))) !== $tracking) {
                $packageContext['existing_shipment'] = null;
            }
            $results[] = $this->candidate(
                $wooId,
                $tracking,
                $target,
                $identityExact && $target !== null && $hasExactTarget,
                $package,
                $packageContext,
                $ambiguousTargets,
            );
        }

        return $results;
    }

    /** @return list<array{tracking_number:string,provider_slug:string,carrier:?string,ship_date:?string,line_items:list<array{id:int,quantity:int}>}> */
    private function packages(array $wooOrder): array
    {
        $meta = [];
        foreach (($wooOrder['meta_data'] ?? []) as $row) {
            if (is_array($row) && isset($row['key'])) {
                $meta[(string) $row['key']] = $row['value'] ?? null;
            }
        }

        $items = is_array($meta['_aftership_tracking_items'] ?? null)
            ? array_values($meta['_aftership_tracking_items'])
            : [];
        if ($items === []) {
            $legacyNumber = trim((string) ($meta['_aftership_tracking_number'] ?? ''));
            if ($legacyNumber !== '') {
                $items = [[
                    'tracking_number' => $legacyNumber,
                    'slug' => trim((string) ($meta['_aftership_tracking_provider_name'] ?? '')),
                    'additional_fields' => [],
                    'line_items' => [],
                ]];
            }
        }

        $result = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $tracking = strtoupper(trim((string) ($item['tracking_number'] ?? '')));
            if ($tracking === '') {
                continue;
            }
            $slug = strtolower(trim((string) ($item['slug'] ?? '')));
            $shipDate = $this->shipDate($item);
            $result[] = [
                'tracking_number' => $tracking,
                'provider_slug' => $slug,
                'carrier' => $this->canonicalCarrier($slug),
                'ship_date' => $shipDate,
                'line_items' => $this->lineItems($item['line_items'] ?? []),
            ];
        }

        return $result;
    }

    /** @param array<string,mixed>|null $order @param array<string,mixed> $package @param array<string,mixed> $context @return array<string,mixed> */
    private function candidate(int $wooId, string $keySuffix, ?array $order, bool $identityExact, array $package, array $context, bool $ambiguousShipment): array
    {
        $tracking = trim((string) ($package['tracking_number'] ?? '')) ?: null;
        $duplicate = $tracking !== null && in_array($tracking, $this->trackingList($context, 'duplicate_tracking_numbers'), true);
        $collision = $tracking !== null && in_array($tracking, $this->trackingList($context, 'tracking_collision_numbers'), true);
        $attemptConflict = $tracking !== null && in_array($tracking, $this->trackingList($context, 'contradictory_attempt_tracking_numbers'), true);
        $existing = is_array($context['existing_shipment'] ?? null) ? $context['existing_shipment'] : null;

        return [
            'candidate_key' => 'woocommerce:'.$wooId.':'.$keySuffix,
            'evidence_source' => 'woocommerce_tracking_meta',
            'historical_non_customer' => $order !== null && ($order['order_kind'] ?? null) !== 'customer',
            'order' => $order,
            'identity' => [
                'exact' => $identityExact,
                'candidate_order_ids' => $order === null ? [] : [(int) ($order['id'] ?? 0)],
            ],
            'existing_shipment' => $existing,
            'attempts' => ['contradictory' => $attemptConflict],
            'conflicts' => [
                'tracking_collision' => $collision,
                'duplicate_tracking' => $duplicate,
                'ambiguous_order' => false,
                'ambiguous_shipment' => $ambiguousShipment,
            ],
            'evidence' => [
                'tracking_number' => $tracking,
                'tracking_exact' => $tracking !== null,
                'warehouse_id' => null,
                'warehouse_approved' => false,
                'carrier' => $package['carrier'] ?? null,
                'service' => null,
                'carrier_service_approved' => false,
                'shipped_at' => $package['ship_date'] ?? null,
                'shipped_at_source_backed' => $this->hasValue($package['ship_date'] ?? null),
                'source_provider_slug' => $package['provider_slug'] ?? null,
                'source_line_items' => $package['line_items'] ?? [],
            ],
        ];
    }

    /** @param array<string,mixed> $order @return array<string,mixed> */
    private function order(array $order): array
    {
        return [
            'id' => (int) ($order['id'] ?? 0),
            'erp_order_id' => trim((string) ($order['erp_order_id'] ?? '')),
            'order_kind' => trim((string) ($order['order_kind'] ?? '')),
            'order_status' => trim((string) ($order['order_status'] ?? '')),
        ];
    }

    /** @return list<string> */
    private function trackingList(array $context, string $key): array
    {
        $values = is_array($context[$key] ?? null) ? $context[$key] : [];

        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $value): string => strtoupper(trim((string) $value)),
            $values,
        ))));
    }

    private function canonicalCarrier(string $slug): ?string
    {
        if ($slug === '') {
            return null;
        }
        foreach ($this->carrierCatalog() as $carrier) {
            if (! is_array($carrier)) {
                continue;
            }
            $value = trim((string) ($carrier['value'] ?? ''));
            $aftership = strtolower(trim((string) ($carrier['aftership_slug'] ?? '')));
            $aliases = is_array($carrier['aliases'] ?? null) ? $carrier['aliases'] : [];
            $normalizedAliases = array_map(static fn (mixed $alias): string => strtolower(trim((string) $alias)), $aliases);
            if ($aftership === $slug || in_array($slug, $normalizedAliases, true)) {
                return $value !== '' ? $value : null;
            }
        }

        return null;
    }

    /** @return list<array<string,mixed>> */
    private function carrierCatalog(): array
    {
        if ($this->carrierCatalog !== null) {
            return $this->carrierCatalog;
        }
        $configured = config('erp_logistics.carriers', []);

        return is_array($configured) ? array_values($configured) : [];
    }

    /** @return list<array{id:int,quantity:int}> */
    private function lineItems(mixed $lineItems): array
    {
        if (! is_array($lineItems)) {
            return [];
        }
        $result = [];
        foreach ($lineItems as $item) {
            if (! is_array($item)) {
                continue;
            }
            $id = (int) ($item['id'] ?? $item['line_item_id'] ?? 0);
            $quantity = (int) ($item['quantity'] ?? $item['qty'] ?? 0);
            if ($id <= 0 || $quantity <= 0) {
                continue;
            }
            $result[] = ['id' => $id, 'quantity' => $quantity];
        }

        return $result;
    }

    /** @param array<string,mixed> $item */
    private function shipDate(array $item): ?string
    {
        $additional = is_array($item['additional_fields'] ?? null) ? $item['additional_fields'] : [];
        $date = trim((string) ($additional['ship_date'] ?? ''));

        return $date === '' ? null : $date;
    }

    private function hasValue(mixed $value): bool
    {
        return trim((string) ($value ?? '')) !== '';
    }
}
