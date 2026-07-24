<?php

declare(strict_types=1);

use App\Enums\FulfillmentAutomationRunStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fulfillment_automation_runs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('fulfillment_id')->constrained('fulfillments')->cascadeOnDelete();
            $table->string('supplier_key');
            $table->enum('status', FulfillmentAutomationRunStatus::values())->default(FulfillmentAutomationRunStatus::Reserved->value);
            $table->unsignedSmallInteger('attempt')->default(1);
            $table->string('idempotency_key')->unique();
            $table->string('external_order_id')->nullable();
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->json('result_payload')->nullable();
            $table->json('log_excerpt')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('callback_received_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['fulfillment_id', 'status']);
            $table->index('supplier_key');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fulfillment_automation_runs');
    }
};
