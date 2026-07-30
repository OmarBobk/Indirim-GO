<?php

declare(strict_types=1);

use App\Support\WalletTransactionPublicRef;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M6.4: assign WTX public_ref to refund workflow rows that lack one (pending/rejected historical).
 * Posted rows were backfilled in M6.2; promote preserves an existing public_ref.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('wallet_transactions')
            ->where('type', 'refund')
            ->whereNull('public_ref')
            ->orderBy('id')
            ->chunkById(200, function ($rows): void {
                foreach ($rows as $row) {
                    DB::table('wallet_transactions')
                        ->where('id', $row->id)
                        ->update(['public_ref' => $this->uniquePublicRef()]);
                }
            });
    }

    public function down(): void
    {
        // Immutable customer references are retained.
    }

    private function uniquePublicRef(): string
    {
        do {
            $ref = WalletTransactionPublicRef::generate();
        } while (DB::table('wallet_transactions')->where('public_ref', $ref)->exists());

        return $ref;
    }
};
