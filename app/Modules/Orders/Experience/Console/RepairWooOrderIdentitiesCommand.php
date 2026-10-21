<?php

namespace App\Modules\Orders\Experience\Console;

use App\Modules\Audit\Application\AuditActor;
use App\Modules\Audit\Application\AuditSource;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Orders\Application\OrderIdentityService;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class RepairWooOrderIdentitiesCommand extends Command
{
    protected $signature = 'orders:repair-woo-identities
        {--date= : Exact Woo order business date in YYYY-MM-DD}
        {--from-date= : Inclusive Woo order business-date range start in YYYY-MM-DD}
        {--to-date= : Inclusive Woo order business-date range end in YYYY-MM-DD}
        {--apply : Apply the reviewed corrections}
        {--expect-change= : Required exact candidate count when applying}
        {--json : Emit safe JSON evidence}';

    protected $description = 'Preview or repair Woo ERP IDs to prefix-MMDD-cWooOrderNumber';

    public function handle(OrderIdentityService $identities, AuditRecorder $audit): int
    {
        $date = trim((string) $this->option('date'));
        $fromDate = trim((string) $this->option('from-date'));
        $toDate = trim((string) $this->option('to-date'));

        if ($date !== '') {
            if ($fromDate !== '' || $toDate !== '' || ! $this->validDate($date)) {
                $this->error('--date must be a valid YYYY-MM-DD and cannot be combined with --from-date/--to-date.');

                return self::FAILURE;
            }
            $fromDate = $date;
            $toDate = $date;
        } else {
            if (! $this->validDate($fromDate) || ! $this->validDate($toDate)) {
                $this->error('Provide either --date=YYYY-MM-DD or both --from-date=YYYY-MM-DD and --to-date=YYYY-MM-DD.');

                return self::FAILURE;
            }
            if ($fromDate > $toDate) {
                $this->error('--from-date must be on or before --to-date.');

                return self::FAILURE;
            }
        }

        $orders = Order::query()
            ->with(['externalReferences' => fn ($query) => $query->where('provider', 'woocommerce')])
            ->where('source_type', 'woocommerce')
            ->whereDate('order_date', '>=', $fromDate)
            ->whereDate('order_date', '<=', $toDate)
            ->orderBy('id')
            ->get();

        $rows = [];
        $targets = [];
        $change = 0;
        $noop = 0;
        $blocked = 0;

        foreach ($orders as $order) {
            $reference = $order->externalReferences->first();
            if (! $reference) {
                $rows[] = $this->row($order, null, 'blocked', 'woocommerce_reference_missing');
                $blocked++;

                continue;
            }

            try {
                $expected = $identities->previewWooCustomer(
                    (string) $order->external_order_number,
                    (string) $reference->reference,
                    $order->order_date->toDateString(),
                );
            } catch (\Throwable) {
                $rows[] = $this->row($order, null, 'blocked', 'woo_order_number_invalid');
                $blocked++;

                continue;
            }

            if ((string) $order->erp_order_id === $expected) {
                $rows[] = $this->row($order, $expected, 'noop', 'already_canonical');
                $noop++;

                continue;
            }

            $collision = Order::query()
                ->where('erp_order_id', $expected)
                ->where('id', '<>', $order->id)
                ->exists();
            if ($collision || isset($targets[$expected])) {
                $rows[] = $this->row($order, $expected, 'blocked', 'target_identity_collision');
                $blocked++;

                continue;
            }

            $targets[$expected] = $order->id;
            $rows[] = $this->row($order, $expected, 'change', 'owner_rule_007_mmdd_c_woo_number');
            $change++;
        }

        $summary = [
            'mode' => $this->option('apply') ? 'apply' : 'dry_run',
            'date' => $fromDate === $toDate ? $fromDate : null,
            'from_date' => $fromDate,
            'to_date' => $toDate,
            'seen' => $orders->count(),
            'change' => $change,
            'noop' => $noop,
            'blocked' => $blocked,
        ];

        if (! $this->option('apply')) {
            return $this->emit($summary, $rows, self::SUCCESS);
        }

        if ($blocked > 0) {
            $summary['gate'] = 'blocked_rows_present';

            return $this->emit($summary, $rows, self::FAILURE);
        }

        $expectedChange = $this->option('expect-change');
        if ($expectedChange === null || ! ctype_digit((string) $expectedChange) || (int) $expectedChange !== $change) {
            $summary['gate'] = 'expect_change_mismatch';

            return $this->emit($summary, $rows, self::FAILURE);
        }

        DB::transaction(function () use ($rows, $audit): void {
            foreach ($rows as $row) {
                if ($row['decision'] !== 'change') {
                    continue;
                }

                $order = Order::query()->whereKey($row['order_id'])->lockForUpdate()->firstOrFail();
                $before = (string) $order->erp_order_id;
                $target = (string) $row['expected_erp_order_id'];
                if (Order::query()->where('erp_order_id', $target)->where('id', '<>', $order->id)->exists()) {
                    throw new \RuntimeException('Target ERP order identity collided during apply.');
                }

                $order->forceFill(['erp_order_id' => $target])->save();
                $audit->record(
                    AuditActor::system(AuditSource::InternalSystem),
                    'order.erp_identity_repaired',
                    Order::class,
                    $order->id,
                    before: ['erp_order_id' => $before],
                    after: ['erp_order_id' => $target],
                    reason: 'owner_rule_007_mmdd_c_woo_number',
                );
            }
        });

        $summary['applied'] = $change;
        $summary['gate'] = 'applied';

        return $this->emit($summary, $rows, self::SUCCESS);
    }

    private function validDate(string $value): bool
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return false;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = \DateTimeImmutable::getLastErrors();

        return $date !== false
            && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
            && $date->format('Y-m-d') === $value;
    }

    /** @return array<string,mixed> */
    private function row(Order $order, ?string $expected, string $decision, string $reason): array
    {
        return [
            'order_id' => $order->id,
            'woo_order_number' => $order->external_order_number,
            'current_erp_order_id' => $order->erp_order_id,
            'expected_erp_order_id' => $expected,
            'decision' => $decision,
            'reason' => $reason,
        ];
    }

    /** @param array<string,mixed> $summary @param list<array<string,mixed>> $rows */
    private function emit(array $summary, array $rows, int $exit): int
    {
        if ($this->option('json')) {
            $this->line((string) json_encode(['summary' => $summary, 'rows' => $rows], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->line(sprintf(
                'Woo ERP identity %s: scope=%s..%s seen=%d change=%d noop=%d blocked=%d',
                $summary['mode'], $summary['from_date'], $summary['to_date'], $summary['seen'], $summary['change'], $summary['noop'], $summary['blocked'],
            ));
            foreach ($rows as $row) {
                $this->line(sprintf('%s -> %s [%s]', $row['current_erp_order_id'] ?? 'NULL', $row['expected_erp_order_id'] ?? 'BLOCKED', $row['decision']));
            }
        }

        return $exit;
    }
}
