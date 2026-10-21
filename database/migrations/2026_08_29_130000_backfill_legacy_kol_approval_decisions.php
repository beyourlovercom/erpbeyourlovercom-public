<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now()->toDateTimeString();

        DB::table('kols')->orderBy('id')->chunk(500, function ($kols) use ($now): void {
            $decisions = [];
            foreach ($kols as $kol) {
                $decisions[] = [
                    'kol_id' => $kol->id,
                    'policy_id' => null,
                    'outcome' => 'approved',
                    'source' => 'legacy_backfill',
                    'actor_user_id' => null,
                    'policy_mode' => null,
                    'threshold_snapshot' => '{}',
                    'reason_code' => 'legacy_backfill',
                    'reason_text' => null,
                    'decided_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            if ($decisions !== []) {
                DB::table('kol_approval_decisions')->insert($decisions);
            }
        });
    }

    public function down(): void
    {
        DB::table('kol_approval_decisions')->where('source', 'legacy_backfill')->delete();
    }
};
