<?php

namespace App\Modules\Logistics\Application;

final class HistoricalRecoveredCarrierServiceEvidenceAdapter
{
    /** @param array<string,mixed> $candidate @param array<string,mixed> $row @return array<string,mixed> */
    public function enrichActualShipVia(array $candidate, array $row): array
    {
        if (($row['evidence_kind'] ?? null) !== 'actual_ship_via') {
            return $candidate;
        }

        $service = trim((string) ($row['service'] ?? ''));
        if (! $this->isExplicitService($service)) {
            return $candidate;
        }

        return $this->enrichSource(
            $candidate,
            $row,
            'us_warehouse_actual_ship_via',
            'source_us_warehouse_actual_ship_via',
            'Ship Via'
        );
    }

    /** @param array<string,mixed> $candidate @param array<string,mixed> $row @return array<string,mixed> */
    public function enrichEastOutboundInvoice(array $candidate, array $row): array
    {
        if (($row['evidence_kind'] ?? null) !== 'east_outbound_invoice') {
            return $candidate;
        }

        $wooId = $this->candidateWooId($candidate);
        $sourceWooId = trim((string) ($row['woo_id'] ?? ''));
        $orderReference = strtoupper(trim((string) ($row['order_reference'] ?? '')));
        if ($wooId === null || $sourceWooId !== $wooId || $this->normalizeOrderReference($orderReference) !== $wooId) {
            return $candidate;
        }

        $service = trim((string) ($row['service'] ?? $row['raw_service'] ?? ''));
        if (! $this->isExplicitService($service)) {
            return $candidate;
        }
        $row['service'] = $service;

        return $this->enrichSource(
            $candidate,
            $row,
            'us_east_outbound_invoice',
            'source_us_east_outbound_invoice',
            'Outbound Service'
        );
    }

    /** @param array<string,mixed> $candidate @param array<string,mixed> $row @return array<string,mixed> */
    private function enrichSource(array $candidate, array $row, string $sourceTag, string $sourceField, string $serviceField): array
    {
        if (($candidate['identity']['exact'] ?? false) !== true) {
            return $candidate;
        }

        $tracking = strtoupper(trim((string) data_get($candidate, 'evidence.tracking_number', '')));
        $sourceTracking = strtoupper(trim((string) ($row['tracking_number'] ?? '')));
        if ($tracking === '' || $tracking !== $sourceTracking) {
            return $candidate;
        }

        $sourceCarrier = trim((string) ($row['carrier'] ?? ''));
        $sourceService = trim((string) ($row['service'] ?? ''));
        if ($sourceCarrier === '' || $sourceService === '') {
            return $candidate;
        }

        $candidate = $this->withSource($candidate, $sourceTag);
        $evidence = is_array($candidate['evidence'] ?? null) ? $candidate['evidence'] : [];
        $existingCarrier = trim((string) ($evidence['carrier'] ?? ''));
        $existingService = trim((string) ($evidence['service'] ?? ''));
        $approved = ($evidence['carrier_service_approved'] ?? false) === true;
        $carrierConflict = $existingCarrier !== '' && strcasecmp($existingCarrier, $sourceCarrier) !== 0;
        $serviceConflict = $approved && $existingService !== '' && strcasecmp($existingService, $sourceService) !== 0;

        $evidence[$sourceField] = $this->sourcePayload($row, $sourceTracking, $sourceCarrier, $sourceService, $serviceField);
        if (! $carrierConflict && ! $serviceConflict) {
            $evidence['carrier'] = $sourceCarrier;
            $evidence['service'] = $sourceService;
            $evidence['carrier_service_approved'] = true;
        }
        $candidate['evidence'] = $evidence;
        if ($carrierConflict || $serviceConflict) {
            $candidate = $this->sourceConflict($candidate);
        }

        return $candidate;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function sourcePayload(array $row, string $tracking, string $carrier, string $service, string $serviceField): array
    {
        return [
            'tracking_number' => $tracking,
            'carrier' => $carrier,
            'service' => $service,
            'service_source_field' => $serviceField,
            'source_count' => (int) ($row['source_count'] ?? 1),
            'projection_sha256' => trim((string) ($row['projection_sha256'] ?? '')),
            'evidence_rows' => $row['evidence_rows'] ?? [],
            'evidence_sha256' => $this->evidenceHash($row),
        ];
    }

    /** @param array<string,mixed> $candidate */
    private function candidateWooId(array $candidate): ?string
    {
        if (preg_match('/^woocommerce:(\d+):/', (string) ($candidate['candidate_key'] ?? ''), $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    private function normalizeOrderReference(string $value): ?string
    {
        if (preg_match('/^C(\d+)(?:-007)?$/', $value, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    private function isExplicitService(string $service): bool
    {
        $normalized = strtolower(trim($service));
        if ($normalized === '') {
            return false;
        }

        $rejected = [
            'selected by warehouse',
            'no shipping service request',
            'upload shipping label',
            '上传物流面单(upload_shipping_label)',
            '上传物流面单',
        ];
        if (in_array($normalized, $rejected, true)) {
            return false;
        }

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $normalized) !== 1;
    }

    /** @param array<string,mixed> $candidate */
    private function withSource(array $candidate, string $source): array
    {
        $current = trim((string) ($candidate['evidence_source'] ?? ''));
        $sources = array_values(array_filter(explode('+', $current), static fn (string $value): bool => $value !== ''));
        if (! in_array($source, $sources, true)) {
            $sources[] = $source;
        }
        $candidate['evidence_source'] = implode('+', $sources);

        return $candidate;
    }

    /** @param array<string,mixed> $candidate @return array<string,mixed> */
    private function sourceConflict(array $candidate): array
    {
        $conflicts = is_array($candidate['conflicts'] ?? null) ? $candidate['conflicts'] : [];
        $conflicts['source_evidence_conflict'] = true;
        $candidate['conflicts'] = $conflicts;

        return $candidate;
    }

    /** @param array<string,mixed> $row */
    private function evidenceHash(array $row): string
    {
        $normalized = [
            'tracking_number' => strtoupper(trim((string) ($row['tracking_number'] ?? ''))),
            'carrier' => trim((string) ($row['carrier'] ?? '')),
            'service' => trim((string) ($row['service'] ?? $row['raw_service'] ?? '')),
            'source_count' => (int) ($row['source_count'] ?? 1),
            'evidence_rows' => $row['evidence_rows'] ?? [],
        ];

        return hash('sha256', json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
}
