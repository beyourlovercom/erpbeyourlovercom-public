<?php

namespace App\Modules\Logistics\Application;

final class HistoricalCarrierServiceInvoiceEvidenceAdapter
{
    /** @param array<string,mixed> $candidate @param array<string,mixed> $row @return array<string,mixed> */
    public function enrich(array $candidate, array $row): array
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
        $candidate = $this->withSource($candidate, 'us_warehouse_invoice');
        $evidence = is_array($candidate['evidence'] ?? null) ? $candidate['evidence'] : [];
        $existingCarrier = trim((string) ($evidence['carrier'] ?? ''));
        $existingService = trim((string) ($evidence['service'] ?? ''));
        $approved = ($evidence['carrier_service_approved'] ?? false) === true;

        $carrierConflict = $existingCarrier !== '' && strcasecmp($existingCarrier, $sourceCarrier) !== 0;
        $serviceConflict = $approved && $existingService !== '' && strcasecmp($existingService, $sourceService) !== 0;

        $evidence['source_us_warehouse_invoice'] = [
            'tracking_number' => $sourceTracking,
            'carrier' => $sourceCarrier,
            'service' => $sourceService,
            'source_count' => (int) ($row['source_count'] ?? 1),
            'evidence_sha256' => $this->evidenceHash($row),
        ];

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
            'service' => trim((string) ($row['service'] ?? '')),
            'source_count' => (int) ($row['source_count'] ?? 1),
            'evidence_rows' => $row['evidence_rows'] ?? [],
        ];

        return hash('sha256', json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
}
