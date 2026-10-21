<?php

namespace App\Modules\Finance\Application;

use App\Modules\Audit\Application\AuditActor;
use App\Modules\Audit\Application\AuditSource;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Finance\Application\Contracts\WooFinancialBootstrapOrderStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

final class WooFinancialEvidenceBootstrapService
{
    private const EVIDENCE_FIELDS = [
        'currency', 'merchandise_list_amount', 'discount_amount', 'shipping_revenue', 'tax_collected',
        'provider_order_total', 'refund_amount', 'refund_tax', 'payment_method', 'payment_transaction_id', 'actual_payment_fee', 'actual_payment_fee_source', 'actual_payment_fee_status', 'paid_at', 'provider',
        'provider_reference', 'provider_order_status', 'provider_modified_at', 'reconciliation_status', 'reconciliation_exception',
    ];

    private const LEGACY_REVENUE_FIELDS = [
        'merchandise_list_amount', 'discount_amount', 'shipping_revenue', 'amount_paid', 'refund_amount',
        'tax_collected', 'provider_order_total', 'refund_tax', 'payment_method', 'paid_at',
    ];

    public function __construct(
        private readonly OrderAnalysisService $analysis,
        private readonly AuditRecorder $audit,
        private readonly WooFinancialBootstrapOrderStore $orders,
    ) {}

    /** @return array<string,mixed> */
    public function preview(array $source): array
    {
        if (($source['source_status'] ?? null) !== 'resolved') {
            return [
                'provider_reference' => $source['provider_reference'] ?? null,
                'order_id' => $source['order_id'] ?? null,
                'disposition' => $source['source_status'] ?? 'error',
                'blocker' => $source['blocker'] ?? 'source_not_resolved',
            ];
        }

        $order = $this->findOperationalOrder($source);
        if (! $order) {
            return [
                'provider_reference' => $source['provider_reference'] ?? null,
                'order_id' => $source['order_id'] ?? null,
                'disposition' => 'blocked',
                'blocker' => 'operational_order_not_found',
            ];
        }

        $evidence = is_array($source['settlement_evidence'] ?? null) ? $source['settlement_evidence'] : [];
        $financials = $order->financials()->first();
        $disposition = $this->disposition($financials, $evidence);

        return [
            'provider_reference' => (string) $source['provider_reference'],
            'connection_key' => (string) ($source['connection_key'] ?? ''),
            'order_id' => (int) $order->getKey(),
            'disposition' => $disposition['status'],
            'blocker' => $disposition['blocker'],
            'currency' => $evidence['currency'] ?? null,
            'provider_order_status' => $evidence['provider_order_status'] ?? null,
            'reconciliation_status' => $evidence['reconciliation_status'] ?? null,
            'clear_fx_snapshot' => $financials !== null
                && $financials->currency !== null
                && ($evidence['currency'] ?? null) !== null
                && strtoupper((string) $financials->currency) !== strtoupper((string) $evidence['currency']),
        ];
    }

    /** @return array<string,mixed> */
    public function apply(array $source): array
    {
        return DB::transaction(function () use ($source): array {
            if (($source['source_status'] ?? null) !== 'resolved') {
                return $this->preview($source);
            }

            $order = $this->findOperationalOrder($source, true);
            if (! $order) {
                return [
                    'provider_reference' => $source['provider_reference'] ?? null,
                    'order_id' => $source['order_id'] ?? null,
                    'disposition' => 'blocked',
                    'blocker' => 'operational_order_not_found',
                ];
            }

            $evidence = is_array($source['settlement_evidence'] ?? null) ? $source['settlement_evidence'] : [];
            $financials = $order->financials()->lockForUpdate()->first();
            $disposition = $this->disposition($financials, $evidence);
            if (! in_array($disposition['status'], ['create', 'update'], true)) {
                return $this->preview($source);
            }

            $before = $financials?->toArray();
            $values = $this->values($evidence);
            if ($financials === null) {
                $financials = $order->financials()->create($values);
            } else {
                $currencyChanged = $financials->currency !== null
                    && ($evidence['currency'] ?? null) !== null
                    && strtoupper((string) $financials->currency) !== strtoupper((string) $evidence['currency']);
                $financials->fill($values);
                if ($currencyChanged) {
                    $financials->fx_rate = null;
                    $financials->fx_rate_date = null;
                    $financials->fx_rate_source = null;
                }
                $financials->save();
            }

            $order = $this->analysis->refreshCompleteness($order, preserveReconciled: false);
            $after = $order->financials()->firstOrFail()->toArray();
            $this->audit->record(
                AuditActor::system(AuditSource::InternalSystem),
                'finance.financial_evidence_bootstrap.applied',
                $order::class,
                $order->getKey(),
                before: $before,
                after: $after,
                reason: 'woocommerce_financial_evidence_bootstrap',
            );

            return [
                'provider_reference' => (string) $source['provider_reference'],
                'connection_key' => (string) ($source['connection_key'] ?? ''),
                'order_id' => (int) $order->getKey(),
                'disposition' => $disposition['status'] === 'create' ? 'created' : 'updated',
                'blocker' => null,
                'finance_status' => $after['completeness_status'] ?? null,
                'reconciliation_status' => $after['reconciliation_status'] ?? null,
            ];
        });
    }

