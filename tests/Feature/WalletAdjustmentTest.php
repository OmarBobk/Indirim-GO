<?php

declare(strict_types=1);

use App\Actions\Wallets\AdjustWallet;
use App\Enums\WalletAdjustmentKind;
use App\Enums\WalletTransactionDirection;
use App\Enums\WalletTransactionType;
use App\Enums\WalletType;
use App\Exceptions\IdempotencyConflictException;
use App\Models\SystemEvent;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\WalletLedger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * @see tests/Feature/WalletAdjustmentTestMatrix.md
 */
function grantAdjustWallets(User $user): User
{
    $permission = Permission::firstOrCreate(['name' => 'adjust_wallets']);
    $role = Role::firstOrCreate(['name' => 'admin']);
    $role->givePermissionTo($permission);
    $user->assignRole($role);

    return $user;
}

function makeAdminWithAdjustWallets(): User
{
    return grantAdjustWallets(User::factory()->create());
}

// ─── Authorization ───────────────────────────────────────────────────────────

test('admin with adjust_wallets can open wallet adjustments page', function () {
    $admin = makeAdminWithAdjustWallets();

    $this->actingAs($admin)
        ->get(route('wallet-adjustments'))
        ->assertOk()
        ->assertSee(__('messages.wallet_adjustments'));
});

test('authenticated user without adjust_wallets cannot open wallet adjustments page', function () {
    $permission = Permission::firstOrCreate(['name' => 'manage_topups']);
    $role = Role::firstOrCreate(['name' => 'ops']);
    $role->givePermissionTo($permission);

    $user = User::factory()->create();
    $user->assignRole($role);

    $this->actingAs($user)
        ->get(route('wallet-adjustments'))
        ->assertForbidden();
});

test('salesperson without adjust_wallets cannot open wallet adjustments page', function () {
    foreach (['view_orders', 'create_orders', 'view_referrals'] as $name) {
        Permission::firstOrCreate(['name' => $name]);
    }
    $role = Role::firstOrCreate(['name' => 'salesperson']);
    $role->syncPermissions(['view_orders', 'create_orders', 'view_referrals']);

    $salesperson = User::factory()->create();
    $salesperson->assignRole($role);

    $this->actingAs($salesperson)
        ->get(route('wallet-adjustments'))
        ->assertForbidden();
});

test('customer cannot open wallet adjustments page', function () {
    $customer = User::factory()->create();

    $this->actingAs($customer)
        ->get(route('wallet-adjustments'))
        ->assertNotFound();
});

test('guest is redirected away from wallet adjustments page', function () {
    $this->get(route('wallet-adjustments'))
        ->assertRedirect();
});

test('AdjustWallet denies actors without adjust_wallets', function () {
    $actor = User::factory()->create();
    $target = User::factory()->create();

    expect(fn () => app(AdjustWallet::class)->handle(
        actor: $actor,
        targetUser: $target,
        amount: '10.00',
        idempotencyKey: (string) Str::uuid(),
    ))->toThrow(AuthorizationException::class);
});

// ─── Happy path + database / audit ───────────────────────────────────────────

