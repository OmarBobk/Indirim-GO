<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\StorefrontShell;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

test('account hub requires authentication', function () {
    $this->get(route('account'))
        ->assertRedirect(route('login'));
});

test('account hub lists core destinations language theme and logout', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('account'));

    $response->assertOk();
    $response->assertSee('data-test="account-hub"', false);
    $response->assertSee('data-test="account-hub-section-account"', false);
    $response->assertSee('data-test="account-hub-section-shopping"', false);
    $response->assertSee('data-test="account-hub-section-settings"', false);
    $response->assertSee('data-test="account-hub-link-profile"', false);
    $response->assertSee('data-test="account-hub-link-activity"', false);
    $response->assertSee('data-test="account-hub-link-wallet"', false);
    $response->assertSee('data-test="account-hub-link-orders"', false);
    $response->assertSee('data-test="account-hub-link-contact"', false);
    $response->assertSee('data-test="account-hub-theme"', false);
    $response->assertSee('data-test="account-hub-logout"', false);
    $response->assertSee('data-test="logout-button"', false);
});

test('account hub sections group links without changing destinations', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $sections = collect(StorefrontShell::accountHubSections())->pluck('key')->all();

    expect($sections)->toBe(['account', 'shopping']);

    $accountKeys = collect(StorefrontShell::accountHubSections())
        ->firstWhere('key', 'account')['links'];

    expect(collect($accountKeys)->pluck('key')->all())->toContain('profile', 'activity', 'contact');
});

test('account hub shows notification badge when unread exist', function () {
    $user = User::factory()->create();

    DatabaseNotification::query()->create([
        'id' => (string) Str::uuid(),
        'type' => 'App\\Notifications\\TestNotification',
        'notifiable_type' => $user->getMorphClass(),
        'notifiable_id' => $user->id,
        'data' => ['title' => 'Hello', 'message' => 'World'],
        'read_at' => null,
    ]);

    $this->actingAs($user)
        ->get(route('account'))
        ->assertOk()
        ->assertSee('data-test="account-hub-badge-activity"', false);
});

test('account bottom nav points to account hub', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $account = collect(StorefrontShell::bottomNavItems())->firstWhere('key', 'account');

    expect($account)->not->toBeNull()
        ->and($account['route'])->toBe('account')
        ->and($account['href'])->toBe(route('account'));
});
