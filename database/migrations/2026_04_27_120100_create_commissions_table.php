<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commissions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained();
            $table->foreignId('salesperson_id')->constrained('users');
            $table->foreignId('customer_id')->constrained('users');
            $table->string('referral_code', 16);
            $table->decimal('order_total', 12, 2);
            $table->decimal('commission_amount', 12, 2);
            $table->string('status', 20);
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index('order_id');
            $table->index('salesperson_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commissions');
    }
};
