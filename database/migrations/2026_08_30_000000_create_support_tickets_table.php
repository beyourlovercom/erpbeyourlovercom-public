<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('support_tickets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('subject');
            $table->text('description')->nullable();
            $table->string('status');
            $table->string('priority');
            $table->string('source_type');
            $table->string('source_provider')->nullable();
            $table->string('external_ticket_id')->nullable();
            $table->string('requester_name')->nullable();
            $table->string('requester_email')->nullable();
            $table->string('requester_phone')->nullable();
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('status_updated_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamps();
            $table->index(['customer_id', 'status']);
            $table->index(['assigned_user_id', 'status']);
            $table->index(['status', 'priority']);
            $table->unique(['source_provider', 'external_ticket_id']);
        });
    }

    public function down(): void { Schema::dropIfExists('support_tickets'); }
};