    /** @return array{status:string,blocker:?string} */
    private function disposition(?Model $financials, array $evidence): array
    {
        if ($financials === null) {
            return ['status' => 'create', 'blocker' => null];
        }

        $provenance = $financials->revenue_provenance;
        if ($provenance === 'confirmed_manual') {
            return ['status' => 'blocked', 'blocker' => 'confirmed_manual_revenue_protected'];
        }
        if ($provenance === null && $this->hasLegacyRevenueFacts($financials)) {
            return ['status' => 'blocked', 'blocker' => 'legacy_unclassified_revenue_protected'];
        }
        if ($provenance !== null && $provenance !== 'provider_import') {
            return ['status' => 'blocked', 'blocker' => 'unsupported_existing_revenue_provenance'];
        }

        return $this->sameEvidence($financials, $evidence)
            ? ['status' => 'noop', 'blocker' => null]
            : ['status' => 'update', 'blocker' => null];
    }

    private function values(array $evidence): array
    {
        $values = array_intersect_key($evidence, array_flip(self::EVIDENCE_FIELDS));
        $values['revenue_provenance'] = 'provider_import';
        $values['completeness_status'] = 'INCOMPLETE';

        return $values;
    }

    private function hasLegacyRevenueFacts(Model $financials): bool
    {
        foreach (self::LEGACY_REVENUE_FIELDS as $field) {
            if ($financials->{$field} !== null) {
                return true;
            }
        }

        return false;
    }

    private function sameEvidence(Model $financials, array $evidence): bool
    {
        foreach (self::EVIDENCE_FIELDS as $field) {
            if (! $this->equivalent($financials->{$field}, $evidence[$field] ?? null)) {
                return false;
            }
        }

        return true;
    }

    private function equivalent(mixed $left, mixed $right): bool
    {
        if ($left === null || $right === null) {
            return $left === null && $right === null;
        }
        if ($left instanceof \DateTimeInterface) {
            $left = $left->format('Y-m-d H:i:s');
        }
        if ($right instanceof \DateTimeInterface) {
            $right = $right->format('Y-m-d H:i:s');
        }
        if (is_array($left) && is_array($right)) {
            return $left == $right;
        }
        if (is_array($left) || is_array($right)) {
            return false;
        }
        $leftString = str_replace('T', ' ', (string) $left);
        $rightString = str_replace('T', ' ', (string) $right);

        return rtrim($leftString, 'Z') === rtrim($rightString, 'Z');
    }

    private function findOperationalOrder(array $source, bool $lock = false): ?Model
    {
        if (($source['provider'] ?? null) !== 'woocommerce') {
            return null;
        }

        $connectionKey = trim((string) ($source['connection_key'] ?? ''));
        $reference = trim((string) ($source['provider_reference'] ?? ''));
        $orderId = (int) ($source['order_id'] ?? 0);
        if ($orderId <= 0 || $connectionKey === '' || $reference === '') {
            return null;
        }

        return $this->orders->findExactOperational(
            $orderId,
            'woocommerce',
            $connectionKey,
            $reference,
            $lock,
        );
    }
}
