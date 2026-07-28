<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ensure fulfillments.order_id / order_item_id are indexed for Activity eager loads.
     * Some environments lost these keys while keeping foreign keys.
     */
    public function up(): void
    {
        if (! $this->hasIndexOnColumn('fulfillments', 'order_id')) {
            Schema::table('fulfillments', function (Blueprint $table): void {
                $table->index('order_id', 'fulfillments_order_id_index');
            });
        }

        if (! $this->hasIndexOnColumn('fulfillments', 'order_item_id')) {
            Schema::table('fulfillments', function (Blueprint $table): void {
                $table->index('order_item_id', 'fulfillments_order_item_id_index');
            });
        }
    }

    public function down(): void
    {
        if ($this->hasNamedIndex('fulfillments', 'fulfillments_order_id_index')) {
            Schema::table('fulfillments', function (Blueprint $table): void {
                $table->dropIndex('fulfillments_order_id_index');
            });
        }

        if ($this->hasNamedIndex('fulfillments', 'fulfillments_order_item_id_index')) {
            Schema::table('fulfillments', function (Blueprint $table): void {
                $table->dropIndex('fulfillments_order_item_id_index');
            });
        }
    }

    private function hasIndexOnColumn(string $table, string $column): bool
    {
        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            $result = DB::selectOne(
                'SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? AND seq_in_index = 1 LIMIT 1',
                [$table, $column]
            );

            return $result !== null;
        }

        if ($driver === 'sqlite') {
            $indexes = DB::select("PRAGMA index_list('{$table}')");
            foreach ($indexes as $index) {
                $name = $index->name ?? '';
                if ($name === '') {
                    continue;
                }
                $cols = DB::select("PRAGMA index_info('{$name}')");
                foreach ($cols as $col) {
                    if (($col->name ?? null) === $column && (int) ($col->seqno ?? 1) === 0) {
                        return true;
                    }
                }
            }

            return false;
        }

        return Schema::hasColumn($table, $column);
    }

    private function hasNamedIndex(string $table, string $indexName): bool
    {
        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            $result = DB::selectOne(
                'SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ? LIMIT 1',
                [$table, $indexName]
            );

            return $result !== null;
        }

        if ($driver === 'sqlite') {
            $indexes = DB::select("PRAGMA index_list('{$table}')");
            foreach ($indexes as $index) {
                if (($index->name ?? null) === $indexName) {
                    return true;
                }
            }

            return false;
        }

        return false;
    }
};
