<?php

use App\Modules\Orders\Application\OrderKindBackfill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('order_kind', 32)->nullable()->after('source_type');
            $table->index(['order_kind', 'order_date']);
        });

        OrderKindBackfill::apply();
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex(['order_kind', 'order_date']);
            $table->dropColumn('order_kind');
        });
    }
};
