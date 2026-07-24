<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payout_batches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('created_by')->constrained('users');
            $table->decimal('total_amount', 12, 2);
            $table->unsignedInteger('commission_count');
            $table->text('notes')->nullable();
            $table->timestamp('paid_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payout_batches');
    }
};
