<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('website_settings', function (Blueprint $table): void {
            $table->decimal('default_commission_rate_percent', 5, 2)
                ->default(20)
                ->after('commission_payout_min_amount');
        });
    }

    public function down(): void
    {
        Schema::table('website_settings', function (Blueprint $table): void {
            $table->dropColumn('default_commission_rate_percent');
        });
    }
};
