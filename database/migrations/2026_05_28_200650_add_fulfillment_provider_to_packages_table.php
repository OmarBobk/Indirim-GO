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
            $table->string('fulfillment_provider')->nullable()->after('is_active');
            $table->index('fulfillment_provider');
        });
    }

    public function down(): void
    {
        Schema::table('packages', function (Blueprint $table): void {
            $table->dropIndex(['fulfillment_provider']);
            $table->dropColumn('fulfillment_provider');
        });
    }
};
