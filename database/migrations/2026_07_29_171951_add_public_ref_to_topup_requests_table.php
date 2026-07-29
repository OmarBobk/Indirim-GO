<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M6.3: immutable customer-facing top-up reference (TUP-XXXXXXXXXX).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('topup_requests', function (Blueprint $table): void {
            $table->string('public_ref', 16)->nullable()->after('id');
        });

        $this->backfillPublicRefs();

        Schema::table('topup_requests', function (Blueprint $table): void {
            $table->unique('public_ref');
            $table->index(
                ['user_id', 'status', 'updated_at', 'id'],
                'topup_requests_user_status_updated_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::table('topup_requests', function (Blueprint $table): void {
            $table->dropIndex('topup_requests_user_status_updated_idx');
            $table->dropUnique(['public_ref']);
            $table->dropColumn('public_ref');
        });
    }

    private function backfillPublicRefs(): void
    {
        DB::table('topup_requests')
            ->whereNull('public_ref')
            ->orderBy('id')
            ->chunkById(200, function ($rows): void {
                foreach ($rows as $row) {
                    DB::table('topup_requests')
                        ->where('id', $row->id)
                        ->update(['public_ref' => $this->uniquePublicRef()]);
                }
            });
    }

    private function uniquePublicRef(): string
    {
        do {
            $ref = 'TUP-'.strtoupper(bin2hex(random_bytes(5)));
        } while (DB::table('topup_requests')->where('public_ref', $ref)->exists());

        return $ref;
    }
};
