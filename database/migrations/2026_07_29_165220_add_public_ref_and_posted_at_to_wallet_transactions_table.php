<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M6.2: customer-safe public reference + posting timestamp for the ledger.
 * posted_at is when money moved (set on createPosted / promotePending).
 * Historical posted rows backfill posted_at = created_at.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallet_transactions', function (Blueprint $table): void {
            $table->string('public_ref', 16)->nullable()->after('idempotency_key');
            $table->timestamp('posted_at')->nullable()->after('created_at');
        });

        $this->backfillPostedRows();

        Schema::table('wallet_transactions', function (Blueprint $table): void {
            $table->unique('public_ref');
            $table->index(
                ['wallet_id', 'status', 'posted_at', 'id'],
                'wallet_transactions_wallet_status_posted_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::table('wallet_transactions', function (Blueprint $table): void {
            $table->dropIndex('wallet_transactions_wallet_status_posted_idx');
            $table->dropUnique(['public_ref']);
            $table->dropColumn(['public_ref', 'posted_at']);
        });
    }

    private function backfillPostedRows(): void
    {
        DB::table('wallet_transactions')
            ->where('status', 'posted')
            ->whereNull('posted_at')
            ->orderBy('id')
            ->chunkById(200, function ($rows): void {
                foreach ($rows as $row) {
                    $ref = $this->uniquePublicRef();

                    DB::table('wallet_transactions')
                        ->where('id', $row->id)
                        ->update([
                            'public_ref' => $ref,
                            'posted_at' => $row->created_at,
                        ]);
                }
            });
    }

    private function uniquePublicRef(): string
    {
        do {
            $ref = 'WTX-'.strtoupper(bin2hex(random_bytes(5)));
        } while (DB::table('wallet_transactions')->where('public_ref', $ref)->exists());

        return $ref;
    }
};
