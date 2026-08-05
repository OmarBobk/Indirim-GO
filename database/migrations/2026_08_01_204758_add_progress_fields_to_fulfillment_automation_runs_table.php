<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fulfillment_automation_runs', function (Blueprint $table): void {
            $table->unsignedInteger('progress_sequence')->default(0)->after('meta');
            $table->timestamp('last_heartbeat_at')->nullable()->after('progress_sequence');
            $table->timestamp('current_step_started_at')->nullable()->after('last_heartbeat_at');
            $table->json('progress_snapshot')->nullable()->after('current_step_started_at');

            $table->index('last_heartbeat_at');
            $table->index('current_step_started_at');
        });

        Schema::create('fulfillment_automation_run_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('run_id')
                ->constrained('fulfillment_automation_runs')
                ->cascadeOnDelete();
            $table->unsignedInteger('sequence');
            $table->string('phase', 32);
            $table->string('step', 64);
            $table->string('safe_message_code', 100)->nullable();
            $table->json('safe_params')->nullable();
            $table->timestamp('occurred_at');
            $table->string('worker_instance_id', 64)->nullable();
            $table->string('worker_build', 100)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['run_id', 'sequence']);
            $table->index(['run_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fulfillment_automation_run_events');

        Schema::table('fulfillment_automation_runs', function (Blueprint $table): void {
            $table->dropIndex(['last_heartbeat_at']);
            $table->dropIndex(['current_step_started_at']);
            $table->dropColumn([
                'progress_sequence',
                'last_heartbeat_at',
                'current_step_started_at',
                'progress_snapshot',
            ]);
        });
    }
};
