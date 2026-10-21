<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration { public function up(): void { Schema::create('audit_records', function (Blueprint $table) { $table->id(); $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete(); $table->string('action'); $table->string('entity_type'); $table->string('entity_id')->nullable(); $table->json('before')->nullable(); $table->json('after')->nullable(); $table->string('source')->nullable(); $table->uuid('request_id')->nullable(); $table->ipAddress('ip_address')->nullable(); $table->text('user_agent')->nullable(); $table->text('reason')->nullable(); $table->timestamp('created_at')->useCurrent(); }); } public function down(): void { Schema::dropIfExists('audit_records'); } };
