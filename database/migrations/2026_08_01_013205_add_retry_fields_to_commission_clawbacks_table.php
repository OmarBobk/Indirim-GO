<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commission_clawbacks', function (Blueprint $table): void {
            $table->timestamp('last_retry_at')->nullable()->after('needs_review_at');
            $table->unsignedInteger('retry_count')->default(0)->after('last_retry_at');
            $table->index(['status', 'attempted_at', 'id'], 'cc_status_attempted_idx');
            $table->index(['status', 'failure_code'], 'cc_status_failure_idx');
        });
    }

    public function down(): void
    {
        Schema::table('commission_clawbacks', function (Blueprint $table): void {
            $table->dropIndex('cc_status_attempted_idx');
            $table->dropIndex('cc_status_failure_idx');
            $table->dropColumn(['last_retry_at', 'retry_count']);
        });
    }
};
