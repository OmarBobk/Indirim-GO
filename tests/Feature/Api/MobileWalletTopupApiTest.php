<?php

declare(strict_types=1);

use App\Actions\Topups\ApproveTopupRequest;
use App\Actions\Topups\SubmitCustomerTopupRequest;
use App\Enums\CreditFacilityStatus;
use App\Models\MobileTopupAttempt;
use App\Models\PaymentMethod;
use App\Models\TopupRequest;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    m31Website();
    Storage::fake('local');
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
});

function m5MethodId(string $name = 'Sham Cash'): int
{
    return (int) PaymentMethod::query()->where('name', $name)->value('id');
}

/**
 * @return array<string, mixed>
 */
function m5TopupPayload(array $overrides = []): array
{
    return array_merge([
        'amount' => '25.00',
        'currency' => 'USD',
        'payment_method_id' => m5MethodId(),
    ], $overrides);
}

function m5ForbiddenKeys(): array
{
    return array_merge(m31SensitiveKeys(), [
        'file_path',
        'file_original_name',
        'approved_by',
        'wallet_id',
        'account_text',
        'balance_before',
        'balance_after',
        'credit_limit',
        'credit_enabled',
        'note',
    ]);
}

test('wallet summary includes pending_topup_public_ref and available_to_spend from spend policy', function (): void {
    $user = m31Customer();
    m31Fund($user, 10);
    Wallet::query()->where('user_id', $user->id)->update([
        'credit_enabled' => true,
        'credit_limit' => 50,
        'credit_status' => CreditFacilityStatus::Active,
    ]);
    $token = m31Token($user);

    $this->getJson('/api/v1/wallet/summary', m31Headers($token))
        ->assertOk()
        ->assertJsonPath('data.available_to_spend.amount', '60.00')
        ->assertJsonPath('data.pending_topup_public_ref', null);

    $negative = m31Customer();
    m31Fund($negative, -20);
    $this->getJson('/api/v1/wallet/summary', m31Headers(m31Token($negative)))
        ->assertOk()
        ->assertJsonPath('data.available_to_spend.amount', '0.00');
});

test('payment methods list returns only active customer-safe fields', function (): void {
    PaymentMethod::factory()->inactive()->create(['name' => 'Hidden Wire']);
    $user = m31Customer();
    $token = m31Token($user);

    $response = $this->getJson('/api/v1/wallet/payment-methods', m31Headers($token));
    $response->assertOk();
    $names = collect($response->json('data'))->pluck('name');
    expect($names)->toContain('Sham Cash')->not->toContain('Hidden Wire');
    m31AssertNoSensitiveKeys($response->json(), m5ForbiddenKeys());
    expect($response->json('data.0'))->toHaveKeys(['id', 'name', 'instructions', 'image_url']);
});

test('customer can submit a usd top-up without crediting the wallet', function (): void {
    $user = m31Customer();
    m31Fund($user, 40);
    $token = m31Token($user);

    $response = $this->post('/api/v1/wallet/topups', m5TopupPayload(), m31Headers($token, 'topup-usd-1'));

    $response->assertOk()
        ->assertJsonPath('data.replayed', false)
        ->assertJsonPath('data.pending_until_admin_approval', true)
        ->assertJsonPath('data.topup.status', 'pending')
        ->assertJsonPath('data.topup.credited', false)
        ->assertJsonPath('data.topup.money_moved', false)
        ->assertJsonPath('data.topup.entered_amount', '25.00')
        ->assertJsonPath('data.topup.entered_currency', 'USD')
        ->assertJsonPath('data.topup.wallet_amount.amount', '25.00')
        ->assertJsonPath('data.topup.wallet_amount.currency', 'USD');

    expect(Wallet::forUser($user)->fresh()->balance)->toBe('40.00')
        ->and(TopupRequest::query()->where('user_id', $user->id)->count())->toBe(1);

    $tx = WalletTransaction::query()->where('reference_type', TopupRequest::class)->firstOrFail();
    expect($tx->status)->toBe(WalletTransaction::STATUS_PENDING);

    $this->getJson('/api/v1/wallet/summary', m31Headers($token))
        ->assertOk()
        ->assertJsonPath('data.available_to_spend.amount', '40.00')
        ->assertJsonPath('data.pending_topup_public_ref', $response->json('data.topup.public_ref'));

    m31AssertNoSensitiveKeys($response->json(), m5ForbiddenKeys());
});