test('admin credit posts ledger, updates balance, and records audit trail', function () {
    $admin = makeAdminWithAdjustWallets();
    $customer = User::factory()->create(['name' => 'Omar']);
    $wallet = Wallet::forUser($customer);
    $wallet->update(['balance' => '100.00']);

    $result = app(AdjustWallet::class)->handle(
        actor: $admin,
        targetUser: $customer,
        amount: '150.00',
        idempotencyKey: 'adj-credit-omar-1',
        kind: WalletAdjustmentKind::AdminCredit,
        reason: 'Goodwill credit',
        ipAddress: '203.0.113.10',
    );

    $wallet->refresh();

    expect($result->previousBalance)->toBe('100.00')
        ->and($result->newBalance)->toBe('250.00')
        ->and((string) $wallet->balance)->toBe('250.00');

    $tx = $result->transaction->fresh();
    expect($tx->type)->toBe(WalletTransactionType::Adjustment)
        ->and($tx->direction)->toBe(WalletTransactionDirection::Credit)
        ->and($tx->status)->toBe(WalletTransaction::STATUS_POSTED)
        ->and((string) $tx->amount)->toBe('150.00')
        ->and($tx->idempotency_key)->toBe('adj-credit-omar-1')
        ->and(data_get($tx->meta, 'adjustment_kind'))->toBe('admin_credit')
        ->and(data_get($tx->meta, 'previous_balance'))->toBe('100.00')
        ->and(data_get($tx->meta, 'new_balance'))->toBe('250.00')
        ->and(data_get($tx->meta, 'reason'))->toBe('Goodwill credit')
        ->and(data_get($tx->meta, 'ip_address'))->toBe('203.0.113.10')
        ->and((int) data_get($tx->meta, 'actor_id'))->toBe($admin->id)
        ->and((int) data_get($tx->meta, 'target_user_id'))->toBe($customer->id);

    expect(WalletTransaction::query()->where('wallet_id', $wallet->id)->count())->toBe(1);

    $activity = Activity::query()
        ->where('event', 'wallet.adjusted')
        ->where('log_name', 'payments')
        ->latest('id')
        ->first();

    expect($activity)->not->toBeNull()
        ->and($activity->description)->toContain($admin->name)
        ->and($activity->description)->toContain('150.00')
        ->and($activity->description)->toContain('Omar')
        ->and(data_get($activity->properties, 'transaction_id'))->toBe($tx->id)
        ->and(data_get($activity->properties, 'previous_balance'))->toBe('100.00')
        ->and(data_get($activity->properties, 'new_balance'))->toBe('250.00');

    expect(SystemEvent::query()
        ->where('event_type', 'wallet.adjustment.posted')
        ->where('is_financial', true)
        ->where('entity_type', WalletTransaction::class)
        ->where('entity_id', $tx->id)
        ->exists())->toBeTrue();
});

test('optional reason may be omitted', function () {
    $admin = makeAdminWithAdjustWallets();
    $customer = User::factory()->create();
    Wallet::forUser($customer);

    $result = app(AdjustWallet::class)->handle(
        actor: $admin,
        targetUser: $customer,
        amount: '5.00',
        idempotencyKey: 'adj-no-reason',
        reason: null,
    );

    expect(data_get($result->transaction->meta, 'reason'))->toBeNull();
});

test('extremely large amount posts when otherwise valid', function () {
    $admin = makeAdminWithAdjustWallets();
    $customer = User::factory()->create();
    $wallet = Wallet::forUser($customer);

    $result = app(AdjustWallet::class)->handle(
        actor: $admin,
        targetUser: $customer,
        amount: '9999999.99',
        idempotencyKey: 'adj-large-amount',
    );

    $wallet->refresh();

    expect($result->newBalance)->toBe('9999999.99')
        ->and((string) $wallet->balance)->toBe('9999999.99');
});

// ─── Validation / business rules ─────────────────────────────────────────────

test('AdjustWallet rejects zero, negative, and blank amounts', function (string $amount) {
    $admin = makeAdminWithAdjustWallets();
    $customer = User::factory()->create();
    $wallet = Wallet::forUser($customer);
    $wallet->update(['balance' => '10.00']);

    expect(fn () => app(AdjustWallet::class)->handle(
        actor: $admin,
        targetUser: $customer,
        amount: $amount,
        idempotencyKey: (string) Str::uuid(),
    ))->toThrow(ValidationException::class);

    expect((string) $wallet->fresh()->balance)->toBe('10.00');
})->with([
    'zero' => '0',
    'negative' => '-5.00',
    'blank' => ' ',
]);

