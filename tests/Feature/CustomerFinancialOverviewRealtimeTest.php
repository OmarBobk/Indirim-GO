<?php

declare(strict_types=1);

use App\Enums\CustomerFinancialInvalidationReason;
use App\Events\CustomerFinancialStateChanged;
use App\Models\User;
use App\Models\Wallet;
use App\Support\FrontendMoney;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
});

it('refreshes wallet overview from CustomerFinancialStateChanged livewire path', function (): void {
    $user = User::factory()->create();
    $wallet = Wallet::forUser($user);
    $wallet->update(['balance' => '20.00']);

    $component = Livewire::actingAs($user)
        ->test('pages::frontend.wallet');

    $wallet->update(['balance' => '75.25']);

    $component
        ->dispatch('customer-financial-invalidate', reason: CustomerFinancialInvalidationReason::BalanceChanged->value)
        ->assertSee(FrontendMoney::for($user)->format(75.25, 'USD', 2));
});

it('broadcasts on the existing private user channel only', function (): void {
    $user = User::factory()->create();

    $event = new CustomerFinancialStateChanged($user->id, CustomerFinancialInvalidationReason::TopupStateChanged);

    expect($event->broadcastOn()[0]->name)->toBe('private-App.Models.User.'.$user->id)
        ->and($event->broadcastWith())->toHaveKeys(['reason', 'occurred_at', 'event_id'])
        ->and($event->broadcastWith())->not->toHaveKey('balance')
        ->and($event->broadcastWith())->not->toHaveKey('amount');
});

it('wallet overview listens for financial invalidate and not activity invalidate', function (): void {
    $user = User::factory()->create();
    Wallet::forUser($user)->update(['balance' => '33.00']);

    $instance = Livewire::actingAs($user)
        ->test('pages::frontend.wallet')
        ->instance();

    $onEvents = [];
    foreach ((new ReflectionClass($instance))->getMethods() as $method) {
        foreach ($method->getAttributes(\Livewire\Attributes\On::class) as $attribute) {
            $onEvents[] = $attribute->getArguments()[0] ?? null;
        }
    }

    expect($onEvents)->toContain('customer-financial-invalidate')
        ->and($onEvents)->not->toContain('customer-activity-invalidate');
});
