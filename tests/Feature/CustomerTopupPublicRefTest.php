<?php

declare(strict_types=1);

use App\Support\TopupRequestPublicRef;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('generates and validates tup public references', function (): void {
    $ref = TopupRequestPublicRef::generate();

    expect(TopupRequestPublicRef::isValidFormat($ref))->toBeTrue()
        ->and(TopupRequestPublicRef::normalize(' tup-abcdef0123 '))->toBe('TUP-ABCDEF0123')
        ->and(TopupRequestPublicRef::isValidFormat('WTX-ABCDEF0123'))->toBeFalse()
        ->and(TopupRequestPublicRef::isValidFormat('123'))->toBeFalse();
});

it('allocates unique public references', function (): void {
    $a = TopupRequestPublicRef::allocateUnique();
    $b = TopupRequestPublicRef::allocateUnique();

    expect($a)->not->toBe($b)
        ->and(TopupRequestPublicRef::isValidFormat($a))->toBeTrue();
});
