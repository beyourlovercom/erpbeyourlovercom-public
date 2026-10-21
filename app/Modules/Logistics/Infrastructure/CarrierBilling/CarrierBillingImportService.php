<?php

namespace App\Modules\Logistics\Infrastructure\CarrierBilling;

use App\Modules\Audit\Application\AuditActor;
use App\Modules\Audit\Application\AuditSource;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Logistics\Application\CarrierBilling\CarrierBillingFileReader;
use App\Modules\Logistics\Infrastructure\Models\CarrierBillingImportBatch;
use App\Modules\Logistics\Infrastructure\Models\CarrierBillingImportRow;
use App\Modules\Logistics\Infrastructure\Models\Shipment;
use App\Modules\Logistics\Infrastructure\Models\ShipmentCarrierBilling;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

final class CarrierBillingImportService
{
    private const ISO_CURRENCIES = [
        'AED','AFN','ALL','AMD','ANG','AOA','ARS','AUD','AWG','AZN','BAM','BBD','BDT','BGN','BHD','BIF','BMD','BND','BOB','BOV','BRL','BSD','BTN','BWP','BYN','BZD','CAD','CDF','CHE','CHF','CHW','CLF','CLP','CNY','COP','COU','CRC','CUC','CUP','CVE','CZK','DJF','DKK','DOP','DZD','EGP','ERN','ETB','EUR','FJD','FKP','GBP','GEL','GHS','GIP','GMD','GNF','GTQ','GYD','HKD','HNL','HTG','HUF','IDR','ILS','INR','IQD','IRR','ISK','JMD','JOD','JPY','KES','KGS','KHR','KMF','KPW','KRW','KWD','KYD','KZT','LAK','LBP','LKR','LRD','LSL','LYD','MAD','MDL','MGA','MKD','MMK','MNT','MOP','MRU','MUR','MVR','MWK','MXN','MXV','MYR','MZN','NAD','NGN','NIO','NOK','NPR','NZD','OMR','PAB','PEN','PGK','PHP','PKR','PLN','PYG','QAR','RON','RSD','RUB','RWF','SAR','SBD','SCR','SDG','SEK','SGD','SHP','SLE','SLL','SOS','SRD','SSP','STN','SVC','SYP','SZL','THB','TJS','TMT','TND','TOP','TRY','TTD','TWD','TZS','UAH','UGX','USD','USN','UYI','UYU','UYW','UZS','VED','VES','VND','VUV','WST','XAF','XAG','XAU','XBA','XBB','XBC','XBD','XCD','XDR','XOF','XPD','XPF','XPT','XSU','XTS','XUA','XXX','YER','ZAR','ZMW','ZWL',
    ];

    public function __construct(
        private readonly CarrierBillingFileReader $reader,
        private readonly AuditRecorder $audit,
    ) {}

