<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_supplier_circuits', function (Blueprint $table): void {
            $table->id();
            $table->string('supplier_key', 64);
            $table->string('capability', 32);
            $table->string('state', 32)->default('enabled');
            $table->string('reason_code', 96)->nullable();
            $table->string('safe_reason_context', 255)->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->foreignId('opened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('opened_source', 32)->nullable();
            $table->timestamp('last_failure_at')->nullable();
            $table->unsignedInteger('consecutive_failure_count')->default(0);
            $table->timestamp('failure_window_started_at')->nullable();
            $table->json('recent_signal_keys')->nullable();
            $table->timestamp('last_probe_at')->nullable();
            $table->string('last_probe_state', 64)->nullable();
            $table->timestamp('last_healthy_at')->nullable();
            $table->timestamp('resumed_at')->nullable();
            $table->foreignId('resumed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('resume_source', 32)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['supplier_key', 'capability'], 'automation_circuits_supplier_capability_unique');
            $table->index(['supplier_key', 'state'], 'automation_circuits_supplier_state_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_supplier_circuits');
    }
};
