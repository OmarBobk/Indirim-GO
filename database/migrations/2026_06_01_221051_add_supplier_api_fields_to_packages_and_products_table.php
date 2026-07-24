<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('packages', function (Blueprint $table): void {
            $table->string('package_api', 2048)->nullable()->after('fulfillment_provider');
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->string('product_api', 2048)->nullable()->after('slug');
        });
    }

    public function down(): void
    {
        Schema::table('packages', function (Blueprint $table): void {
            $table->dropColumn('package_api');
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn('product_api');
        });
    }
};
