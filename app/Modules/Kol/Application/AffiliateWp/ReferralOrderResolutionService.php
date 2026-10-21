<?php

namespace App\Modules\Kol\Application\AffiliateWp;

use App\Modules\Audit\Application\AuditActor;
use App\Modules\Audit\Application\AuditSource;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Kol\Infrastructure\Models\KolAffiliateReferral;
use App\Modules\Orders\Infrastructure\Models\OrderExternalReference;
use Illuminate\Support\Facades\DB;

final class ReferralOrderResolutionService
{
    public function __construct(private readonly AuditRecorder $audit) {}

    /** @return array<string,mixed> */
    public function preview(?string $connectionKey = null): array
    {
        [$rows, $matches] = $this->snapshot($connectionKey);
        $summary = $this->emptySummary('dry_run');
        foreach ($rows as $row) {
            $this->accumulate($summary, $row, $matches);
        }
        return $summary;
    }

    /** @return array<string,mixed> */
    public function apply(?string $connectionKey = null): array
    {
        return DB::transaction(function () use ($connectionKey): array {
            [$rows, $matches] = $this->snapshot($connectionKey, true);
            $summary = $this->emptySummary('apply');
            foreach ($rows as $row) {
                $decision = $this->decision($row, $matches);
                $this->countDecision($summary, $decision);
                $desiredOrderId = $decision['status'] === 'ready' ? $decision['order_id'] : null;
                $desiredResolution = $decision['status'] === 'ready' ? 'resolved' : 'unresolved';
                if ((int) ($row->order_id ?? 0) === (int) ($desiredOrderId ?? 0)
                    && (string) $row->order_resolution_status === $desiredResolution) {
                    $summary['noop']++;
                    continue;
                }
                $row->forceFill([
                    'order_id' => $desiredOrderId,
                    'order_resolution_status' => $desiredResolution,
                ])->save();
                $summary['changed']++;
            }
            if ($summary['changed'] > 0) {
                $this->audit->record(
                    AuditActor::system(AuditSource::InternalSystem),
                    'kol.referral_order_resolution.applied',
                    'kol_affiliate_referrals',
                    $connectionKey ?? '*',
                    null,
                    ['connection_key' => $connectionKey, 'summary' => $summary],
                    'p8_referral_order_resolution',
                );
            }
            return $summary;
        });
    }

    /** @return array{0:\Illuminate\Support\Collection,1:array<string,list<int>>} */
    private function snapshot(?string $connectionKey, bool $lock = false): array
    {
        $query = KolAffiliateReferral::query()->orderBy('id');
        if ($connectionKey !== null && $connectionKey !== '') {
            $query->where('connection_key', $connectionKey);
        }
        if ($lock) {
            $query->lockForUpdate();
        }
        $rows = $query->get(['id','connection_key','context','order_reference','order_id','order_resolution_status']);
        $references = $rows->filter(fn ($row): bool => strtolower((string) $row->context) === 'woocommerce'
            && trim((string) $row->order_reference) !== '');
        $matches = [];
        foreach ($references->groupBy('connection_key') as $connection => $group) {
            $refs = $group->pluck('order_reference')->map(fn ($v) => (string) $v)->unique()->values()->all();
            foreach (array_chunk($refs, 500) as $chunk) {
                $found = OrderExternalReference::query()
                    ->where('provider', 'woocommerce')
                    ->where('connection_key', (string) $connection)
                    ->whereIn('reference', $chunk)
                    ->get(['reference','order_id']);
                foreach ($found as $ref) {
                    $key = $this->key((string) $connection, (string) $ref->reference);
                    $matches[$key] ??= [];
                    $matches[$key][] = (int) $ref->order_id;
                    $matches[$key] = array_values(array_unique($matches[$key]));
                }
            }
        }
        return [$rows, $matches];
    }

    /** @param array<string,list<int>> $matches @return array{status:string,order_id:?int} */
    private function decision(KolAffiliateReferral $row, array $matches): array
    {
        if (strtolower((string) $row->context) !== 'woocommerce' || trim((string) $row->order_reference) === '') {
            return ['status' => 'unresolved', 'order_id' => null];
        }
        $ids = $matches[$this->key((string) $row->connection_key, (string) $row->order_reference)] ?? [];
        if (count($ids) === 1) {
            return ['status' => 'ready', 'order_id' => $ids[0]];
        }
        return ['status' => count($ids) > 1 ? 'ambiguous' : 'unresolved', 'order_id' => null];
    }

    /** @param array<string,mixed> $summary @param array<string,list<int>> $matches */
    private function accumulate(array &$summary, KolAffiliateReferral $row, array $matches): void
    {
        $decision = $this->decision($row, $matches);
        $this->countDecision($summary, $decision);
        $desiredOrderId = $decision['status'] === 'ready' ? $decision['order_id'] : null;
        $desiredResolution = $decision['status'] === 'ready' ? 'resolved' : 'unresolved';
        if ((int) ($row->order_id ?? 0) === (int) ($desiredOrderId ?? 0)
            && (string) $row->order_resolution_status === $desiredResolution) {
            $summary['noop']++;
        } else {
            $summary['changed']++;
        }
    }

    /** @param array<string,mixed> $summary @param array{status:string,order_id:?int} $decision */
    private function countDecision(array &$summary, array $decision): void
    {
        $summary['seen']++;
        $summary[$decision['status']]++;
    }

    /** @return array<string,int|string> */
    private function emptySummary(string $mode): array
    {
        return ['mode'=>$mode,'seen'=>0,'ready'=>0,'unresolved'=>0,'ambiguous'=>0,'changed'=>0,'noop'=>0];
    }

    private function key(string $connection, string $reference): string
    {
        return $connection."\0".$reference;
    }
}
