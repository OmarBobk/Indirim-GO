<?php

declare(strict_types=1);

use App\Models\User;

it('documents intentional storefront page width tiers', function () {
    expect(config('storefront.page_widths'))->toMatchArray([
        'browse' => '7xl',
        'work' => '4xl',
        'work-wide' => '5xl',
        'focus' => '2xl',
    ]);
});

it('applies the work page contract on root account tabs without a back button', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('account'))
        ->assertOk()
        ->assertSee('data-storefront-page="work"', false)
        ->assertSee('data-test="storefront-page-header"', false)
        ->assertDontSee('data-test="back-button"', false);

    $this->actingAs($user)
        ->get(route('orders.index'))
        ->assertOk()
        ->assertSee('data-storefront-page="work"', false)
        ->assertSee('data-test="orders-header"', false)
        ->assertDontSee('data-test="back-button"', false);

    $this->actingAs($user)
        ->get(route('wallet'))
        ->assertOk()
        ->assertSee('data-storefront-page="work"', false)
        ->assertDontSee('data-test="back-button"', false);
});

it('uses focus width and a back button on wallet top-up', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('wallet.topup'))
        ->assertOk()
        ->assertSee('data-storefront-page="focus"', false)
        ->assertSee('data-test="storefront-page-header"', false)
        ->assertSee('data-test="back-button"', false);
});

it('uses browse width on cart without a root-tab back button', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('cart'))
        ->assertOk()
        ->assertSee('data-storefront-page="browse"', false)
        ->assertDontSee('data-test="back-button"', false);
});

it('uses the shared empty state vocabulary on activity', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('activity.index'))
        ->assertOk()
        ->assertSee('data-storefront-page="work"', false)
        ->assertSee('data-test="activity-empty"', false)
        ->assertSee('data-test="storefront-page-header"', false)
        ->assertSee('data-test="back-button"', false);
});

it('keeps notifications route compatible with the activity page', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('notifications.index'))
        ->assertOk()
        ->assertSee('data-test="activity-page"', false);
});
