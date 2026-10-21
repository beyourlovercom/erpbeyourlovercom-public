<?php

namespace App\Modules\Logistics\Application;

use InvalidArgumentException;

final class HistoricalFulfillmentReconciliationClassifier
{
    public const READY = 'CUSTOMER_READY_BACKFILL';

    public const ALREADY_CANONICAL = 'ALREADY_CANONICAL';

    public const HISTORICAL_NON_CUSTOMER = 'HISTORICAL_NON_CUSTOMER';

    public const MISSING_EVIDENCE = 'CUSTOMER_REVIEW_MISSING_EVIDENCE';

    public const CONFLICT = 'CUSTOMER_REVIEW_CONFLICT';

    public const ORPHAN = 'ORPHAN_EVIDENCE';

    public const AMBIGUOUS = 'DUPLICATE_OR_AMBIGUOUS';

    private const COMPATIBLE_STATUSES = ['shipped', 'completed', 'completed_delivered'];

    /** @param array<string,mixed> $candidate @return array<string,mixed> */
    public function classify(array $candidate): array
    {
        $candidateKey = trim((string) ($candidate['candidate_key'] ?? ''));
        $source = trim((string) ($candidate['evidence_source'] ?? ''));
        if ($candidateKey === '' || $source === '') {
            throw new InvalidArgumentException('Historical fulfillment candidate key and evidence source are required.');
        }

        $order = is_array($candidate['order'] ?? null) ? $candidate['order'] : null;
        $identity = is_array($candidate['identity'] ?? null) ? $candidate['identity'] : [];
        $evidence = is_array($candidate['evidence'] ?? null) ? $candidate['evidence'] : [];
        $conflicts = is_array($candidate['conflicts'] ?? null) ? $candidate['conflicts'] : [];
        $attempts = is_array($candidate['attempts'] ?? null) ? $candidate['attempts'] : [];
        $existing = is_array($candidate['existing_shipment'] ?? null) ? $candidate['existing_shipment'] : null;

        if (($candidate['historical_non_customer'] ?? false) === true) {
            return $this->result($candidateKey, $source, $order, self::HISTORICAL_NON_CUSTOMER, 'skip', ['historical_non_customer'], $evidence);
        }

        $candidateOrderIds = array_values(array_unique(array_map('intval', is_array($identity['candidate_order_ids'] ?? null) ? $identity['candidate_order_ids'] : [])));
        if (($conflicts['duplicate_tracking'] ?? false) === true
            || ($conflicts['ambiguous_order'] ?? false) === true
            || ($conflicts['ambiguous_shipment'] ?? false) === true
            || count($candidateOrderIds) > 1) {
            return $this->result($candidateKey, $source, $order, self::AMBIGUOUS, 'review', ['duplicate_or_ambiguous_mapping'], $evidence);
        }

        if ($order === null) {
            return $this->result($candidateKey, $source, null, self::ORPHAN, 'review', ['canonical_order_not_found'], $evidence);
        }

        if (($order['order_kind'] ?? null) !== 'customer') {
            return $this->result($candidateKey, $source, $order, self::HISTORICAL_NON_CUSTOMER, 'skip', ['canonical_order_not_customer'], $evidence);
        }

        $conflictReasons = $this->conflictReasons($order, $identity, $existing, $attempts, $evidence, $conflicts);
        if ($conflictReasons !== []) {
            return $this->result($candidateKey, $source, $order, self::CONFLICT, 'review', $conflictReasons, $evidence);
        }
        if ($existing !== null) {
            return $this->result($candidateKey, $source, $order, self::ALREADY_CANONICAL, 'skip', ['canonical_shipment_agrees'], $evidence);
        }

        $missingReasons = $this->missingReasons($order, $identity, $evidence);
        if ($missingReasons !== []) {
            return $this->result($candidateKey, $source, $order, self::MISSING_EVIDENCE, 'review', $missingReasons, $evidence);
        }

        return $this->result($candidateKey, $source, $order, self::READY, 'ready', ['all_required_exact_evidence_present'], $evidence);
    }

