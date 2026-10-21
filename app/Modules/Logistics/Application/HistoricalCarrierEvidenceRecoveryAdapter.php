<?php

namespace App\Modules\Logistics\Application;

final class HistoricalCarrierEvidenceRecoveryAdapter
{
    /** @param array<string,mixed> $candidate @param array<string,mixed> $row @return array<string,mixed> */
    public function enrichSpeeda(array $candidate, array $row): array
    {
        if (! $this->exactOrder($candidate, $row['原单号'] ?? null)) {
            return $candidate;
        }

        $tracking = $this->candidateTracking($candidate);
        $providerTracking = strtoupper(trim((string) ($row['转单号'] ?? '')));
        if ($tracking === '' || $tracking !== $providerTracking) {
            return $candidate;
        }

        $candidate = $this->withSource($candidate, 'speeda_export');
        $evidence = is_array($candidate['evidence'] ?? null) ? $candidate['evidence'] : [];
        $service = trim((string) ($row['运输方式'] ?? ''));
        $existingCarrier = trim((string) ($evidence['carrier'] ?? ''));
        $carrierConflict = $existingCarrier !== '' && strcasecmp($existingCarrier, '4PX') !== 0;

        $evidence['source_speeda'] = [
            'order_number' => trim((string) ($row['原单号'] ?? '')),
            'tracking_number' => $providerTracking,
            'service' => $service !== '' ? $service : null,
            'status' => $this->nullable($row['状态'] ?? null),
            'created_at' => $this->nullable($row['创建时间'] ?? null),
            'shipped_at' => $this->nullable($row['出货时间'] ?? null),
            'row_sha256' => $this->rowHash($row, ['状态', '原单号', '转单号', '运输方式', '创建时间', '出货时间']),
        ];

        if (! $carrierConflict && $service !== '' && str_starts_with($providerTracking, '4PX')) {
            $evidence['carrier'] = '4PX';
            $evidence['service'] = $service;
            $evidence['carrier_service_approved'] = true;
        }
        $candidate['evidence'] = $evidence;
        if ($carrierConflict) {
            $candidate = $this->sourceConflict($candidate);
        }

        return $candidate;
    }

    /** @param array<string,mixed> $candidate @param array<string,mixed> $row @return array<string,mixed> */
    public function enrichWeship(array $candidate, array $row): array
    {
        if (! $this->exactOrder($candidate, $row['客户单号'] ?? null)) {
            return $candidate;
        }

        $tracking = $this->candidateTracking($candidate);
        $waybill = strtoupper(trim((string) ($row['运单号'] ?? '')));
        $providerReference = strtoupper(trim((string) ($row['服务商单号'] ?? '')));
        if ($tracking === '' || ($tracking !== $waybill && $tracking !== $providerReference)) {
            return $candidate;
        }

        $candidate = $this->withSource($candidate, 'weship_export');
        $evidence = is_array($candidate['evidence'] ?? null) ? $candidate['evidence'] : [];
        $existingCarrier = trim((string) ($evidence['carrier'] ?? ''));
        $carrierConflict = $existingCarrier !== '' && strcasecmp($existingCarrier, 'Weship') !== 0;

        $evidence['source_weship'] = [
            'order_number' => trim((string) ($row['客户单号'] ?? '')),
            'waybill_number' => $waybill !== '' ? $waybill : null,
            'provider_reference' => $providerReference !== '' ? $providerReference : null,
            'status' => $this->nullable($row['操作状态'] ?? null),
            'signed_in_at' => $this->nullable($row['签入时间'] ?? null),
            'row_sha256' => $this->rowHash($row, ['客户单号', '运单号', '服务商单号', '操作状态', '签入时间']),
        ];
        if (! $carrierConflict) {
            $evidence['carrier'] = 'Weship';
            $evidence['carrier_service_approved'] = false;
        }
        $candidate['evidence'] = $evidence;
        if ($carrierConflict) {
            $candidate = $this->sourceConflict($candidate);
        }

        return $candidate;
    }

    /** @param array<string,mixed> $candidate */
    private function exactOrder(array $candidate, mixed $providerOrder): bool
    {
        if (($candidate['identity']['exact'] ?? false) !== true) {
            return false;
        }
        $erpOrderId = trim((string) data_get($candidate, 'order.erp_order_id', ''));
        if (preg_match('/-c(\d+)$/i', $erpOrderId, $erp) !== 1) {
            return false;
        }
        $providerOrder = strtoupper(trim((string) $providerOrder));
        if (preg_match('/^C(\d+)(?:-007)?$/D', $providerOrder, $source) !== 1) {
            return false;
        }

        return $erp[1] === $source[1];
    }

    /** @param array<string,mixed> $candidate */
    private function candidateTracking(array $candidate): string
    {
        return strtoupper(trim((string) data_get($candidate, 'evidence.tracking_number', '')));
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

    /** @param array<string,mixed> $row @param list<string> $keys */
    private function rowHash(array $row, array $keys): string
    {
        $normalized = [];
        foreach ($keys as $key) {
            $normalized[$key] = trim((string) ($row[$key] ?? ''));
        }

        return hash('sha256', json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function nullable(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }
}
