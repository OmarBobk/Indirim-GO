<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_clawbacks', function (Blueprint $table): void {
            $table->id();
            $table->string('public_ref', 16)->unique();
            $table->foreignId('commission_id')->constrained('commissions')->restrictOnDelete();
            $table->foreignId('salesperson_id')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('fulfillment_id')->nullable();
            $table->unsignedBigInteger('refund_wallet_transaction_id');
            $table->unsignedBigInteger('original_commission_credit_transaction_id')->nullable();
            $table->unsignedBigInteger('reversal_wallet_transaction_id')->nullable();
            $table->decimal('amount', 10, 2)->unsigned();
            $table->string('currency', 3)->default('USD');
            $table->string('status', 32);
            $table->unsignedSmallInteger('policy_version');
            $table->string('idempotency_key')->unique();
            $table->string('failure_code', 64)->nullable();
            $table->string('failure_message_safe', 255)->nullable();
            $table->timestamp('attempted_at')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamp('needs_review_at')->nullable();
            $table->timestamps();

            $table->foreign('fulfillment_id', 'cc_fulfillment_fk')
                ->references('id')->on('fulfillments')->nullOnDelete();
            $table->foreign('refund_wallet_transaction_id', 'cc_refund_wtx_fk')
                ->references('id')->on('wallet_transactions')->restrictOnDelete();
            $table->foreign('original_commission_credit_transaction_id', 'cc_original_credit_wtx_fk')
                ->references('id')->on('wallet_transactions')->nullOnDelete();
            $table->foreign('reversal_wallet_transaction_id', 'cc_reversal_wtx_fk')
                ->references('id')->on('wallet_transactions')->nullOnDelete();

            $table->unique('reversal_wallet_transaction_id', 'cc_reversal_wtx_unique');
            $table->unique(
                ['commission_id', 'refund_wallet_transaction_id'],
                'cc_commission_refund_unique'
            );
            $table->index(['status', 'created_at', 'id'], 'cc_status_created_idx');
            $table->index(['salesperson_id', 'status', 'created_at', 'id'], 'cc_salesperson_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_clawbacks');
    }
};