test('AdjustWallet requires an idempotency key', function () {
    $admin = makeAdminWithAdjustWallets();
    $customer = User::factory()->create();

    expect(fn () => app(AdjustWallet::class)->handle(
        actor: $admin,
        targetUser: $customer,
        amount: '10.00',
        idempotencyKey: '   ',
    ))->toThrow(ValidationException::class);
});

test('AdjustWallet always credits a customer-type wallet for the target user', function () {
    $admin = makeAdminWithAdjustWallets();
    $customer = User::factory()->create();

    $result = app(AdjustWallet::class)->handle(
        actor: $admin,
        targetUser: $customer,
        amount: '10.00',
        idempotencyKey: 'adj-customer-type',
    );

    $wallet = Wallet::query()->findOrFail($result->transaction->wallet_id);

    expect($wallet->type)->toBe(WalletType::Customer)
        ->and((int) $wallet->user_id)->toBe($customer->id);
});

test('WalletLedger posts credit, replays idempotently, and rejects payload mismatches', function () {
    $user = User::factory()->create();
    $wallet = Wallet::forUser($user);
    $wallet->update(['balance' => '10.00']);
    $ledger = app(WalletLedger::class);

    $first = $ledger->post(
        wallet: $wallet,
        type: WalletTransactionType::Adjustment,
        direction: WalletTransactionDirection::Credit,
        amount: '2.50',
        idempotencyKey: 'ledger-credit-1',
        meta: ['source' => 'test'],
    );

    expect($first->previousBalance)->toBe('10.00')
        ->and($first->newBalance)->toBe('12.50')
        ->and((string) $wallet->fresh()->balance)->toBe('12.50');

    $replay = $ledger->post(
        wallet: $wallet,
        type: WalletTransactionType::Adjustment,
        direction: WalletTransactionDirection::Credit,
        amount: '2.50',
        idempotencyKey: 'ledger-credit-1',
    );

    expect($replay->transaction->id)->toBe($first->transaction->id)
        ->and(WalletTransaction::query()->where('idempotency_key', 'ledger-credit-1')->count())->toBe(1);

    expect(fn () => $ledger->post(
        wallet: $wallet,
        type: WalletTransactionType::Topup,
        direction: WalletTransactionDirection::Credit,
        amount: '2.50',
        idempotencyKey: 'ledger-credit-1',
    ))->toThrow(IdempotencyConflictException::class);
});

test('WalletLedger debit rejects insufficient balance', function () {
    $user = User::factory()->create();
    $wallet = Wallet::forUser($user);
    $wallet->update(['balance' => '1.00']);

    expect(fn () => app(WalletLedger::class)->post(
        wallet: $wallet,
        type: WalletTransactionType::Adjustment,
        direction: WalletTransactionDirection::Debit,
        amount: '5.00',
        idempotencyKey: 'ledger-overdraft',
    ))->toThrow(RuntimeException::class);

    expect((string) $wallet->fresh()->balance)->toBe('1.00');
});

// ─── Idempotency / concurrency ───────────────────────────────────────────────

test('duplicate idempotency key with same payload credits once', function () {
    $admin = makeAdminWithAdjustWallets();
    $customer = User::factory()->create();
    $wallet = Wallet::forUser($customer);
    $wallet->update(['balance' => '20.00']);

    $action = app(AdjustWallet::class);
    $first = $action->handle($admin, $customer, '15.00', 'adj-same-key');
    $second = $action->handle($admin, $customer, '15.00', 'adj-same-key');

    $wallet->refresh();

    expect($first->transaction->id)->toBe($second->transaction->id)
        ->and((string) $wallet->balance)->toBe('35.00')
        ->and(WalletTransaction::query()->where('idempotency_key', 'adj-same-key')->count())->toBe(1);
});

