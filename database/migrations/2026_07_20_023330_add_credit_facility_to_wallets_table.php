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
            $table->boolean('credit_enabled')->default(false)->after('currency');
            $table->decimal('credit_limit', 10, 2)->default(0)->after('credit_enabled');
            $table->unsignedSmallInteger('payment_terms_days')->nullable()->after('credit_limit');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('wallets', function (Blueprint $table): void {
            $table->dropColumn(['credit_enabled', 'credit_limit', 'payment_terms_days']);
        });
    }
};
