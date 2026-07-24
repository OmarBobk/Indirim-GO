<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commissions', function (Blueprint $table): void {
            $table->foreignId('wallet_transaction_id')
                ->nullable()
                ->after('payout_batch_id')
                ->constrained('wallet_transactions')
                ->nullOnDelete();

            $table->unique('wallet_transaction_id');
        });

        DB::table('commissions')
            ->where('status', 'paid')
            ->update(['status' => 'credited']);
    }

    public function down(): void
    {
        DB::table('commissions')
            ->where('status', 'credited')
            ->update(['status' => 'paid']);

        Schema::table('commissions', function (Blueprint $table): void {
            $table->dropUnique(['wallet_transaction_id']);
            $table->dropConstrainedForeignId('wallet_transaction_id');
        });
    }
};
