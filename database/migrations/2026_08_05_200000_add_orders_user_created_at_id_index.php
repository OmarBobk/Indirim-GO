<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Composite index for mobile customer order history:
 * WHERE user_id = ? ORDER BY created_at DESC, id DESC
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->index(
                ['user_id', 'created_at', 'id'],
                'orders_user_id_created_at_id_index'
            );
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex('orders_user_id_created_at_id_index');
        });
    }
};