    /** @param array<string,mixed> $order @param array<string,mixed> $identity @param array<string,mixed>|null $existing @param array<string,mixed> $attempts @param array<string,mixed> $evidence @param array<string,mixed> $conflicts @return list<string> */
    private function conflictReasons(array $order, array $identity, ?array $existing, array $attempts, array $evidence, array $conflicts): array
    {
        $reasons = [];
        $candidateIds = array_values(array_unique(array_map('intval', is_array($identity['candidate_order_ids'] ?? null) ? $identity['candidate_order_ids'] : [])));
        if (count($candidateIds) === 1 && (int) $candidateIds[0] !== (int) ($order['id'] ?? 0)) {
            $reasons[] = 'order_identity_conflict';
        }
        if (! in_array((string) ($order['order_status'] ?? ''), self::COMPATIBLE_STATUSES, true)) {
            $reasons[] = 'order_status_incompatible';
        }
        if (($conflicts['tracking_collision'] ?? false) === true) {
            $reasons[] = 'tracking_collision';
        }
        if (($conflicts['source_evidence_conflict'] ?? false) === true) {
            $reasons[] = 'source_evidence_conflict';
        }
        if (($attempts['contradictory'] ?? false) === true) {
            $reasons[] = 'contradictory_shipment_attempt';
        }
        if ($existing !== null && ! $this->existingAgrees($existing, $evidence)) {
            $reasons[] = 'existing_shipment_conflict';
        }

        return $reasons;
    }

    /** @param array<string,mixed> $order @param array<string,mixed> $identity @param array<string,mixed> $evidence @return list<string> */
    private function missingReasons(array $order, array $identity, array $evidence): array
    {
        $reasons = [];
        $candidateIds = array_values(array_unique(array_map('intval', is_array($identity['candidate_order_ids'] ?? null) ? $identity['candidate_order_ids'] : [])));
        if (($identity['exact'] ?? false) !== true || count($candidateIds) !== 1 || (int) $candidateIds[0] !== (int) ($order['id'] ?? 0)) {
            $reasons[] = 'missing_exact_order_identity';
        }
        if (trim((string) ($evidence['tracking_number'] ?? '')) === '' || ($evidence['tracking_exact'] ?? false) !== true) {
            $reasons[] = 'missing_exact_tracking';
        }
        if ((int) ($evidence['warehouse_id'] ?? 0) <= 0 || ($evidence['warehouse_approved'] ?? false) !== true) {
            $reasons[] = 'missing_approved_warehouse';
        }
        if (trim((string) ($evidence['carrier'] ?? '')) === ''
            || trim((string) ($evidence['service'] ?? '')) === ''
            || ($evidence['carrier_service_approved'] ?? false) !== true) {
            $reasons[] = 'missing_approved_carrier_service';
        }
        if (trim((string) ($evidence['shipped_at'] ?? '')) === '' || ($evidence['shipped_at_source_backed'] ?? false) !== true) {
            $reasons[] = 'missing_source_backed_shipped_at';
        }
        if (! in_array((string) ($order['order_status'] ?? ''), self::COMPATIBLE_STATUSES, true)) {
            $reasons[] = 'order_status_not_shipped_compatible';
        }

        return array_values(array_unique($reasons));
    }

    /** @param array<string,mixed> $existing @param array<string,mixed> $evidence */
    private function existingAgrees(array $existing, array $evidence): bool
    {
        $checks = [
            ['tracking_number', 'tracking_number', ($evidence['tracking_exact'] ?? false) === true],
            ['warehouse_id', 'warehouse_id', ($evidence['warehouse_approved'] ?? false) === true],
            ['carrier', 'carrier', ($evidence['carrier_service_approved'] ?? false) === true],
            ['service', 'service', ($evidence['carrier_service_approved'] ?? false) === true],
        ];
        foreach ($checks as [$evidenceKey, $existingKey, $authoritative]) {
            if (! $authoritative) {
                continue;
            }
            $value = $evidence[$evidenceKey] ?? null;
            if ($value === null || $value === '') {
                continue;
            }
            if ((string) ($existing[$existingKey] ?? '') !== (string) $value) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string,mixed>|null $order @param list<string> $reasonCodes @param array<string,mixed> $evidence @return array<string,mixed> */
    private function result(string $candidateKey, string $source, ?array $order, string $classification, string $decision, array $reasonCodes, array $evidence): array
    {
        return [
            'candidate_key' => $candidateKey,
            'order_identity' => $order === null ? null : [
                'id' => (int) ($order['id'] ?? 0),
                'erp_order_id' => trim((string) ($order['erp_order_id'] ?? '')) ?: null,
            ],
            'evidence_source' => $source,
            'classification' => $classification,
            'reason_codes' => array_values(array_unique($reasonCodes)),
            'decision' => $decision,
            'proposed_shipment' => [
                'tracking_number' => $this->nullableString($evidence['tracking_number'] ?? null),
                'warehouse_id' => ($warehouse = (int) ($evidence['warehouse_id'] ?? 0)) > 0 ? $warehouse : null,
                'carrier' => $this->nullableString($evidence['carrier'] ?? null),
                'service' => $this->nullableString($evidence['service'] ?? null),
                'shipped_at' => $this->nullableString($evidence['shipped_at'] ?? null),
            ],
        ];
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }
}
