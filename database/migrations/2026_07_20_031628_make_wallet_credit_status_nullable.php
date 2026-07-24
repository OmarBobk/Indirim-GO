<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * credit_status is operational state of a *granted* facility only:
     * - credit_enabled=false → credit_status must be null
     * - credit_enabled=true → credit_status must be active|suspended
     */
    public function up(): void
    {
        Schema::table('wallets', function (Blueprint $table): void {
            $table->string('credit_status', 20)->nullable()->default(null)->change();
        });

        DB::table('wallets')
            ->where('credit_enabled', false)
            ->update(['credit_status' => null]);

        DB::table('wallets')
            ->where('credit_enabled', true)
            ->whereNull('credit_status')
            ->update(['credit_status' => 'active']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('wallets')
            ->whereNull('credit_status')
            ->update(['credit_status' => 'active']);

        Schema::table('wallets', function (Blueprint $table): void {
            $table->string('credit_status', 20)->nullable(false)->default('active')->change();
        });
    }
};
