<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Indexed attempt identity for mobile checkout reconciliation.
 * Prefer this column over scanning/locking every paid order for the customer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('mobile_attempt_key_hash', 64)->nullable()->after('meta');
            $table->index(
                ['user_id', 'mobile_attempt_key_hash'],
                'orders_user_id_mobile_attempt_key_hash_index'
            );
        });

        // Backfill from meta for any already-linked mobile orders (MySQL/MariaDB/SQLite JSON path).
        if (Schema::hasColumn('orders', 'mobile_attempt_key_hash')) {
            $driver = Schema::getConnection()->getDriverName();

            if (in_array($driver, ['mysql', 'mariadb'], true)) {
                DB::statement("
                    UPDATE orders
                    SET mobile_attempt_key_hash = JSON_UNQUOTE(JSON_EXTRACT(meta, '$.mobile_attempt_key_hash'))
                    WHERE mobile_attempt_key_hash IS NULL
                      AND JSON_EXTRACT(meta, '$.mobile_attempt_key_hash') IS NOT NULL
                ");
            } elseif ($driver === 'sqlite') {
                DB::statement("
                    UPDATE orders
                    SET mobile_attempt_key_hash = json_extract(meta, '$.mobile_attempt_key_hash')
                    WHERE mobile_attempt_key_hash IS NULL
                      AND json_extract(meta, '$.mobile_attempt_key_hash') IS NOT NULL
                ");
            }
        }
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex('orders_user_id_mobile_attempt_key_hash_index');
            $table->dropColumn('mobile_attempt_key_hash');
        });
    }
};