test('explicit try amount converts to wallet usd and is not reinterpreted as usd', function (): void {
    $user = m31Customer(['preferred_currency' => 'USD']);
    m31Fund($user, 0);
    $token = m31Token($user);

    $response = $this->post('/api/v1/wallet/topups', m5TopupPayload([
        'amount' => '1000.00',
        'currency' => 'TRY',
    ]), m31Headers($token, 'topup-try-1'));

    $response->assertOk()
        ->assertJsonPath('data.topup.entered_amount', '1000.00')
        ->assertJsonPath('data.topup.entered_currency', 'TRY')
        ->assertJsonPath('data.topup.wallet_amount.amount', '25.00');

    $request = TopupRequest::query()->where('user_id', $user->id)->firstOrFail();
    expect((string) $request->amount)->toBe('25.00')
        ->and($request->currency)->toBe('USD')
        ->and(Wallet::forUser($user)->fresh()->balance)->toBe('0.00');
});

test('web preferred-currency conversion remains unchanged when currency is omitted', function (): void {
    $user = User::factory()->create(['preferred_currency' => 'TRY']);
    Wallet::forUser($user);

    $created = app(SubmitCustomerTopupRequest::class)->handle(
        $user,
        '1000',
        m5MethodId(),
        false,
        null,
    );

    expect((string) $created->amount)->toBe('25.00')
        ->and($created->currency)->toBe('USD');
});

test('one pending top-up is enforced for a different idempotency key', function (): void {
    $user = m31Customer();
    m31Fund($user, 10);
    $token = m31Token($user);

    $this->post('/api/v1/wallet/topups', m5TopupPayload(), m31Headers($token, 'topup-a'))
        ->assertOk();

    $this->post('/api/v1/wallet/topups', m5TopupPayload(['amount' => '30.00']), m31Headers($token, 'topup-b'))
        ->assertStatus(422)
        ->assertJsonPath('code', 'topup_request_pending');

    expect(TopupRequest::query()->where('user_id', $user->id)->count())->toBe(1);
});

test('same idempotency key and payload replays without creating another request', function (): void {
    $user = m31Customer();
    m31Fund($user, 10);
    $token = m31Token($user);

    $first = $this->post('/api/v1/wallet/topups', m5TopupPayload(), m31Headers($token, 'topup-replay'))
        ->assertOk();
    $second = $this->post('/api/v1/wallet/topups', m5TopupPayload(), m31Headers($token, 'topup-replay'))
        ->assertOk()
        ->assertJsonPath('data.replayed', true)
        ->assertJsonPath('data.topup.public_ref', $first->json('data.topup.public_ref'));

    expect(TopupRequest::query()->where('user_id', $user->id)->count())->toBe(1)
        ->and($second->json('data.pending_until_admin_approval'))->toBeTrue();
});

test('same key with a different payload conflicts', function (): void {
    $user = m31Customer();
    m31Fund($user, 10);
    $token = m31Token($user);

    $this->post('/api/v1/wallet/topups', m5TopupPayload(), m31Headers($token, 'topup-conflict'))
        ->assertOk();

    $this->post('/api/v1/wallet/topups', m5TopupPayload(['amount' => '40.00']), m31Headers($token, 'topup-conflict'))
        ->assertStatus(409)
        ->assertJsonPath('code', 'idempotency_conflict');

    expect(TopupRequest::query()->where('user_id', $user->id)->count())->toBe(1);
});

