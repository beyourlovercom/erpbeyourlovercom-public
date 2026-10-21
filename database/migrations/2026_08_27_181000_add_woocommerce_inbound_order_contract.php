<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('currency', 3)->default('USD')->after('order_note');
        });

        Schema::table('order_external_references', function (Blueprint $table): void {
            $table->dropUnique(['provider', 'reference']);
            $table->string('connection_key', 127)->default('default')->after('provider');
            $table->timestamp('provider_modified_at')->nullable()->after('reference');
            $table->unique(['provider', 'connection_key', 'reference'], 'order_external_references_provider_connection_reference_unique');
        });
    }

    public function down(): void
    {
        Schema::table('order_external_references', function (Blueprint $table): void {
            $table->dropUnique('order_external_references_provider_connection_reference_unique');
            $table->dropColumn(['connection_key', 'provider_modified_at']);
            $table->unique(['provider', 'reference']);
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn('currency');
        });
    }
};
