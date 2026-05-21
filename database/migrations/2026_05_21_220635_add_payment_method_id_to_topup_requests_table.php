<?php

declare(strict_types=1);

use App\Enums\TopupMethod;
use App\Models\PaymentMethod;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('topup_requests', function (Blueprint $table): void {
            $table->foreignId('payment_method_id')
                ->nullable()
                ->after('wallet_id')
                ->constrained('payment_methods')
                ->restrictOnDelete();
        });

        $legacyMap = [
            TopupMethod::ShamCash->value => [
                'name' => 'Sham Cash',
                'sort_order' => 0,
            ],
            TopupMethod::EftTransfer->value => [
                'name' => 'EFT Transfer',
                'sort_order' => 1,
            ],
        ];

        $methodIdsBySlug = [];

        foreach ($legacyMap as $slug => $defaults) {
            $existing = PaymentMethod::query()->where('name', $defaults['name'])->first();

            if ($existing === null) {
                $existing = PaymentMethod::query()->create([
                    'name' => $defaults['name'],
                    'account_text' => $defaults['name'],
                    'is_active' => true,
                    'sort_order' => $defaults['sort_order'],
                ]);
            }

            $methodIdsBySlug[$slug] = $existing->id;
        }

        foreach ($methodIdsBySlug as $slug => $paymentMethodId) {
            DB::table('topup_requests')
                ->where('method', $slug)
                ->update(['payment_method_id' => $paymentMethodId]);
        }

        Schema::table('topup_requests', function (Blueprint $table): void {
            $table->dropColumn('method');
        });
    }

    public function down(): void
    {
        Schema::table('topup_requests', function (Blueprint $table): void {
            $table->enum('method', TopupMethod::values())->after('wallet_id');
        });

        $methods = PaymentMethod::query()->orderBy('sort_order')->get();

        foreach ($methods as $method) {
            $slug = match ($method->name) {
                'Sham Cash' => TopupMethod::ShamCash->value,
                'EFT Transfer' => TopupMethod::EftTransfer->value,
                default => null,
            };

            if ($slug === null) {
                continue;
            }

            DB::table('topup_requests')
                ->where('payment_method_id', $method->id)
                ->update(['method' => $slug]);
        }

        Schema::table('topup_requests', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('payment_method_id');
        });
    }
};