test('lost-response recovery and retry after admin approval reuse the same request', function (): void {
    $user = m31Customer();
    m31Fund($user, 5);
    $token = m31Token($user);

    $submit = $this->post('/api/v1/wallet/topups', m5TopupPayload(), m31Headers($token, 'topup-recover'))
        ->assertOk();
    $publicRef = $submit->json('data.topup.public_ref');

    $this->getJson('/api/v1/wallet/topups/status', m31Headers($token, 'topup-recover'))
        ->assertOk()
        ->assertJsonPath('data.state', 'completed')
        ->assertJsonPath('data.topup.public_ref', $publicRef)
        ->assertJsonPath('data.topup.credited', false);

    $approver = User::factory()->create();
    Permission::firstOrCreate(['name' => 'manage_topups', 'guard_name' => 'web']);
    $approver->givePermissionTo('manage_topups');
    $request = TopupRequest::query()->where('public_ref', $publicRef)->firstOrFail();
    app(ApproveTopupRequest::class)->handle($approver, $request);

    expect(Wallet::forUser($user)->fresh()->balance)->toBe('30.00');

    $this->post('/api/v1/wallet/topups', m5TopupPayload(), m31Headers($token, 'topup-recover'))
        ->assertOk()
        ->assertJsonPath('data.replayed', true)
        ->assertJsonPath('data.topup.public_ref', $publicRef)
        ->assertJsonPath('data.topup.credited', true)
        ->assertJsonPath('data.topup.money_moved', true)
        ->assertJsonPath('data.pending_until_admin_approval', false);

    expect(TopupRequest::query()->where('user_id', $user->id)->count())->toBe(1)
        ->and(Wallet::forUser($user)->fresh()->balance)->toBe('30.00');

    $this->getJson('/api/v1/wallet/transactions', m31Headers($token))
        ->assertOk()
        ->assertJsonPath('data.0.type', 'topup')
        ->assertJsonPath('data.0.amount.amount', '25.00')
        ->assertJsonPath('data.0.related_topup_public_ref', $publicRef);
});

test('owned top-up detail and proof are private', function (): void {
    $owner = m31Customer();
    m31Fund($owner, 10);
    $ownerToken = m31Token($owner);
    $proof = UploadedFile::fake()->image('receipt.jpg');

    $created = $this->post('/api/v1/wallet/topups', m5TopupPayload([
        'proof' => $proof,
    ]), m31Headers($ownerToken, 'topup-proof'))->assertOk();
    $publicRef = $created->json('data.topup.public_ref');

    $this->getJson('/api/v1/wallet/topups/'.$publicRef, m31Headers($ownerToken))
        ->assertOk()
        ->assertJsonPath('data.has_proof', true)
        ->assertJsonPath('data.public_ref', $publicRef);

    $this->get('/api/v1/wallet/topups/'.$publicRef.'/proof', m31Headers($ownerToken))
        ->assertOk();

    $stranger = m31Customer();
    $strangerToken = m31Token($stranger);

    $this->getJson('/api/v1/wallet/topups/'.$publicRef, m31Headers($strangerToken))
        ->assertNotFound()
        ->assertJsonPath('code', 'topup_not_found');

    $this->getJson('/api/v1/wallet/topups/'.$publicRef.'/proof', m31Headers($strangerToken))
        ->assertNotFound();

    $this->getJson('/api/v1/wallet/topups', m31Headers($strangerToken))
        ->assertOk()
        ->assertJsonPath('meta.pagination.total', 0);

    $this->getJson('/api/v1/wallet/topups/TUP-ZZZZZZZZZZ', m31Headers($ownerToken))
        ->assertNotFound()
        ->assertJsonPath('code', 'topup_not_found');
});

test('inactive payment methods and invalid amounts are rejected before a request is created', function (): void {
    $inactive = PaymentMethod::factory()->inactive()->create();
    $user = m31Customer();
    $token = m31Token($user);

    $this->post('/api/v1/wallet/topups', m5TopupPayload([
        'payment_method_id' => $inactive->id,
    ]), m31Headers($token, 'topup-inactive'))
        ->assertStatus(422)
        ->assertJsonPath('code', 'payment_method_unavailable');

    $this->post('/api/v1/wallet/topups', m5TopupPayload([
        'amount' => '0',
    ]), m31Headers($token, 'topup-zero'))
        ->assertStatus(422);

    expect(TopupRequest::query()->count())->toBe(0)
        ->and(MobileTopupAttempt::query()->count())->toBe(0);
});

test('wallet transactions omit pending top-up credits and another customer ledger', function (): void {
    $user = m31Customer();
    m31Fund($user, 10);
    $token = m31Token($user);

    $this->post('/api/v1/wallet/topups', m5TopupPayload(), m31Headers($token, 'topup-pending-ledger'))
        ->assertOk();

    $this->getJson('/api/v1/wallet/transactions', m31Headers($token))
        ->assertOk()
        ->assertJsonPath('meta.pagination.total', 0);

    $other = m31Customer();
    $this->getJson('/api/v1/wallet/transactions', m31Headers(m31Token($other)))
        ->assertOk()
        ->assertJsonPath('data', []);
});