test('idempotency key with different payload throws conflict', function () {
    $admin = makeAdminWithAdjustWallets();
    $customer = User::factory()->create();
    Wallet::forUser($customer)->update(['balance' => '20.00']);

    app(AdjustWallet::class)->handle($admin, $customer, '15.00', 'adj-conflict-key');

    expect(fn () => app(AdjustWallet::class)->handle($admin, $customer, '25.00', 'adj-conflict-key'))
        ->toThrow(IdempotencyConflictException::class);

    expect((string) Wallet::forUser($customer)->fresh()->balance)->toBe('35.00');
});

test('WalletLedger rejects blank idempotency keys', function () {
    $customer = User::factory()->create();
    $wallet = Wallet::forUser($customer);

    expect(fn () => app(WalletLedger::class)->post(
        wallet: $wallet,
        type: WalletTransactionType::Adjustment,
        direction: WalletTransactionDirection::Credit,
        amount: '1.00',
        idempotencyKey: '',
    ))->toThrow(InvalidArgumentException::class);
});

// ─── Livewire UI / security of confirm flow ──────────────────────────────────

test('livewire confirm posts credit, success summary, and recent adjustments', function () {
    $admin = makeAdminWithAdjustWallets();
    $customer = User::factory()->create([
        'name' => 'Nour',
        'email' => 'nour@example.com',
        'username' => 'nourx',
        'phone' => '+905551112233',
    ]);
    $wallet = Wallet::forUser($customer);
    $wallet->update(['balance' => '50.00']);

    Livewire::actingAs($admin)
        ->test('pages::backend.wallet-adjustments.index')
        ->set('search', 'nour@example.com')
        ->assertSee('Nour')
        ->call('selectUser', $customer->id)
        ->assertSet('selectedUserId', $customer->id)
        ->set('amount', '12.50')
        ->set('reason', 'Promo')
        ->assertSet('resultingBalance', '62.50')
        ->call('reviewAdjustment')
        ->assertSet('showTransactionSummary', true)
        ->set('confirmAcknowledged', true)
        ->call('confirmAdjustment')
        ->assertSet('showTransactionSummary', false)
        ->assertSet('lastSuccessAmount', '12.50')
        ->assertSet('lastSuccessBalance', '62.50')
        ->assertNotSet('lastSuccessTransactionId', null)
        ->assertSee(__('messages.wallet_adjustment_success_summary'))
        ->assertSee('Nour')
        ->assertSee('12.50');

    expect((string) $wallet->fresh()->balance)->toBe('62.50');
});

test('livewire search matches name email username and phone', function (string $term) {
    $admin = makeAdminWithAdjustWallets();
    $customer = User::factory()->create([
        'name' => 'Zeynep Karman',
        'email' => 'zeynep.search@example.com',
        'username' => 'zeynep_k',
        'phone' => '+905559998877',
    ]);

    Livewire::actingAs($admin)
        ->test('pages::backend.wallet-adjustments.index')
        ->set('search', $term)
        ->assertSee('Zeynep Karman');

    expect(
        Livewire::actingAs($admin)
            ->test('pages::backend.wallet-adjustments.index')
            ->set('search', $term)
            ->get('searchResults')
            ->pluck('id')
            ->contains($customer->id)
    )->toBeTrue();
})->with([
    'name' => 'Zeynep',
    'email' => 'zeynep.search@example.com',
    'username' => 'zeynep_k',
    'phone' => '5559998877',
]);

test('livewire rejects missing amount and overly long reason', function () {
    $admin = makeAdminWithAdjustWallets();
    $customer = User::factory()->create();
    Wallet::forUser($customer);

    Livewire::actingAs($admin)
        ->test('pages::backend.wallet-adjustments.index')
        ->call('selectUser', $customer->id)
        ->set('amount', '')
        ->call('reviewAdjustment')
        ->assertHasErrors(['amount']);

    Livewire::actingAs($admin)
        ->test('pages::backend.wallet-adjustments.index')
        ->call('selectUser', $customer->id)
        ->set('amount', '1.00')
        ->set('reason', str_repeat('x', 501))
        ->call('reviewAdjustment')
        ->assertHasErrors(['reason']);
});