    /** @return array<string,mixed> */
    public function preview(string $path, string $provider): array
    {
        $provider = $this->provider($provider);
        $checksum = hash_file('sha256', $path);
        if (! is_string($checksum)) throw new InvalidArgumentException('Unable to checksum carrier bill source.');
        $already = CarrierBillingImportBatch::query()->where('provider', $provider)->where('source_checksum', $checksum)->first();
        if ($already !== null) {
            return [
                'mode' => 'dry_run', 'provider' => $provider, 'original_filename' => (string) $already->original_filename,
                'source_checksum' => $checksum, 'parser_version' => (string) $already->parser_version,
                'plan_checksum' => (string) $already->plan_checksum, 'total_rows' => (int) $already->total_rows,
                'ready_count' => (int) $already->ready_count, 'review_count' => (int) $already->review_count,
                'skip_count' => (int) $already->skip_count, 'rows' => [], 'groups' => [],
                'already_applied' => true, 'batch_id' => (int) $already->id,
            ];
        }
        $rows = $this->reader->read($path);
        $planned = $this->planRows($rows, $checksum);
        $manifest = array_map(fn (array $g): array => $this->manifestGroup($g), $planned['groups']);
        usort($manifest, fn (array $a, array $b): int => strcmp((string) $a['tracking_number'], (string) $b['tracking_number']));
        $planChecksum = hash('sha256', json_encode([
            'version' => CarrierBillingFileReader::PARSER_VERSION,
            'provider' => $provider,
            'source_checksum' => $checksum,
            'groups' => $manifest,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return [
            'mode' => 'dry_run',
            'provider' => $provider,
            'original_filename' => basename($path),
            'source_checksum' => $checksum,
            'parser_version' => CarrierBillingFileReader::PARSER_VERSION,
            'plan_checksum' => $planChecksum,
            'total_rows' => count($planned['rows']),
            'ready_count' => $planned['counts']['ready'],
            'review_count' => $planned['counts']['review'],
            'skip_count' => $planned['counts']['skip'],
            'rows' => $planned['rows'],
            'groups' => $planned['groups'],
        ];
    }

    /** @return array<string,mixed> */
    public function apply(string $path, string $provider): array
    {
        $provider = $this->provider($provider);
        $checksum = hash_file('sha256', $path);
        if (! is_string($checksum)) throw new InvalidArgumentException('Unable to checksum carrier bill source.');
        $already = CarrierBillingImportBatch::query()->where('provider', $provider)->where('source_checksum', $checksum)->first();
        if ($already !== null) {
            return [
                'mode' => 'apply', 'provider' => $provider, 'original_filename' => (string) $already->original_filename,
                'source_checksum' => $checksum, 'parser_version' => (string) $already->parser_version,
                'plan_checksum' => (string) $already->plan_checksum, 'total_rows' => (int) $already->total_rows,
                'ready_count' => (int) $already->ready_count, 'review_count' => (int) $already->review_count,
                'skip_count' => (int) $already->skip_count, 'rows' => [], 'groups' => [],
                'already_applied' => true, 'batch_id' => (int) $already->id, 'billing_created' => 0,
            ];
        }

        $preview = $this->preview($path, $provider);
        return DB::transaction(function () use ($preview): array {
            $existing = CarrierBillingImportBatch::query()
                ->where('provider', $preview['provider'])
                ->where('source_checksum', $preview['source_checksum'])
                ->lockForUpdate()->first();
            if ($existing !== null) throw new RuntimeException('carrier_billing_import_concurrent_duplicate');

            $batch = CarrierBillingImportBatch::query()->create([
                'provider' => $preview['provider'],
                'original_filename' => $preview['original_filename'],
                'source_checksum' => $preview['source_checksum'],
                'parser_version' => $preview['parser_version'],
                'plan_checksum' => $preview['plan_checksum'],
                'status' => 'applied',
                'total_rows' => $preview['total_rows'],
                'ready_count' => $preview['ready_count'],
                'review_count' => $preview['review_count'],
                'skip_count' => $preview['skip_count'],
                'applied_at' => now(),
            ]);

            $billingByTracking = [];
            $created = 0;
            foreach ($preview['groups'] as $group) {
                if ($group['decision'] === 'ready') {
                    $shipment = Shipment::query()->lockForUpdate()->findOrFail($group['shipment_id']);
                    $current = $shipment->billingEvidence()->lockForUpdate()->first();
                    if ($current !== null) throw new RuntimeException('carrier_billing_conflict_after_preflight');
                    $billing = ShipmentCarrierBilling::query()->create([
                        'shipment_id' => $shipment->id,
                        'chargeable_weight_kg' => $group['chargeable_weight_kg'],
                        'actual_cost' => $group['actual_cost'],
                        'currency' => $group['currency'],
                        'billing_document_number' => $group['billing_document_number'],
                        'provider_reference' => $group['provider_reference'],
                        'delivery_status' => $group['delivery_status'],
                        'data_source' => 'carrier_bill_import:'.$preview['provider'],
                        'billed_at' => $group['billed_at'],
                        'raw_evidence' => [
                            'import_batch_id' => (int) $batch->id,
                            'provider' => $preview['provider'],
                            'source_checksum' => $preview['source_checksum'],
                            'plan_checksum' => $preview['plan_checksum'],
                            'parser_version' => $preview['parser_version'],
                            'source_row_numbers' => $group['source_row_numbers'],
                            'row_hashes' => $group['row_hashes'],
                            'charge_descriptions' => $group['charge_descriptions'],
                        ],
                    ]);
                    $billingByTracking[$group['tracking_number']] = $billing;
                    $created++;
                } elseif ($group['decision'] === 'skip' && isset($group['billing_id'])) {
                    $billingByTracking[$group['tracking_number']] = ShipmentCarrierBilling::query()->find($group['billing_id']);
                }
            }

            foreach ($preview['rows'] as $row) {
                $billing = $billingByTracking[$row['tracking_number']] ?? null;
                CarrierBillingImportRow::query()->create([
                    'batch_id' => $batch->id,
                    'source_row_number' => $row['source_row_number'],
                    'tracking_number' => $row['tracking_number'],
                    'shipment_id' => $row['shipment_id'],
                    'billing_id' => $billing?->id,
                    'decision' => $row['decision'],
                    'reason_code' => $row['reason_code'],
                    'actual_cost' => $row['actual_cost'],
                    'currency' => $row['currency'],
                    'chargeable_weight_kg' => $row['chargeable_weight_kg'],
                    'billing_document_number' => $row['billing_document_number'],
                    'provider_reference' => $row['provider_reference'],
                    'billed_at' => $row['billed_at'],
                    'row_hash' => $row['row_hash'],
                    'raw_data' => $row['raw_data'],
                    'normalized_data' => $row['normalized_data'],
                ]);
            }

            $this->audit->record(
                AuditActor::system(AuditSource::InternalSystem),
                'logistics.carrier_billing_import.applied',
                CarrierBillingImportBatch::class,
                $batch->id,
                after: [
                    'provider' => $preview['provider'], 'source_checksum' => $preview['source_checksum'],
                    'plan_checksum' => $preview['plan_checksum'], 'ready_count' => $preview['ready_count'],
                    'review_count' => $preview['review_count'], 'skip_count' => $preview['skip_count'],
                    'billing_created' => $created,
                ],
                reason: 'carrier_billing_import',
            );

            return $preview + ['mode' => 'apply', 'already_applied' => false, 'batch_id' => (int) $batch->id, 'billing_created' => $created];
        });
    }

    /** @param list<array{source_row_number:int,raw:array<string,?string>,values:array<string,?string>}> $sourceRows @return array<string,mixed> */
    private function planRows(array $sourceRows, string $checksum): array
    {
        $normalized = [];
        foreach ($sourceRows as $source) $normalized[] = $this->normalizeRow($source, $checksum);
        $shipmentMap = $this->shipmentMap();
        $grouped = [];
        foreach ($normalized as $index => $row) {
            if ($row['preflight_reason'] !== null || $row['tracking_number'] === null) continue;
            $grouped[$row['tracking_number']][] = $index;
        }

        $groups = [];
        foreach ($grouped as $tracking => $indexes) {
            $rows = array_map(fn (int $i): array => $normalized[$i], $indexes);
            $group = $this->classifyGroup($tracking, $rows, $shipmentMap[$tracking] ?? []);
            $groups[] = $group;
            foreach ($indexes as $index) {
                $normalized[$index]['decision'] = $group['decision'];
                $normalized[$index]['reason_code'] = $group['reason_code'];
                $normalized[$index]['shipment_id'] = $group['shipment_id'];
            }
        }
        foreach ($normalized as &$row) {
            if ($row['decision'] !== null) continue;
            $row['decision'] = 'review';
            $row['reason_code'] = $row['preflight_reason'] ?? 'invalid_row';
        }
        unset($row);

        $counts = ['ready' => 0, 'review' => 0, 'skip' => 0];
        foreach ($normalized as $row) $counts[$row['decision']]++;
        return ['rows' => $normalized, 'groups' => $groups, 'counts' => $counts];
    }

    /** @return array<string,list<Shipment>> */
    private function shipmentMap(): array
    {
        $map = [];
        foreach (Shipment::query()->whereNotNull('tracking_number')->get(['id', 'tracking_number']) as $shipment) {
            $tracking = $this->tracking((string) $shipment->tracking_number);
            if ($tracking !== '') $map[$tracking][] = $shipment;
        }
        return $map;
    }

    /** @param list<array<string,mixed>> $rows @param list<Shipment> $shipments @return array<string,mixed> */
    private function classifyGroup(string $tracking, array $rows, array $shipments): array
    {
        $base = [
            'tracking_number' => $tracking, 'source_row_numbers' => array_column($rows, 'source_row_number'),
            'row_hashes' => array_column($rows, 'row_hash'), 'shipment_id' => null, 'billing_id' => null,
            'actual_cost' => null, 'currency' => null, 'chargeable_weight_kg' => null,
            'billing_document_number' => null, 'provider_reference' => null, 'billed_at' => null,
            'delivery_status' => null, 'carrier' => null, 'charge_descriptions' => [],
        ];
        if (count($shipments) === 0) return $base + ['decision' => 'review', 'reason_code' => 'shipment_not_found'];
        if (count($shipments) !== 1) return $base + ['decision' => 'review', 'reason_code' => 'shipment_tracking_ambiguous'];
        $shipment = $shipments[0];
        $base['shipment_id'] = (int) $shipment->id;

        foreach (['currency', 'chargeable_weight_kg', 'billing_document_number', 'provider_reference', 'billed_at', 'delivery_status', 'carrier'] as $field) {
            $values = array_values(array_unique(array_filter(array_column($rows, $field), fn ($v): bool => $v !== null && $v !== '')));
            if (count($values) > 1) return $base + ['decision' => 'review', 'reason_code' => 'conflicting_group_'.$field];
            $base[$field] = $values[0] ?? null;
        }
        $total = '0.0000';
        foreach ($rows as $row) $total = bcadd($total, (string) $row['actual_cost'], 4);
        $base['actual_cost'] = $total;
        $base['charge_descriptions'] = array_values(array_unique(array_filter(array_column($rows, 'charge_description'))));

        $existing = ShipmentCarrierBilling::query()->where('shipment_id', $shipment->id)->first();
        if ($existing !== null) {
            $base['billing_id'] = (int) $existing->id;
            if ($this->sameBilling($existing, $base)) return $base + ['decision' => 'skip', 'reason_code' => 'already_applied'];
            return $base + ['decision' => 'review', 'reason_code' => 'existing_billing_conflict'];
        }
        return $base + ['decision' => 'ready', 'reason_code' => null];
    }

    /** @param array<string,mixed> $source */
    private function normalizeRow(array $source, string $checksum): array
    {
        $values = $source['values'];
        $tracking = $this->tracking((string) ($values['tracking_number'] ?? ''));
        $reason = null;
        if ($tracking === '') $reason = 'tracking_missing';
        $money = $this->money($values['actual_cost'] ?? null);
        if ($money === null) $reason ??= 'actual_cost_invalid';
        $currency = $this->currency($values['currency'] ?? null);
        if ($currency === null) $reason ??= 'currency_invalid';
        $weight = $this->weight($values['chargeable_weight_kg'] ?? null);
        if (($values['chargeable_weight_kg'] ?? null) !== null && $weight === null) $reason ??= 'chargeable_weight_invalid';
        $billedAt = $this->date($values['billed_at'] ?? null);
        if (($values['billed_at'] ?? null) !== null && $billedAt === null) $reason ??= 'billed_at_invalid';

        $normalizedData = [
            'tracking_number' => $tracking !== '' ? $tracking : null,
            'actual_cost' => $money,
            'currency' => $currency,
            'chargeable_weight_kg' => $weight,
            'billing_document_number' => $this->text($values['billing_document_number'] ?? null, 128),
            'provider_reference' => $this->text($values['provider_reference'] ?? null, 128),
            'billed_at' => $billedAt,
            'delivery_status' => $this->text($values['delivery_status'] ?? null, 32),
            'carrier' => $this->text($values['carrier'] ?? null, 128),
            'charge_description' => $this->text($values['charge_description'] ?? null, 255),
        ];
        $rowHash = hash('sha256', json_encode([
            'source_checksum' => $checksum, 'source_row_number' => (int) $source['source_row_number'], 'normalized' => $normalizedData,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        return $normalizedData + [
            'source_row_number' => (int) $source['source_row_number'], 'shipment_id' => null,
            'decision' => null, 'reason_code' => null, 'preflight_reason' => $reason,
            'row_hash' => $rowHash, 'raw_data' => $source['raw'], 'normalized_data' => $normalizedData,
        ];
    }

    /** @param array<string,mixed> $group */
    private function manifestGroup(array $group): array
    {
        return [
            'tracking_number' => $group['tracking_number'], 'decision' => $group['decision'], 'reason_code' => $group['reason_code'],
            'shipment_id' => $group['shipment_id'], 'billing_id' => $group['billing_id'], 'actual_cost' => $group['actual_cost'],
            'currency' => $group['currency'], 'chargeable_weight_kg' => $group['chargeable_weight_kg'],
            'billing_document_number' => $group['billing_document_number'], 'provider_reference' => $group['provider_reference'],
            'billed_at' => $group['billed_at'], 'delivery_status' => $group['delivery_status'], 'carrier' => $group['carrier'],
            'source_row_numbers' => $group['source_row_numbers'], 'row_hashes' => $group['row_hashes'],
        ];
    }

    private function sameBilling(ShipmentCarrierBilling $existing, array $group): bool
    {
        return (string) $existing->actual_cost === (string) $group['actual_cost']
            && strtoupper((string) $existing->currency) === (string) $group['currency']
            && (string) ($existing->chargeable_weight_kg ?? '') === (string) ($group['chargeable_weight_kg'] ?? '')
            && (string) ($existing->billing_document_number ?? '') === (string) ($group['billing_document_number'] ?? '')
            && (string) ($existing->provider_reference ?? '') === (string) ($group['provider_reference'] ?? '');
    }

    private function provider(string $provider): string
    {
        $provider = strtolower(trim($provider));
        if (preg_match('/^[a-z0-9][a-z0-9._-]{0,31}$/D', $provider) !== 1) throw new InvalidArgumentException('Carrier bill provider key is invalid.');
        return $provider;
    }
    private function tracking(string $tracking): string { return strtoupper(trim($tracking)); }
    private function money(?string $value): ?string
    {
        if ($value === null || preg_match('/^\d{1,10}(?:\.\d{1,4})?$/D', trim($value)) !== 1) return null;
        return bcadd(trim($value), '0', 4);
    }
    private function currency(?string $value): ?string
    {
        $value = strtoupper(trim((string) $value));
        return in_array($value, self::ISO_CURRENCIES, true) ? $value : null;
    }
    private function weight(?string $value): ?string
    {
        if ($value === null || trim($value) === '') return null;
        if (preg_match('/^\d{1,8}(?:\.\d{1,3})?$/D', trim($value)) !== 1 || bccomp(trim($value), '0', 3) <= 0) return null;
        return bcadd(trim($value), '0', 3);
    }
    private function date(?string $value): ?string
    {
        if ($value === null || trim($value) === '') return null;
        $value = trim($value);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) === 1) {
            try { return CarbonImmutable::createFromFormat('!Y-m-d', $value, 'UTC')->format('Y-m-d H:i:s'); } catch (\Throwable) { return null; }
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(?::\d{2})?(?:\.\d+)?(?:Z|[+-]\d{2}:?\d{2})?$/D', $value) !== 1) return null;
        try { return CarbonImmutable::parse($value)->utc()->format('Y-m-d H:i:s'); } catch (\Throwable) { return null; }
    }
    private function text(?string $value, int $max): ?string
    {
        $value = trim((string) $value); if ($value === '') return null;
        return mb_strlen($value) <= $max ? $value : mb_substr($value, 0, $max);
    }
}
