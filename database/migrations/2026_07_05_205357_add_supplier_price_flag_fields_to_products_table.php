<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->string('supplier_price_flag_reason', 64)->nullable()->after('supplier_scan_error');
            $table->timestamp('supplier_price_flagged_at')->nullable()->after('supplier_price_flag_reason');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn([
                'supplier_price_flag_reason',
                'supplier_price_flagged_at',
            ]);
        });
    }
};
