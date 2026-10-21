<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        foreach (['POSTNL international', 'Weship', 'KingTrans'] as $name) {
            DB::table('logistics_carriers')->updateOrInsert(
                ['name' => $name],
                ['is_active' => true, 'updated_at' => $now, 'created_at' => $now],
            );
        }
    }

    public function down(): void
    {
        // Operational carrier master rows are preserved on rollback to avoid orphaning Shipment history.
    }
};
