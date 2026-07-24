<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('wallets', function (Blueprint $table): void {
            // Null when facility is not granted (credit_enabled=false); Active/Suspended only when granted.
            $table->string('credit_status', 20)->nullable()->default(null)->after('payment_terms_days');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('wallets', function (Blueprint $table): void {
            $table->dropColumn('credit_status');
        });
    }
};
