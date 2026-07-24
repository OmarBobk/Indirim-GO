<?php

declare(strict_types=1);

use App\Enums\WalletTransactionType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $existing = DB::table('wallet_transactions')
            ->distinct()
            ->pluck('type')
            ->map(fn ($value) => (string) $value)
            ->filter()
            ->values()
            ->all();

        $values = array_unique(array_merge(
            $existing ?: WalletTransactionType::values(),
            [WalletTransactionType::CommissionCredit->value]
        ));
        $enum = implode("','", array_map(fn ($value) => addslashes((string) $value), $values));

        DB::statement("ALTER TABLE wallet_transactions MODIFY COLUMN type ENUM('{$enum}') NOT NULL");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $existing = DB::table('wallet_transactions')
            ->distinct()
            ->pluck('type')
            ->map(fn ($value) => (string) $value)
            ->filter()
            ->values()
            ->all();

        $values = array_values(array_filter(
            $existing,
            fn ($value) => $value !== WalletTransactionType::CommissionCredit->value
        ));
        $enum = implode("','", array_map(fn ($value) => addslashes((string) $value), $values));

        if ($values !== []) {
            DB::statement("ALTER TABLE wallet_transactions MODIFY COLUMN type ENUM('{$enum}') NOT NULL");
        }
    }
};
