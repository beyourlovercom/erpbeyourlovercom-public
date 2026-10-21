<?php

namespace App\Modules\Logistics\Infrastructure;

use App\Modules\Audit\Application\AuditActor;
use App\Modules\Audit\Application\AuditSource;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Logistics\Infrastructure\Models\Shipment;
use App\Modules\Logistics\Infrastructure\Models\ShipmentAttempt;
use App\Modules\Orders\Infrastructure\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

final class CustomerHistoricalShipmentBackfillService
{
    public const PARSER_VERSION = 'customer-historical-shipment-backfill-v1';

    private const REQUIRED = [
        'order_id', 'erp_order_id', 'tracking_number', 'warehouse_code', 'carrier', 'service',
        'shipped_at', 'source_reference', 'provider_reference', 'source_row_number',
    ];

    public function __construct(private readonly AuditRecorder $audit) {}

    /** @return array<string,mixed> */
    public function preview(string $path): array
    {
        $real = realpath($path);
        if ($real === false || ! is_file($real) || ! is_readable($real)) {
            throw new InvalidArgumentException('Backfill source file is not readable.');
        }
        $checksum = hash_file('sha256', $real);
        if (! is_string($checksum)) throw new InvalidArgumentException('Unable to checksum backfill source.');

        $rows = $this->readCsv($real);
        $orderCounts = array_count_values(array_filter(array_column($rows, 'order_id')));
        $trackingCounts = array_count_values(array_filter(array_column($rows, 'tracking_number')));

        foreach ($rows as &$row) {
            $row['decision'] = 'review';
            $row['reason_code'] = $row['preflight_reason'];
            if (($row['preflight_reason'] ?? null) !== null) continue;
            if (($orderCounts[$row['order_id']] ?? 0) !== 1) {
                $row['reason_code'] = 'duplicate_source_order';
                continue;
            }
            if (($trackingCounts[$row['tracking_number']] ?? 0) !== 1) {
                $row['reason_code'] = 'duplicate_source_tracking';
                continue;
            }
            [$decision, $reason, $shipmentId, $warehouseId] = $this->classify($row);
            $row['decision'] = $decision;
            $row['reason_code'] = $reason;
            $row['shipment_id'] = $shipmentId;
            $row['warehouse_id'] = $warehouseId;
        }
        unset($row);

        $manifest = array_map(fn (array $row): array => $this->manifestRow($row), $rows);
        usort($manifest, fn (array $a, array $b): int => $a['source_row_number'] <=> $b['source_row_number']);
        $planChecksum = hash('sha256', json_encode([
            'version' => self::PARSER_VERSION,
            'source_checksum' => $checksum,
            'rows' => $manifest,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        $counts = ['ready' => 0, 'review' => 0, 'skip' => 0];
        foreach ($rows as $row) $counts[$row['decision']]++;

        return [
            'mode' => 'dry_run',
            'original_filename' => basename($real),
            'source_checksum' => $checksum,
            'parser_version' => self::PARSER_VERSION,
            'plan_checksum' => $planChecksum,
            'total_rows' => count($rows),
            'ready_count' => $counts['ready'],
            'review_count' => $counts['review'],
            'skip_count' => $counts['skip'],
            'rows' => $rows,
        ];
    }

    /** @return array<string,mixed> */
    public function apply(string $path): array
    {
        $preview = $this->preview($path);

        return DB::transaction(function () use ($preview): array {
            $created = 0;
            foreach ($preview['rows'] as $row) {
                if ($row['decision'] !== 'ready') continue;

                $order = Order::query()->whereKey($row['order_id'])->lockForUpdate()->first();
                if ($order === null) throw new RuntimeException('backfill_preflight_drift:order_not_found');
                $warehouse = Warehouse::query()->where('code', $row['warehouse_code'])->lockForUpdate()->first();
                if ($warehouse === null) throw new RuntimeException('backfill_preflight_drift:warehouse_not_found');

                [$decision, $reason] = $this->classify($row, true);
                if ($decision !== 'ready') {
                    throw new RuntimeException('backfill_preflight_drift:'.($reason ?? 'unknown'));
                }

                $shipment = $order->shipment()->create([
                    'shipment_status' => 'shipped',
                    'warehouse_id' => $warehouse->id,
                    'warehouse_selection_source' => 'historical_exact',
                    'carrier' => $row['carrier'],
                    'service' => $row['service'],
                    'carrier_selection_source' => 'historical_exact',
                    'tracking_number' => $row['tracking_number'],
                    'shipped_at' => $row['shipped_at'],
                    'logistics_note' => $this->note($preview['source_checksum'], $row),
                    'handled_by' => null,
                ]);

                $this->audit->record(
                    AuditActor::system(AuditSource::InternalSystem),
                    'logistics.customer_historical_shipment_backfill.applied',
                    Shipment::class,
                    $shipment->id,
                    null,
                    [
                        'order_id' => (int) $order->id,
                        'erp_order_id' => (string) $order->erp_order_id,
                        'shipment_id' => (int) $shipment->id,
                        'tracking_number' => $row['tracking_number'],
                        'warehouse_code' => $row['warehouse_code'],
                        'carrier' => $row['carrier'],
                        'service' => $row['service'],
                        'shipped_at' => $row['shipped_at'],
                        'source_checksum' => $preview['source_checksum'],
                        'source_row_number' => $row['source_row_number'],
                        'source_reference' => $row['source_reference'],
                        'provider_reference' => $row['provider_reference'],
                    ],
                    'customer_historical_shipment_backfill',
                );
                $created++;
            }

            return $preview + ['mode' => 'apply', 'shipments_created' => $created];
        });
    }

    /** @return list<array<string,mixed>> */
    private function readCsv(string $path): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) throw new InvalidArgumentException('Backfill CSV cannot be opened.');
        try {
            $headers = fgetcsv($handle);
            if (! is_array($headers)) throw new InvalidArgumentException('Backfill CSV is empty.');
            $headers = array_map(fn ($v): string => trim($this->stripBom((string) $v)), $headers);
            foreach (self::REQUIRED as $required) {
                if (! in_array($required, $headers, true)) {
                    throw new InvalidArgumentException('Backfill CSV is missing required column: '.$required);
                }
            }
            $map = array_flip($headers);
            $rows = [];
            $line = 1;
            while (($cells = fgetcsv($handle)) !== false) {
                $line++;
                if ($line > 1001) throw new InvalidArgumentException('Backfill CSV row count exceeds the limit.');
                if (count(array_filter($cells, fn ($v): bool => trim((string) $v) !== '')) === 0) continue;
                $raw = [];
                foreach (self::REQUIRED as $field) $raw[$field] = trim((string) ($cells[$map[$field]] ?? ''));
                $rows[] = $this->normalizeRow($raw, $line);
            }
            return $rows;
        } finally {
            fclose($handle);
        }
    }

    /** @param array<string,string> $raw @return array<string,mixed> */
    private function normalizeRow(array $raw, int $line): array
    {
        $reason = null;
        if (preg_match('/^\d+$/D', $raw['order_id']) !== 1) $reason = 'order_id_invalid';
        if ($raw['erp_order_id'] === '') $reason ??= 'erp_order_id_missing';
        $tracking = strtoupper(trim($raw['tracking_number']));
        if ($tracking === '' || strlen($tracking) > 128) $reason ??= 'tracking_invalid';
        $warehouse = strtoupper(trim($raw['warehouse_code']));
        if ($warehouse === '') $reason ??= 'warehouse_code_missing';
        $carrier = trim($raw['carrier']);
        $service = trim($raw['service']);
        if ($carrier === '' || strlen($carrier) > 128) $reason ??= 'carrier_invalid';
        if ($service === '' || strlen($service) > 128) $reason ??= 'service_invalid';
        $sourceReference = strtoupper(trim($raw['source_reference']));
        if (str_starts_with($sourceReference, 'KOL')) $reason ??= 'historical_scope_excluded';
        if (! str_starts_with($sourceReference, 'C')) $reason ??= 'source_reference_invalid';
        if (preg_match('/^\d+$/D', $raw['source_row_number']) !== 1) $reason ??= 'source_row_number_invalid';
        $shippedAt = $this->timestamp($raw['shipped_at']);
        if ($shippedAt === null) $reason ??= 'shipped_at_invalid';

        return [
            'input_line' => $line,
            'order_id' => (int) $raw['order_id'],
            'erp_order_id' => $raw['erp_order_id'],
            'tracking_number' => $tracking,
            'warehouse_code' => $warehouse,
            'carrier' => $carrier,
            'service' => $service,
            'shipped_at' => $shippedAt,
            'source_reference' => $sourceReference,
            'provider_reference' => trim($raw['provider_reference']),
            'source_row_number' => (int) $raw['source_row_number'],
            'preflight_reason' => $reason,
            'shipment_id' => null,
            'warehouse_id' => null,
        ];
    }

    /** @param array<string,mixed> $row @return array{0:string,1:?string,2:?int,3:?int} */
    private function classify(array $row, bool $locked = false): array
    {
        $orderQuery = Order::query()->whereKey($row['order_id']);
        $warehouseQuery = Warehouse::query()->where('code', $row['warehouse_code']);
        if ($locked) {
            $orderQuery->lockForUpdate();
            $warehouseQuery->lockForUpdate();
        }
        $order = $orderQuery->first();
        if ($order === null) return ['review', 'order_not_found', null, null];
        if ((string) $order->erp_order_id !== (string) $row['erp_order_id']) return ['review', 'order_identity_mismatch', null, null];
        if ($order->order_kind?->value !== 'customer') return ['review', 'order_kind_not_customer', null, null];
        if ($order->order_status?->value !== 'shipped') return ['review', 'order_status_not_shipped', null, null];

        $warehouse = $warehouseQuery->first();
        if ($warehouse === null) return ['review', 'warehouse_not_found', null, null];
        if (! $warehouse->is_active) return ['review', 'warehouse_inactive', null, (int) $warehouse->id];

        $shipmentQuery = Shipment::query()->where('order_id', $order->id);
        $attemptQuery = ShipmentAttempt::query()->where('order_id', $order->id);
        $trackingQuery = Shipment::query()->where('tracking_number', $row['tracking_number']);
        if ($locked) {
            $shipmentQuery->lockForUpdate();
            $attemptQuery->lockForUpdate();
            $trackingQuery->lockForUpdate();
        }
        $existing = $shipmentQuery->first();
        if ($existing !== null) {
            if ($this->sameShipment($existing, $warehouse, $row)) {
                return ['skip', 'already_applied', (int) $existing->id, (int) $warehouse->id];
            }
            return ['review', 'existing_shipment_conflict', (int) $existing->id, (int) $warehouse->id];
        }
        if ($attemptQuery->exists()) return ['review', 'shipment_attempt_exists', null, (int) $warehouse->id];
        if ($trackingQuery->exists()) return ['review', 'tracking_conflict', null, (int) $warehouse->id];

        return ['ready', null, null, (int) $warehouse->id];
    }

    private function sameShipment(Shipment $shipment, Warehouse $warehouse, array $row): bool
    {
        $existingTime = $shipment->shipped_at?->utc()->format('Y-m-d H:i:s');
        return $shipment->shipment_status === 'shipped'
            && (int) $shipment->warehouse_id === (int) $warehouse->id
            && $shipment->warehouse_selection_source === 'historical_exact'
            && (string) $shipment->carrier === (string) $row['carrier']
            && (string) $shipment->service === (string) $row['service']
            && $shipment->carrier_selection_source === 'historical_exact'
            && strtoupper((string) $shipment->tracking_number) === (string) $row['tracking_number']
            && $existingTime === (string) $row['shipped_at'];
    }

    private function timestamp(string $value): ?string
    {
        $value = trim($value);
        if ($value === '' || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:?\d{2})$/D', $value) !== 1) return null;
        try {
            return CarbonImmutable::parse($value)->utc()->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function manifestRow(array $row): array
    {
        return [
            'source_row_number' => $row['source_row_number'],
            'order_id' => $row['order_id'],
            'erp_order_id' => $row['erp_order_id'],
            'tracking_number' => $row['tracking_number'],
            'warehouse_code' => $row['warehouse_code'],
            'carrier' => $row['carrier'],
            'service' => $row['service'],
            'shipped_at' => $row['shipped_at'],
            'source_reference' => $row['source_reference'],
            'provider_reference' => $row['provider_reference'],
            'decision' => $row['decision'],
            'reason_code' => $row['reason_code'],
            'shipment_id' => $row['shipment_id'],
            'warehouse_id' => $row['warehouse_id'],
        ];
    }

    private function note(string $checksum, array $row): string
    {
        return 'Historical customer shipment backfill; source_sha='.$checksum
            .'; source_row='.$row['source_row_number']
            .'; source_reference='.$row['source_reference']
            .'; provider_reference='.$row['provider_reference'];
    }

    private function stripBom(string $value): string
    {
        return str_starts_with($value, "\xEF\xBB\xBF") ? substr($value, 3) : $value;
    }
}
