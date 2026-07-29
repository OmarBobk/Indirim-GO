<?php

declare(strict_types=1);

use App\Exceptions\InvalidWalletPostingAmountException;
use App\Support\LedgerMoney;

test('ledger money normalizes to two decimal places', function () {
    expect(LedgerMoney::normalize('1.2'))->toBe('1.20')
        ->and(LedgerMoney::normalizePositive('1.23'))->toBe('1.23');
});

test('ledger money rejects scientific notation zero negative and overflow', function (string $value) {
    expect(fn () => LedgerMoney::normalizePositive($value))
        ->toThrow(InvalidWalletPostingAmountException::class);
})->with([
    'scientific' => '1e3',
    'zero' => '0.00',
    'negative' => '-5.00',
    'overflow' => '100000000.00',
    'blank' => '',
]);
