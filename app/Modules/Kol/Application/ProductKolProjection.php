<?php

namespace App\Modules\Kol\Application;

use Illuminate\Support\Facades\DB;

/** Read-only Product projection of canonical KOL Collaboration relations. */
final class ProductKolProjection
{
    /** @return array{units_30d:int,shipment_count_30d:int,units_90d:int,shipment_count_90d:int,lifetime_units:int,lifetime_shipment_count:int} */
    public function shippingSummaryForProduct(int $productId): array
    {
        $base = fn () => DB::table('orders')
            ->join('order_items', 'order_items.order_id', '=', 'orders.id')
            ->join('shipments', 'shipments.order_id', '=', 'orders.id')
            ->where('order_items.product_id', $productId)
            ->where('orders.order_kind', 'kol_fulfillment')
            ->where('shipments.shipment_status', 'shipped')
            ->whereNotNull('shipments.shipped_at');

        $summary = $base()
            ->selectRaw('COALESCE(SUM(order_items.quantity), 0) as lifetime_units')
            ->selectRaw('COUNT(DISTINCT shipments.id) as lifetime_shipment_count')
            ->selectRaw("COALESCE(SUM(CASE WHEN shipments.shipped_at >= ? THEN order_items.quantity ELSE 0 END), 0) as units_30d", [now()->subDays(30)->toDateString()])
            ->selectRaw("COUNT(DISTINCT CASE WHEN shipments.shipped_at >= ? THEN shipments.id END) as shipment_count_30d", [now()->subDays(30)->toDateString()])
            ->selectRaw("COALESCE(SUM(CASE WHEN shipments.shipped_at >= ? THEN order_items.quantity ELSE 0 END), 0) as units_90d", [now()->subDays(90)->toDateString()])
            ->selectRaw("COUNT(DISTINCT CASE WHEN shipments.shipped_at >= ? THEN shipments.id END) as shipment_count_90d", [now()->subDays(90)->toDateString()])
            ->first();

        return [
            'units_30d' => (int) ($summary->units_30d ?? 0),
            'shipment_count_30d' => (int) ($summary->shipment_count_30d ?? 0),
            'units_90d' => (int) ($summary->units_90d ?? 0),
            'shipment_count_90d' => (int) ($summary->shipment_count_90d ?? 0),
            'lifetime_units' => (int) ($summary->lifetime_units ?? 0),
            'lifetime_shipment_count' => (int) ($summary->lifetime_shipment_count ?? 0),
        ];
    }

    /** @return list<array<string,mixed>> */
    public function forProduct(int $productId, int $limit = 20): array
    {
        return DB::table('kol_collaborations')
            ->join('kols', 'kols.id', '=', 'kol_collaborations.kol_id')
            ->join('kol_collaboration_products', function ($join) use ($productId): void {
                $join->on('kol_collaboration_products.collaboration_id', '=', 'kol_collaborations.id')
                    ->where('kol_collaboration_products.product_id', '=', $productId);
            })
            ->select('kol_collaborations.id', 'kol_collaborations.title', 'kol_collaborations.status',
                'kol_collaborations.cooperation_type', 'kol_collaborations.kol_id', 'kols.display_name as kol_name',
                'kol_collaboration_products.quantity', 'kol_collaborations.created_at')
            ->orderByDesc('kol_collaborations.created_at')->orderByDesc('kol_collaborations.id')->limit($limit)->get()
            ->map(fn (object $collaboration): array => [
                'id' => (int) $collaboration->id,
                'title' => $collaboration->title,
                'status' => (string) $collaboration->status,
                'cooperation_type' => $collaboration->cooperation_type,
                'kol_id' => (int) $collaboration->kol_id,
                'kol_name' => $collaboration->kol_name,
                'quantity' => (int) $collaboration->quantity,
                'created_at' => $collaboration->created_at ? (string) $collaboration->created_at : null,
            ])->values()->all();
    }
}
