<?php

namespace App\Modules\Inventory\Experience\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Application\InventoryBatchSyncService;
use App\Modules\Inventory\Application\Sync\ApiAdapterPlaceholder;
use App\Modules\Inventory\Application\Sync\CsvAdapter;
use App\Modules\Inventory\Application\Sync\PasteAdapter;
use App\Modules\Inventory\Application\Sync\XlsxAdapter;
use App\Modules\Inventory\Infrastructure\Models\InventorySyncBatch;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Inventory\Infrastructure\Models\WarehouseInventory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class InventorySyncController extends Controller
{
    public function __construct(private readonly InventoryBatchSyncService $service) {}

    public function index(Request $request): Response
    {
        $warehouses = Warehouse::where('is_active', true)->orderBy('name')->get(['id', 'slug', 'name', 'country_code']);
        $api = new ApiAdapterPlaceholder();

        return Inertia::render('Inventory/Sync', [
            'warehouses'         => $warehouses,
            'apiAvailable'       => $api->isAvailable(),
            'apiStatusLabel'     => $api->statusLabel(),
            'recentBatches'      => InventorySyncBatch::with('warehouse')
                ->orderByDesc('created_at')->limit(10)->get()->map(fn (InventorySyncBatch $batch): array => [
                    'id' => $batch->getKey(),
                    'warehouse_name' => $batch->warehouse?->name,
                    'source_type' => $batch->source_type,
                    'sync_mode' => $batch->sync_mode,
                    'status' => $batch->status,
                    'preview_unique_skus' => $batch->preview_unique_skus,
                    'preview_sellable_qty' => $batch->preview_sellable_qty,
                    'confirmed_at' => $batch->confirmed_at?->toIso8601String(),
                    'created_at' => $batch->created_at?->toIso8601String(),
                ]),
        ]);
    }

    public function preview(Request $request): \Illuminate\Http\JsonResponse
    {
        $request->validate([
            'warehouse_id' => 'required|exists:warehouses,id',
            'source_type'  => 'required|in:paste,csv,xlsx',
            'sync_mode'    => 'required|in:partial,full',
            'stock_count_at'=> 'nullable|date',
        ]);

        $warehouse = Warehouse::findOrFail($request->integer('warehouse_id'));
        $sourceType = $request->string('source_type')->toString();

        $rows = match ($sourceType) {
            'paste' => (new PasteAdapter())->parse($request->string('paste_text', '')->toString()),
            'csv'   => (function () use ($request): array {
                $request->validate(['file' => 'required|file|mimes:csv,txt|max:5120']);
                return (new CsvAdapter())->parse($request->file('file'));
            })(),
            'xlsx'  => (function () use ($request): array {
                $request->validate(['file' => 'required|file|mimes:xlsx|max:10240']);
                return (new XlsxAdapter())->parse($request->file('file'));
            })(),
            default => [],
        };

        if (empty($rows)) {
            return response()->json(['error' => '未解析到有效行，请检查输入格式'], 422);
        }

        $batch = $this->service->preview(
            warehouse:        $warehouse,
            rows:             $rows,
            sourceType:       $sourceType,
            syncMode:         $request->string('sync_mode')->toString(),
            originalFilename: $request->file('file')?->getClientOriginalName(),
            stockCountAt:     $request->string('stock_count_at', '')->toString() ?: null,
            actor:            $request->user(),
        );

        return response()->json($this->formatBatch($batch));
    }

    public function confirm(Request $request, int $batchId): \Illuminate\Http\JsonResponse
    {
        $batch = InventorySyncBatch::findOrFail($batchId);

        if ($batch->warehouse_id !== $request->integer('warehouse_id') && $request->has('warehouse_id')) {
            abort(403, 'Warehouse mismatch');
        }

        $confirmed = $this->service->confirm($batch, $request->user());
        return response()->json($this->formatBatch($confirmed));
    }

    public function history(Request $request): \Illuminate\Http\JsonResponse
    {
        $request->validate(['warehouse_id' => 'nullable|exists:warehouses,id']);
        $query = InventorySyncBatch::with('warehouse', 'creator', 'confirmer')->orderByDesc('created_at');
        if ($request->filled('warehouse_id')) {
            $query->where('warehouse_id', $request->integer('warehouse_id'));
        }
        return response()->json($query->paginate(20));
    }

    public function show(int $batchId): \Illuminate\Http\JsonResponse
    {
        return response()->json($this->formatBatch($this->service->batchDetail($batchId)));
    }

    private function projection(InventorySyncBatch $batch): array
    {
        $rows = $batch->rows()->with('product')->get();
        $groups = [];
        foreach ($rows as $row) {
            if ($row->validation_status === 'error' || $row->product_id === null) continue;
            $pid = (int) $row->product_id;
            $groups[$pid] ??= [
                'product_id' => $pid, 'sku' => $row->product?->sku ?? $row->sku,
                'sellable_qty' => 0, 'defective_qty' => 0,
                'lot_keys' => [], 'sellable_dates' => [],
                'source_present' => true, 'action' => 'replace',
            ];
            if ($row->stock_status === 'sellable') {
                $groups[$pid]['sellable_qty'] += (int) ($row->quantity ?? 0);
                if ($row->putaway_date !== null) $groups[$pid]['sellable_dates'][] = $row->putaway_date->toDateString();
            } elseif ($row->stock_status === 'defective') {
                $groups[$pid]['defective_qty'] += (int) ($row->quantity ?? 0);
            }
            $groups[$pid]['lot_keys'][$row->stock_status.'|'.($row->putaway_date?->toDateString() ?? '')] = true;
        }

        $productIds = array_keys($groups);
        $current = $productIds === [] ? collect() : WarehouseInventory::where('warehouse_id', $batch->warehouse_id)
            ->whereIn('product_id', $productIds)->get()->keyBy('product_id');
        foreach ($groups as $pid => &$group) {
            $reserved = (int) ($current->get($pid)?->quantity_reserved ?? 0);
            $group['lot_count'] = count($group['lot_keys']);
            $group['inbound_at'] = $group['sellable_dates'] !== [] ? min($group['sellable_dates']) : null;
            $group['quantity_reserved'] = $reserved;
            $group['quantity_available_after'] = max($group['sellable_qty'] - $reserved, 0);
            unset($group['lot_keys'], $group['sellable_dates']);
        }
        unset($group);

        $zeroRows = [];
        if ($batch->sync_mode === 'full') {
            $query = WarehouseInventory::with('product')->where('warehouse_id', $batch->warehouse_id)
                ->where('quantity_on_hand', '>', 0);
            if ($productIds !== []) $query->whereNotIn('product_id', $productIds);
            foreach ($query->get() as $inventory) {
                $zeroRows[] = [
                    'product_id' => (int) $inventory->product_id, 'sku' => $inventory->product?->sku,
                    'sellable_qty' => 0, 'defective_qty' => 0, 'lot_count' => 0, 'inbound_at' => null,
                    'quantity_reserved' => (int) $inventory->quantity_reserved, 'quantity_available_after' => 0,
                    'current_sellable_qty' => (int) $inventory->quantity_on_hand,
                    'source_present' => false, 'action' => 'zero',
                ];
            }
        }
        return ['aggregated_rows' => array_values($groups), 'full_snapshot_zero_rows' => $zeroRows];
    }

    private function formatBatch(InventorySyncBatch $batch): array
    {
        $projection = $this->projection($batch);

        return [
            'id'                   => $batch->getKey(),
            'warehouse_id'         => $batch->warehouse_id,
            'warehouse_name'       => $batch->warehouse?->name,
            'source_type'          => $batch->source_type,
            'sync_mode'            => $batch->sync_mode,
            'original_filename'    => $batch->original_filename,
            'stock_count_at'       => $batch->stock_count_at?->toDateString(),
            'status'               => $batch->status,
            'preview_total_rows'   => $batch->preview_total_rows,
            'preview_unique_skus'  => $batch->preview_unique_skus,
            'preview_sellable_qty' => $batch->preview_sellable_qty,
            'preview_defective_qty'=> $batch->preview_defective_qty,
            'preview_unmatched_skus'=>$batch->preview_unmatched_skus,
            'preview_duplicate_skus'=>$batch->preview_duplicate_skus,
            'preview_warning_rows' => $batch->preview_warning_rows,
            'preview_zeroed_skus'  => $batch->preview_zeroed_skus,
            'preview_error_rows'   => $batch->preview_error_rows,
            'has_blockers'         => $batch->preview_error_rows > 0,
            'confirmed_at'         => $batch->confirmed_at?->toIso8601String(),
            'created_at'           => $batch->created_at?->toIso8601String(),
            'aggregated_rows'      => $projection['aggregated_rows'],
            'full_snapshot_zero_rows' => $projection['full_snapshot_zero_rows'],
            'rows'                 => $batch->relationLoaded('rows') ? $batch->rows->map(fn ($r) => [
                'id'                 => $r->getKey(),
                'source_row_no'      => $r->source_row_no,
                'sku'                => $r->sku,
                'stock_status'       => $r->stock_status,
                'putaway_date'       => $r->putaway_date?->toDateString(),
                'quantity'           => $r->quantity,
                'product_id'         => $r->product_id,
                'raw_product_name'   => $r->raw_product_name,
                'raw_warehouse_name' => $r->raw_warehouse_name,
                'raw_stock_status'   => $r->raw_stock_status,
                'raw_dimension'      => $r->raw_dimension,
                'raw_total_volume'   => $r->raw_total_volume,
                'validation_status'  => $r->validation_status,
                'validation_message' => $r->validation_message,
            ])->all() : null,
        ];
    }
}
