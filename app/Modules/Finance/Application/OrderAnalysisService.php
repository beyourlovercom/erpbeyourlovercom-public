<?php

namespace App\Modules\Finance\Application;

use App\Modules\Audit\Application\AuditActor;
use App\Modules\Audit\Application\AuditSource;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class OrderAnalysisService
{
    public const FINANCE_STATUSES = ['UNPOSTED', 'INCOMPLETE', 'ESTIMATED', 'CONFIRMED', 'RECONCILED'];

    private const COST_FIELDS = [
        'product_cost', 'warehouse_cost', 'first_mile_cost', 'overseas_handling_cost', 'last_mile_cost',
        'kol_commission', 'payment_fee', 'customs_tax', 'refund_replacement_loss', 'other_variable_cost',
    ];

    private const P82_REQUIRED_COST_FIELDS = [
        'product_cost', 'first_mile_cost', 'overseas_handling_cost', 'last_mile_cost', 'payment_fee', 'customs_tax',
    ];

    private const LOGISTICS_COST_FIELDS = [
        'warehouse_cost', 'first_mile_cost', 'overseas_handling_cost', 'last_mile_cost',
    ];

    private const OTHER_COST_FIELDS = [
        'kol_commission', 'payment_fee', 'customs_tax', 'refund_replacement_loss', 'other_variable_cost',
    ];

    private const FINANCIAL_FIELDS = [
        'merchandise_list_amount', 'discount_amount', 'shipping_revenue', 'amount_paid', 'refund_amount',
    ];

    private const CONFIRMED_COST_PROVENANCE = [
        'actual', 'confirmed_manual', 'provider_import', 'allocated_actual',
    ];

    public function __construct(private readonly AuditRecorder $audit) {}

    public function save(Model $order, array $attributes, ?Authenticatable $actor): Model
    {
        return DB::transaction(function () use ($order, $attributes, $actor): Model {
            $financials = $order->financials()->lockForUpdate()->firstOrCreate(
                ['order_id' => $order->getKey()],
                ['currency' => $attributes['currency'] ?? $order->currency ?? 'USD'],
            );
            $before = [
                'financials' => $financials->toArray(),
                'costs' => $order->costEntries()->get()->toArray(),
            ];

            $financialAttributes = array_intersect_key(
                $attributes,
                array_flip([...self::FINANCIAL_FIELDS, 'currency']),
            );
            if ($financialAttributes !== []) {
                $revenueChanged = $this->financialValuesChanged($financials, $financialAttributes);
                $financials->fill($financialAttributes);
                if ($revenueChanged || $financials->revenue_provenance === null) {
                    $financials->revenue_provenance = 'confirmed_manual';
                    $financials->reconciliation_status = null;
                    $financials->reconciliation_exception = null;
                }
                $financials->save();
            }

            foreach (self::COST_FIELDS as $field) {
                if (! array_key_exists($field, $attributes)) {
                    continue;
                }
                $entry = $order->costEntries()->where('cost_type', $field)->lockForUpdate()->first();
                if ($attributes[$field] === null) {
                    $entry?->delete();
                    continue;
                }

                $currency = $attributes['currency'] ?? $financials->currency ?? $order->currency ?? 'USD';
                if ($entry && $this->sameDecimal($entry->amount, $attributes[$field]) && $entry->currency === $currency) {
                    continue;
                }

                $values = [
                    'amount' => $attributes[$field],
                    'currency' => $currency,
                    'provenance' => 'confirmed_manual',
                    'status' => 'confirmed',
                    'confirmed_by' => $actor?->getAuthIdentifier(),
                    'effective_at' => now(),
                    'source_at' => now(),
                ];
                if ($entry) {
                    $entry->fill($values)->save();
                } else {
                    $order->costEntries()->create([
                        'cost_type' => $field,
                        ...$values,
                        'created_by' => $actor?->getAuthIdentifier(),
                    ]);
                }
            }

            $projectedOrder = $order->fresh(['financials', 'costEntries']);
            $projection = $this->projection($projectedOrder, preserveReconciled: false);
            $projectedOrder->financials->forceFill([
                'completeness_status' => $projection['finance_status'],
                'missing_facts' => $projection['missing_facts'],
            ])->save();

            $after = [
                'financials' => $projectedOrder->financials->fresh()->toArray(),
                'costs' => $projectedOrder->costEntries()->get()->toArray(),
            ];
            $this->audit->record(
                $actor ? AuditActor::user($actor, AuditSource::InternalSystem) : AuditActor::system(AuditSource::InternalSystem),
                'order_analysis.updated',
                get_class($order),
                $order->getKey(),
                before: $before,
                after: $after,
                reason: 'order_cost_correction',
            );

            return $order->fresh(['financials', 'costEntries']);
        });
    }

    public function summary(Model $order): array
    {
        return $this->projection($order, preserveReconciled: true);
    }

    public function refreshCompleteness(Model $order, bool $preserveReconciled = false): Model
    {
        $financials = $order->financials()->firstOrCreate(
            ['order_id' => $order->getKey()],
            ['currency' => $order->currency ?? 'USD'],
        );
        $projectedOrder = $order->fresh(['financials', 'costEntries']);
        $projection = $this->projection($projectedOrder, preserveReconciled: $preserveReconciled);
        $financials->forceFill([
            'completeness_status' => $projection['finance_status'],
            'missing_facts' => $projection['missing_facts'],
        ]);
        if ($financials->isDirty(['completeness_status', 'missing_facts'])) {
            $financials->save();
        }

        return $order->fresh(['financials', 'costEntries']);
    }

    public function fields(): array
    {
        return [...self::FINANCIAL_FIELDS, ...self::COST_FIELDS];
    }

    public static function p82RequiredCostFields(): array
    {
        return self::P82_REQUIRED_COST_FIELDS;
    }

    public function values(Model $order): array
    {
        $financials = $order->relationLoaded('financials') ? $order->financials : $order->financials()->first();
        $entries = $order->relationLoaded('costEntries') ? $order->costEntries : $order->costEntries()->get();
        $values = [];
        foreach (self::FINANCIAL_FIELDS as $field) {
            $values[$field] = $financials?->{$field};
        }
        $byType = $entries->keyBy('cost_type');
        foreach (self::COST_FIELDS as $field) {
            $values[$field] = $byType->get($field)?->amount;
        }

        return $values;
    }

    public function visibility(Model $order): array
    {
        $financials = $order->relationLoaded('financials') ? $order->financials : $order->financials()->first();
        $entries = $order->relationLoaded('costEntries') ? $order->costEntries : $order->costEntries()->get();
        $byType = $entries->keyBy('cost_type');

        return [
            'summary' => $this->summary($order),
            'values' => $this->values($order),
            'revenue' => [
                'currency' => strtoupper((string) ($financials?->currency ?? $order->currency ?? 'USD')),
                'provenance' => $financials?->revenue_provenance,
                'reconciliation_status' => $financials?->reconciliation_status,
                'provider_order_status' => $financials?->provider_order_status,
            ],
            'cost_lines' => collect(self::COST_FIELDS)->map(function (string $field) use ($byType): array {
                $entry = $byType->get($field);
                if (! $entry) {
                    return ['cost_type' => $field, 'state' => 'missing', 'amount' => null, 'currency' => null, 'provenance' => null, 'status' => null, 'source_reference' => null, 'source_at' => null, 'effective_at' => null];
                }

                $provenance = (string) ($entry->provenance ?? 'confirmed_manual');
                $status = (string) ($entry->status ?? 'confirmed');
                $state = ($provenance === 'estimated_reference' || $status === 'estimated')
                    ? 'estimated'
                    : ((in_array($status, ['confirmed', 'adjusted'], true) && in_array($provenance, self::CONFIRMED_COST_PROVENANCE, true)) ? 'confirmed' : 'review');

                return [
                    'cost_type' => $field,
                    'state' => $state,
                    'amount' => (string) $entry->amount,
                    'currency' => strtoupper((string) $entry->currency),
                    'provenance' => $provenance,
                    'status' => $status,
                    'source_reference' => $entry->source_reference,
                    'source_at' => $entry->source_at?->toIso8601String(),
                    'effective_at' => $entry->effective_at?->toIso8601String(),
                ];
            })->values()->all(),
        ];
    }

    private function projection(Model $order, bool $preserveReconciled): array
    {
        $financials = $order->relationLoaded('financials') ? $order->financials : $order->financials()->first();
        /** @var Collection<string,Model> $byType */
        $byType = ($order->relationLoaded('costEntries') ? $order->costEntries : $order->costEntries()->get())->keyBy('cost_type');

        $revenue = $this->revenueAssessment($financials);
        $costs = $this->costAssessment($byType);
        $missingFacts = [...$revenue['missing'], ...$costs['missing']];

        if ($financials === null && $byType->isEmpty()) {
            $financeStatus = 'UNPOSTED';
        } elseif ($missingFacts !== []) {
            $financeStatus = 'INCOMPLETE';
        } elseif ($costs['estimated']) {
            $financeStatus = 'ESTIMATED';
        } else {
            $financeStatus = 'CONFIRMED';
        }
        if ($preserveReconciled && $financeStatus === 'CONFIRMED' && $financials?->completeness_status === 'RECONCILED') {
            $financeStatus = 'RECONCILED';
        }

        $commercialBeforeRefund = $revenue['commercial_before_refund_cents'];
        $netRevenueCents = $revenue['net_revenue_cents'];
        $totalCostCents = $costs['total_cents'];
        $profitCents = $netRevenueCents !== null && $totalCostCents !== null ? $netRevenueCents - $totalCostCents : null;
        $margin = $profitCents !== null && $netRevenueCents !== 0
            ? bcdiv((string) $profitCents, (string) $netRevenueCents, 4)
            : null;

        $currency = strtoupper((string) ($financials?->currency ?? $order->currency ?? 'USD'));
        $postedCostEntries = collect(self::COST_FIELDS)->filter(fn (string $field): bool => $byType->has($field))->map(fn (string $field) => $byType->get($field));
        $postedCostCount = $postedCostEntries->count();
        $estimatedCostCount = $postedCostEntries->filter(fn ($entry): bool => ($entry->provenance ?? null) === 'estimated_reference' || ($entry->status ?? null) === 'estimated')->count();
        $confirmedCostCount = $postedCostEntries->filter(function ($entry): bool {
            return in_array((string) ($entry->status ?? 'confirmed'), ['confirmed', 'adjusted'], true)
                && in_array((string) ($entry->provenance ?? 'confirmed_manual'), self::CONFIRMED_COST_PROVENANCE, true);
        })->count();
        $knownCostCurrencyCompatible = $postedCostEntries->every(fn ($entry): bool => strtoupper((string) $entry->currency) === $currency);
        $knownCostCents = $knownCostCurrencyCompatible
            ? $postedCostEntries->sum(fn ($entry): int => $this->cents($entry->amount))
            : null;
        $currentStageProfitCents = $netRevenueCents !== null && $knownCostCents !== null ? $netRevenueCents - $knownCostCents : null;
        $currentStageMargin = $currentStageProfitCents !== null && $netRevenueCents !== 0
            ? bcdiv((string) $currentStageProfitCents, (string) $netRevenueCents, 4)
            : null;
        $fxFrozen = $currency === 'USD' || ($financials?->fx_rate !== null && $financials?->fx_rate_date !== null && $financials?->fx_rate_source !== null);
        $aggregationMissing = $fxFrozen ? [] : ['transaction_fx_snapshot'];
        $p82Readiness = $this->p82ReadinessAssessment($revenue, $byType, $currency);

        return [
            'completeness' => match ($financeStatus) {
                'CONFIRMED', 'RECONCILED' => 'complete',
                'ESTIMATED' => 'estimated',
                default => 'incomplete',
            },
            'finance_status' => $financeStatus,
            'revenue_status' => $revenue['ready'] ? 'confirmed' : 'incomplete',
            'cost_status' => $costs['ready'] ? ($costs['estimated'] ? 'estimated' : 'confirmed') : 'incomplete',
            'profit_status' => match ($financeStatus) {
                'CONFIRMED', 'RECONCILED' => 'confirmed',
                'ESTIMATED' => 'estimated',
                default => 'not_confirmed',
            },
            'missing_facts' => $missingFacts,
            'aggregation_missing_facts' => $aggregationMissing,
            'fx_status' => $currency === 'USD' ? 'not_required' : ($fxFrozen ? 'frozen' : 'missing'),
            'base_currency_aggregate_eligible' => $fxFrozen,
            'commercial_revenue_before_refund' => $commercialBeforeRefund === null ? null : $this->money($commercialBeforeRefund),
            'net_revenue' => $netRevenueCents === null ? null : $this->money($netRevenueCents),
            'known_cost' => $knownCostCents === null ? null : $this->money($knownCostCents),
            'current_stage_profit' => $currentStageProfitCents === null ? null : $this->money($currentStageProfitCents),
            'current_stage_margin' => $currentStageMargin,
            'posted_cost_count' => $postedCostCount,
            'confirmed_cost_count' => $confirmedCostCount,
            'estimated_cost_count' => $estimatedCostCount,
            'required_cost_count' => count(self::COST_FIELDS),
            'p82_estimated_profit_base_ready' => $p82Readiness['ready'],
            'p82_required_cost_count' => count(self::P82_REQUIRED_COST_FIELDS),
            'p82_required_cost_covered_count' => $p82Readiness['covered_count'],
            'p82_missing_required_costs' => $p82Readiness['missing'],
            'total_cost' => $totalCostCents === null ? null : $this->money($totalCostCents),
            'contribution_profit' => $profitCents === null ? null : $this->money($profitCents),
            'margin' => $margin,
            'cost_breakdown' => [
                'product_cost' => $this->groupMoney(['product_cost'], $byType),
                'logistics_cost' => $this->groupMoney(self::LOGISTICS_COST_FIELDS, $byType),
                'other_cost' => $this->groupMoney(self::OTHER_COST_FIELDS, $byType),
            ],
        ];
    }

    private function revenueAssessment(?Model $financials): array
    {
        if ($financials === null) {
            return [
                'ready' => false,
                'missing' => ['settlement_components'],
                'commercial_before_refund_cents' => null,
                'net_revenue_cents' => null,
            ];
        }

        $componentFields = ['merchandise_list_amount', 'discount_amount', 'shipping_revenue', 'refund_amount'];
        $hasComponents = collect($componentFields)->every(fn (string $field): bool => $financials->{$field} !== null);
        $legacyManual = $financials->merchandise_list_amount === null
            && $financials->amount_paid !== null
            && $financials->refund_amount !== null;
        $provenance = $financials->revenue_provenance;
        $missing = [];

        if ($provenance === 'provider_import') {
            foreach ($componentFields as $field) {
                if ($financials->{$field} === null) {
                    $missing[] = $field;
                }
            }
            if ($financials->provider_order_total === null) {
                $missing[] = 'provider_order_total';
            }
            if ($financials->tax_collected === null) {
                $missing[] = 'tax_collected';
            }
            if ($financials->paid_at === null) {
                $missing[] = 'payment_settlement';
            }
            if (! in_array((string) $financials->provider_order_status, ['processing', 'completed', 'refunded'], true)) {
                $missing[] = 'provider_order_status_not_revenue_eligible';
            }
            if ($financials->reconciliation_status !== 'matched') {
                $missing[] = 'provider_reconciliation';
            }
        } elseif ($provenance === 'confirmed_manual' || ($provenance === null && $legacyManual)) {
            if (! $hasComponents && ! $legacyManual) {
                $missing[] = 'settlement_components';
            }
        } else {
            $missing[] = 'revenue_provenance';
            if (! $hasComponents && ! $legacyManual) {
                $missing[] = 'settlement_components';
            }
        }

        $commercialBeforeRefundCents = null;
        $netRevenueCents = null;
        if ($hasComponents) {
            $commercialBeforeRefundCents = $this->cents($financials->merchandise_list_amount)
                - $this->cents($financials->discount_amount)
                + $this->cents($financials->shipping_revenue);
            $netRevenueCents = $commercialBeforeRefundCents - $this->cents($financials->refund_amount);
        } elseif ($legacyManual) {
            $commercialBeforeRefundCents = $this->cents($financials->amount_paid);
            $netRevenueCents = $commercialBeforeRefundCents - $this->cents($financials->refund_amount);
        }

        return [
            'ready' => $missing === [],
            'missing' => array_values(array_unique($missing)),
            'commercial_before_refund_cents' => $missing === [] ? $commercialBeforeRefundCents : null,
            'net_revenue_cents' => $missing === [] ? $netRevenueCents : null,
        ];
    }

    private function costAssessment(Collection $byType): array
    {
        $missing = [];
        $estimated = false;
        foreach (self::COST_FIELDS as $field) {
            $entry = $byType->get($field);
            if (! $entry) {
                $missing[] = $field;
                continue;
            }
            $provenance = (string) ($entry->provenance ?? 'confirmed_manual');
            $status = (string) ($entry->status ?? 'confirmed');
            if ($provenance === 'estimated_reference' || $status === 'estimated') {
                $estimated = true;
                continue;
            }
            if (! in_array($status, ['confirmed', 'adjusted'], true)) {
                $missing[] = $field.'_not_confirmed';
                continue;
            }
            if (! in_array($provenance, self::CONFIRMED_COST_PROVENANCE, true)) {
                $missing[] = $field.'_provenance_unapproved';
            }
        }

        $totalCents = null;
        if ($missing === []) {
            $totalCents = collect(self::COST_FIELDS)->sum(fn (string $field): int => $this->cents($byType->get($field)->amount));
        }

        return [
            'ready' => $missing === [],
            'estimated' => $estimated,
            'missing' => $missing,
            'total_cents' => $totalCents,
        ];
    }

    private function p82ReadinessAssessment(array $revenue, Collection $byType, string $currency): array
    {
        $covered = 0;
        $missing = [];
        foreach (self::P82_REQUIRED_COST_FIELDS as $field) {
            $entry = $byType->get($field);
            if (! $entry) {
                $missing[] = $field;
                continue;
            }
            if (strtoupper((string) $entry->currency) !== $currency) {
                $missing[] = $field.'_currency_mismatch';
                continue;
            }
            $provenance = (string) ($entry->provenance ?? 'confirmed_manual');
            $status = (string) ($entry->status ?? 'confirmed');
            if ($provenance === 'estimated_reference' || $status === 'estimated') {
                $covered++;
                continue;
            }
            if (in_array($status, ['confirmed', 'adjusted'], true)
                && in_array($provenance, self::CONFIRMED_COST_PROVENANCE, true)) {
                $covered++;
                continue;
            }
            $missing[] = $field.'_not_ready';
        }

        return [
            'ready' => $revenue['ready'] && $missing === [],
            'covered_count' => $covered,
            'missing' => $missing,
        ];
    }

    private function groupMoney(array $fields, Collection $byType): ?string
    {
        foreach ($fields as $field) {
            if (! $byType->has($field)) {
                return null;
            }
        }
        $cents = collect($fields)->sum(fn (string $field): int => $this->cents($byType->get($field)->amount));
        return $this->money($cents);
    }

    private function financialValuesChanged(Model $financials, array $attributes): bool
    {
        foreach ($attributes as $field => $value) {
            if ($field === 'currency') {
                if (strtoupper((string) $financials->{$field}) !== strtoupper((string) $value)) {
                    return true;
                }
                continue;
            }
            if (! $this->sameDecimal($financials->{$field}, $value)) {
                return true;
            }
        }
        return false;
    }

    private function sameDecimal(mixed $left, mixed $right): bool
    {
        if ($left === null || $right === null) {
            return $left === null && $right === null;
        }
        return $this->cents($left) === $this->cents($right);
    }

    private function cents(mixed $amount): int
    {
        $value = trim((string) $amount);
        if (! preg_match('/^(?<sign>-)?(?<whole>\d+)(?:\.(?<fraction>\d{1,2}))?$/D', $value, $matches)) {
            throw new \UnexpectedValueException('Financial amounts must be decimal values with at most two fractional digits.');
        }
        $cents = ((int) $matches['whole'] * 100) + (int) str_pad($matches['fraction'] ?? '', 2, '0');
        return ($matches['sign'] ?? '') === '-' ? -$cents : $cents;
    }

    private function money(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}
