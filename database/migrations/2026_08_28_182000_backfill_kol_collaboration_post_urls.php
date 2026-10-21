<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('kol_collaborations') || ! Schema::hasColumn('kol_collaborations', 'post_url')) return;

        Schema::table('kol_collaborations', function ($table): void {
            $table->string('title')->nullable()->change();
        });

        DB::table('kol_collaborations')
            ->whereNull('post_url')
            ->where(function ($query): void {
                $query->where('title', 'like', 'http://%')->orWhere('title', 'like', 'https://%');
            })
            ->orderBy('id')
            ->eachById(function ($row): void {
                DB::table('kol_collaborations')->where('id', $row->id)->update([
                    'post_url' => $row->title,
                    'title' => null,
                ]);
            });
    }

    public function down(): void {}
};
