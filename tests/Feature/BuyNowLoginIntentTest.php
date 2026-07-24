<?php

use App\Support\BuyNowLoginIntent;

it('stores and pulls a product buy-now intent', function (): void {
    BuyNowLoginIntent::store([
        'product_id' => 42,
        'quantity' => 2,
        'requested_amount' => 1000,
    ]);

    expect(session()->get('url.intended'))->toBe(route('home'));

    $intent = BuyNowLoginIntent::pull();

    expect($intent)->toMatchArray([
        'product_id' => 42,
        'quantity' => 2,
        'requested_amount' => 1000,
    ])->and(session()->has(BuyNowLoginIntent::SESSION_KEY))->toBeFalse();
});

it('ignores empty intents', function (): void {
    BuyNowLoginIntent::store([
        'product_id' => 0,
        'package_id' => null,
    ]);

    expect(session()->has(BuyNowLoginIntent::SESSION_KEY))->toBeFalse()
        ->and(BuyNowLoginIntent::pull())->toBeNull();
});

it('stores package overlay intent without product', function (): void {
    BuyNowLoginIntent::store([
        'package_id' => 9,
    ]);

    expect(BuyNowLoginIntent::pull())->toMatchArray([
        'package_id' => 9,
    ]);
});
