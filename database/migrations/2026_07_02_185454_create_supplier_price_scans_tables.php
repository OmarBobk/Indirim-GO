<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_price_scans', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('supplier_key', 32);
            $table->string('status', 32);
            $table->unsignedInteger('products_total')->default(0);
            $table->unsignedInteger('products_ok')->default(0);
            $table->unsignedInteger('products_failed')->default(0);
            $table->string('triggered_by', 32)->default('command');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['supplier_key', 'status']);
        });

        Schema::create('supplier_price_scan_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('supplier_price_scan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('product_api', 2048);
            $table->string('amount_mode', 16);
            $table->unsignedInteger('reference_quantity')->nullable();
            $table->string('status', 16)->default('pending');
            $table->decimal('scanned_price', 16, 8)->nullable();
            $table->string('displayed_raw', 64)->nullable();
            $table->string('error_code', 64)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('scanned_at')->nullable();
            $table->timestamps();

            $table->unique(['supplier_price_scan_id', 'product_id'], 'supplier_price_scan_items_scan_product_unique');
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->decimal('supplier_scanned_price', 16, 8)->nullable()->after('entry_price');
            $table->timestamp('supplier_scanned_at')->nullable()->after('supplier_scanned_price');
            $table->string('supplier_scan_error', 255)->nullable()->after('supplier_scanned_at');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn([
                'supplier_scanned_price',
                'supplier_scanned_at',
                'supplier_scan_error',
            ]);
        });

        Schema::dropIfExists('supplier_price_scan_items');
        Schema::dropIfExists('supplier_price_scans');
    }
};
