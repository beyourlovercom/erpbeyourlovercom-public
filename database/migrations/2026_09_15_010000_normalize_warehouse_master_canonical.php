<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        $now = now();
        $canonical = [
            'US-NY' => ['name' => '美东纽约仓', 'country_code' => 'US'],
            'US-LA' => ['name' => '美西 MorePlus 仓', 'country_code' => 'US'],
            'DE' => ['name' => '德国天运仓', 'country_code' => 'DE'],
            'CA' => ['name' => '加拿大仓', 'country_code' => 'CA'],
            'FACTORY' => ['name' => '中国工厂', 'country_code' => 'CN'],
            '007' => ['name' => '007 本地仓库', 'country_code' => 'CN'],
        ];

        foreach ($canonical as $code => $attributes) {
            DB::table('warehouses')->where('code', $code)->update([
                ...$attributes,
                'updated_at' => $now,
            ]);
        }

        $threeSm = DB::table('warehouses')
            ->whereIn('code', ['US-SM', 'US-SJ'])
            ->orWhereIn('slug', ['us-3sm', 'us-sj', 'us-sm', 'usa-sj'])
            ->orderBy('id')
            ->first();

        if ($threeSm) {
            DB::table('warehouses')->where('id', $threeSm->id)->update([
                'slug' => 'us-3sm',
                'code' => 'US-SM',
                'name' => '美国3世迈仓',
                'country_code' => 'US',
                'is_active' => true,
                'updated_at' => $now,
            ]);
        } else {
            DB::table('warehouses')->insert([
                'slug' => 'us-3sm',
                'code' => 'US-SM',
                'name' => '美国3世迈仓',
                'country_code' => 'US',
                'type' => 'warehouse',
                'ownership_type' => 'external',
                'is_domestic' => false,
                'is_active' => true,
                'priority' => 25,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        $now = now();
        DB::table('warehouses')->where('code', 'US-SM')->update([
            'slug' => 'us-sj',
            'code' => 'US-SJ',
            'name' => '美西加州3仓',
            'updated_at' => $now,
        ]);
        foreach ([
            'US-NY' => '美东纽约仓',
            'US-LA' => '美西洛杉矶仓',
            'DE' => '德国仓',
            'CA' => '加拿大仓库',
            'FACTORY' => '中国工厂',
            '007' => '007本地仓库',
        ] as $code => $name) {
            DB::table('warehouses')->where('code', $code)->update(['name' => $name, 'updated_at' => $now]);
        }
    }
};