test('livewire requires confirmation checkbox before posting', function () {
    $admin = makeAdminWithAdjustWallets();
    $customer = User::factory()->create();
    $wallet = Wallet::forUser($customer);
    $wallet->update(['balance' => '5.00']);

    Livewire::actingAs($admin)
        ->test('pages::backend.wallet-adjustments.index')
        ->call('selectUser', $customer->id)
        ->set('amount', '1.00')
        ->call('reviewAdjustment')
        ->set('confirmAcknowledged', false)
        ->call('confirmAdjustment')
        ->assertHasErrors(['confirmAcknowledged']);

    expect((string) $wallet->fresh()->balance)->toBe('5.00');
});

test('livewire replay with same idempotency key does not double credit', function () {
    $admin = makeAdminWithAdjustWallets();
    $customer = User::factory()->create();
    $wallet = Wallet::forUser($customer);
    $wallet->update(['balance' => '10.00']);

    $component = Livewire::actingAs($admin)
        ->test('pages::backend.wallet-adjustments.index')
        ->call('selectUser', $customer->id)
        ->set('amount', '7.00')
        ->call('reviewAdjustment')
        ->set('confirmAcknowledged', true);

    $key = $component->get('idempotencyKey');

    $component->call('confirmAdjustment');

    // Replay: restore same key/amount and confirm again (simulates double submit / replay).
    $component
        ->set('amount', '7.00')
        ->set('idempotencyKey', $key)
        ->set('showTransactionSummary', true)
        ->set('confirmAcknowledged', true)
        ->call('confirmAdjustment');

    expect((string) $wallet->fresh()->balance)->toBe('17.00')
        ->and(WalletTransaction::query()->where('idempotency_key', $key)->count())->toBe(1);
});

test('livewire confirm uses currently selected user id when changed mid-flow', function () {
    $admin = makeAdminWithAdjustWallets();
    $first = User::factory()->create(['name' => 'First']);
    $second = User::factory()->create(['name' => 'Second']);
    Wallet::forUser($first)->update(['balance' => '0.00']);
    Wallet::forUser($second)->update(['balance' => '0.00']);

    Livewire::actingAs($admin)
        ->test('pages::backend.wallet-adjustments.index')
        ->call('selectUser', $first->id)
        ->set('amount', '9.00')
        ->call('reviewAdjustment')
        ->set('selectedUserId', $second->id)
        ->set('confirmAcknowledged', true)
        ->call('confirmAdjustment');

    expect((string) Wallet::forUser($first)->fresh()->balance)->toBe('0.00')
        ->and((string) Wallet::forUser($second)->fresh()->balance)->toBe('9.00');
});

test('livewire rejects confirm when amount is tampered to invalid after review', function () {
    $admin = makeAdminWithAdjustWallets();
    $customer = User::factory()->create();
    $wallet = Wallet::forUser($customer);
    $wallet->update(['balance' => '8.00']);

    Livewire::actingAs($admin)
        ->test('pages::backend.wallet-adjustments.index')
        ->call('selectUser', $customer->id)
        ->set('amount', '3.00')
        ->call('reviewAdjustment')
        ->set('amount', '0')
        ->set('confirmAcknowledged', true)
        ->call('confirmAdjustment')
        ->assertHasErrors(['amount']);

    expect((string) $wallet->fresh()->balance)->toBe('8.00');
});

test('user with manage_topups cannot escalate to wallet adjustments endpoint', function () {
    $permission = Permission::firstOrCreate(['name' => 'manage_topups']);
    $role = Role::firstOrCreate(['name' => 'finance-ops']);
    $role->givePermissionTo($permission);
    $user = User::factory()->create();
    $user->assignRole($role);

    $this->actingAs($user)
        ->get(route('wallet-adjustments'))
        ->assertForbidden();
});
