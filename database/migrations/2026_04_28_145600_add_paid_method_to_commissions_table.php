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
            $table->string('paid_method', 50)->nullable()->after('paid_at');
        });
    }

    public function down(): void
    {
        Schema::table('commissions', function (Blueprint $table): void {
            $table->dropColumn('paid_method');
        });
    }
};
