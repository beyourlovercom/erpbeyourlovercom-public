<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backup_runs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('run_uuid')->unique();
            $table->string('backup_type', 32);
            $table->string('state', 32)->default('queued');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('error_code', 100)->nullable();
            $table->string('error_message', 500)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['state', 'created_at'], 'backup_runs_state_created_index');
            $table->index(['backup_type', 'created_at'], 'backup_runs_type_created_index');
        });

        Schema::create('backup_artifacts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('backup_run_id')->constrained('backup_runs')->cascadeOnDelete();
            $table->string('kind', 32);
            $table->string('path', 1024);
            $table->unsignedBigInteger('size')->default(0);
            $table->boolean('encrypted')->default(false);
            $table->char('checksum', 64)->comment('SHA-256 of the encrypted artifact at path');
            $table->char('package_checksum', 64)->nullable()->comment('SHA-256 of the internal pre-encryption package');
            $table->string('key_id', 191)->nullable();
            $table->timestamps();
            $table->unique(['backup_run_id', 'kind'], 'backup_artifacts_run_kind_unique');
            $table->index('checksum', 'backup_artifacts_checksum_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_artifacts');
        Schema::dropIfExists('backup_runs');
    }
};
