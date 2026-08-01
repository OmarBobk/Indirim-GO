<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_clawback_decisions', function (Blueprint $table): void {
            $table->id();
            $table->string('public_ref', 16)->unique();
            $table->foreignId('commission_clawback_id')
                ->constrained('commission_clawbacks')
                ->restrictOnDelete();
            $table->string('type', 32);
            $table->string('status', 32);
            $table->decimal('amount', 10, 2)->unsigned()->nullable();
            $table->string('reason_code', 64);
            $table->string('admin_note', 500)->nullable();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('related_wallet_transaction_id')->nullable();
            $table->string('idempotency_key')->unique();
            $table->timestamp('decided_at');
            $table->timestamps();

            $table->foreign('related_wallet_transaction_id', 'ccd_related_wtx_fk')
                ->references('id')->on('wallet_transactions')->nullOnDelete();
            $table->unique('related_wallet_transaction_id', 'ccd_related_wtx_unique');
            $table->index(['commission_clawback_id', 'type', 'decided_at', 'id'], 'ccd_clawback_type_decided_idx');
            $table->index(['actor_id', 'decided_at'], 'ccd_actor_decided_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_clawback_decisions');
    }
};
