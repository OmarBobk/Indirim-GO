<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commissions', function (Blueprint $table): void {
            $table->decimal('commission_rate_percent', 5, 2)
                ->default(20)
                ->after('commission_amount');
        });
    }

    public function down(): void
    {
        Schema::table('commissions', function (Blueprint $table): void {
            $table->dropColumn('commission_rate_percent');
        });
    }
};
