<?php

namespace App\Modules\Inventory\Application;

use App\Modules\Products\Application\PackagingPresetCatalog;
use Illuminate\Support\Facades\DB;

final class ProductPackagingStandardReader
{
    public function __construct(private readonly PackagingPresetCatalog $presets) {}

    /** @return array<int,array<string,mixed>> */
    public function forProductIds(array $productIds): array
    {
        $ids = collect($productIds)->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();
        if ($ids === []) return [];

        return DB::table('product_packaging_profiles as profile')
            ->join('packaging_measurement_records as measurement', 'measurement.id', '=', 'profile.current_measurement_record_id')
            ->where('profile.is_active', true)
            ->where('measurement.status', 'approved')
            ->whereColumn('measurement.product_id', 'profile.product_id')
            ->whereIn('profile.product_id', $ids)
            ->select([
                'profile.id as profile_id', 'profile.product_id', 'profile.preset_key', 'profile.custom_method',
                'measurement.id as measurement_record_id', 'measurement.packaging_method_key as measurement_method_key',
                'measurement.packaging_length_cm', 'measurement.packaging_width_cm', 'measurement.packaging_height_cm',
                'measurement.packaged_weight_kg', 'measurement.measured_at',
            ])->get()->mapWithKeys(function (object $row): array {
                $methodKey = $row->preset_key ?: $row->measurement_method_key;
                $preset = $this->presets->get($methodKey);

                return [(int) $row->product_id => [
                    'product_id' => (int) $row->product_id,
                    'product_packaging_profile_id' => (int) $row->profile_id,
                    'measurement_record_id' => (int) $row->measurement_record_id,
                    'packaging_method_key' => $methodKey,
                    'packaging_method_label' => $preset['label'] ?? $row->custom_method ?? ($methodKey === 'custom' ? '定制包装方案' : null),
                    'packaging_length_cm' => number_format((float) $row->packaging_length_cm, 2, '.', ''),
                    'packaging_width_cm' => number_format((float) $row->packaging_width_cm, 2, '.', ''),
                    'packaging_height_cm' => number_format((float) $row->packaging_height_cm, 2, '.', ''),
                    'packaged_weight_kg' => number_format((float) $row->packaged_weight_kg, 3, '.', ''),
                    'measured_at' => $row->measured_at,
                ]];
            })->all();
    }
}
