<?php

namespace App\Modules\Logistics\Application;

final class HistoricalWarehouseEvidenceRecoveryAdapter
{
    /** @var array<string,string> */
    private const SOURCE_WAREHOUSE_CODES = [
        'USA 美西仓' => 'US-LA',
        '美东仓' => 'US-NY',
        'Factory 国内仓' => 'FACTORY',
        'Canada 加拿大仓' => 'CA',
    ];

    /** @param array<string,int> $warehouseIdsByCode */
    public function __construct(private readonly array $warehouseIdsByCode) {}

    /** @param array<string,mixed> $candidate @param array<string,mixed> $row @return array<string,mixed> */
    public function enrichTencentFulfillment(array $candidate, array $row): array
    {
        if (! $this->exactOrder($candidate, $row['单号 Order No.'] ?? null)) {
            return $candidate;
        }

        $tracking = $this->candidateTracking($candidate);
        $sourceTracking = strtoupper(trim((string) ($row['追踪单号 Tracking No.'] ?? '')));
        if ($tracking === '' || $tracking !== $sourceTracking) {
            return $candidate;
        }

        $candidate = $this->withSource($candidate, 'tencent_fulfillment_export');
        $evidence = is_array($candidate['evidence'] ?? null) ? $candidate['evidence'] : [];
        $rawWarehouse = trim((string) ($row['Stock 仓库'] ?? ''));
        $canonicalCode = self::SOURCE_WAREHOUSE_CODES[$rawWarehouse] ?? null;
        $warehouseId = $canonicalCode === null ? null : ($this->warehouseIdsByCode[$canonicalCode] ?? null);

        $evidence['source_tencent_fulfillment'] = [
            'order_number' => trim((string) ($row['单号 Order No.'] ?? '')),
            'tracking_number' => $sourceTracking,
            'raw_warehouse' => $rawWarehouse !== '' ? $rawWarehouse : null,
            'canonical_warehouse_code' => $canonicalCode,
            'status' => $this->nullable($row['Status 进度'] ?? null),
            'carrier' => $this->nullable($row['代理 Carrier'] ?? null),
            'input_time' => $this->nullable($row['Input Time'] ?? null),
            'row_sha256' => $this->rowHash($row),
        ];

        if ($warehouseId !== null) {
            $existingApproved = ($evidence['warehouse_approved'] ?? false) === true;
            $existingWarehouseId = (int) ($evidence['warehouse_id'] ?? 0);
            if ($existingApproved && $existingWarehouseId > 0 && $existingWarehouseId !== $warehouseId) {
                $candidate['evidence'] = $evidence;

                return $this->sourceConflict($candidate);
            }

            $evidence['warehouse_id'] = $warehouseId;
            $evidence['warehouse_approved'] = true;
            $evidence['warehouse_evidence_source'] = 'tencent_fulfillment_export';
        }

        $candidate['evidence'] = $evidence;

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

    /** @param array<string,mixed> $row */
    private function rowHash(array $row): string
    {
        $keys = ['Status 进度', 'Stock 仓库', '代理 Carrier', '追踪单号 Tracking No.', 'Input Time', '单号 Order No.'];
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
