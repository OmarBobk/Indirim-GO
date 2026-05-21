<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commissions', function (Blueprint $table): void {
            $table->foreignId('payout_batch_id')
                ->nullable()
                ->after('paid_method')
                ->constrained('payout_batches')
                ->nullOnDelete();

            $table->index('payout_batch_id');
        });
    }

    public function down(): void
    {
        Schema::table('commissions', function (Blueprint $table): void {
            $table->dropIndex(['payout_batch_id']);
            $table->dropConstrainedForeignId('payout_batch_id');
        });
    }
};
