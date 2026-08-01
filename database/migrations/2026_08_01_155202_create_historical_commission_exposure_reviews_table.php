<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('historical_commission_exposure_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('commission_id')->constrained('commissions')->restrictOnDelete();
            $table->unsignedBigInteger('refund_wallet_transaction_id');
            $table->string('outcome', 64);
            $table->string('reason_code', 64);
            $table->string('admin_note', 500)->nullable();
            $table->foreignId('reviewed_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('reviewed_at');
            $table->timestamps();

            $table->foreign('refund_wallet_transaction_id', 'hcer_refund_wtx_fk')
                ->references('id')
                ->on('wallet_transactions')
                ->restrictOnDelete();

            $table->unique(
                ['commission_id', 'refund_wallet_transaction_id'],
                'hcer_commission_refund_unique'
            );
            $table->index(['outcome', 'reviewed_at', 'id'], 'hcer_outcome_reviewed_idx');
            $table->index(['reviewed_by', 'reviewed_at'], 'hcer_reviewer_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('historical_commission_exposure_reviews');
    }
};
