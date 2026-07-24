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
            $table->decimal('commission_payout_min_amount', 12, 2)
                ->default(200)
                ->after('commission_payout_wait_days');
        });
    }

    public function down(): void
    {
        Schema::table('website_settings', function (Blueprint $table): void {
            $table->dropColumn('commission_payout_min_amount');
        });
    }
};
