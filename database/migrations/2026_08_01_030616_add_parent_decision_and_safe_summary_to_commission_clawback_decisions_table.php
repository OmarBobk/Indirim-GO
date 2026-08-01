<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commission_clawback_decisions', function (Blueprint $table): void {
            $table->unsignedBigInteger('parent_decision_id')->nullable()->after('commission_clawback_id');
            $table->string('safe_resolution_summary', 300)->nullable()->after('admin_note');

            $table->foreign('parent_decision_id', 'ccd_parent_decision_fk')
                ->references('id')
                ->on('commission_clawback_decisions')
                ->nullOnDelete();

            $table->index(['commission_clawback_id', 'type', 'status', 'id'], 'ccd_clawback_type_status_idx');
            $table->index(['parent_decision_id'], 'ccd_parent_decision_idx');
        });
    }

    public function down(): void
    {
        Schema::table('commission_clawback_decisions', function (Blueprint $table): void {
            $table->dropForeign('ccd_parent_decision_fk');
            $table->dropIndex('ccd_clawback_type_status_idx');
            $table->dropIndex('ccd_parent_decision_idx');
            $table->dropColumn(['parent_decision_id', 'safe_resolution_summary']);
        });
    }
};
