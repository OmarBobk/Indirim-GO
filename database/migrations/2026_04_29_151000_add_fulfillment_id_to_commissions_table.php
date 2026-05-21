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
        $driver = (string) DB::connection()->getDriverName();

        if (! Schema::hasColumn('commissions', 'fulfillment_id')) {
            Schema::table('commissions', function (Blueprint $table): void {
                $table->foreignId('fulfillment_id')
                    ->nullable()
                    ->after('order_id')
                    ->constrained()
                    ->cascadeOnDelete();
            });
        }

        if (
            $driver !== 'sqlite'
            && $this->indexExists('commissions', 'commissions_order_id_unique')
        ) {
            Schema::table('commissions', function (Blueprint $table): void {
                $table->dropUnique(['order_id']);
            });
        }

        if (! $this->indexExists('commissions', 'commissions_fulfillment_id_unique')) {
            Schema::table('commissions', function (Blueprint $table): void {
                $table->unique('fulfillment_id');
            });
        }
    }

    public function down(): void
    {
        $driver = (string) DB::connection()->getDriverName();

        if ($this->indexExists('commissions', 'commissions_fulfillment_id_unique')) {
            Schema::table('commissions', function (Blueprint $table): void {
                $table->dropUnique(['fulfillment_id']);
            });
        }

        if (Schema::hasColumn('commissions', 'fulfillment_id')) {
            Schema::table('commissions', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('fulfillment_id');
            });
        }

        if (
            $driver !== 'sqlite'
            && ! $this->indexExists('commissions', 'commissions_order_id_unique')
        ) {
            Schema::table('commissions', function (Blueprint $table): void {
                $table->unique('order_id');
            });
        }
    }

    private function indexExists(string $table, string $indexName): bool
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return false;
        }

        $database = (string) DB::connection()->getDatabaseName();
        $row = DB::selectOne(
            'SELECT 1 FROM information_schema.statistics WHERE table_schema = ? AND table_name = ? AND index_name = ? LIMIT 1',
            [$database, $table, $indexName]
        );

        return $row !== null;
    }
};
