<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('website_settings', function (Blueprint $table) {
            $table->string('wasim_automation_username')->nullable()->after('automation_enabled');
            $table->text('wasim_automation_password')->nullable()->after('wasim_automation_username');
        });
    }

    public function down(): void
    {
        Schema::table('website_settings', function (Blueprint $table) {
            $table->dropColumn(['wasim_automation_username', 'wasim_automation_password']);
        });
    }
};
