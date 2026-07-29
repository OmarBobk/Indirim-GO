<?php

declare(strict_types=1);

use App\Actions\Topups\CreateTopupRequestAction;
use App\Enums\TopupRequestStatus;
use App\Models\PaymentMethod;
use App\Models\TopupProof;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
});

it('denies cross-user top-up detail list search and proof access', function (): void {
    Storage::fake('local');

    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $wallet = Wallet::forUser($owner);

    $request = app(CreateTopupRequestAction::class)->handle([
        'user_id' => $owner->id,
        'wallet_id' => $wallet->id,
        'payment_method_id' => PaymentMethod::query()->where('name', 'Sham Cash')->value('id'),
        'amount' => 18,
        'currency' => 'USD',
        'status' => TopupRequestStatus::Pending,
    ]);

    $path = 'topups/proofs/'.$request->id.'/'.fake()->uuid().'.jpg';
    Storage::disk('local')->put($path, 'dummy');

    $proof = TopupProof::query()->create([
        'topup_request_id' => $request->id,
        'file_path' => $path,
        'file_original_name' => 'proof.jpg',
        'mime_type' => 'image/jpeg',
        'size_bytes' => 5,
    ]);

    $this->actingAs($intruder)
        ->get(route('wallet.topups.show', ['topup' => $request->public_ref]))
        ->assertNotFound();

    $this->actingAs($intruder)
        ->get(route('topup-proofs.show', $proof))
        ->assertForbidden();

    Livewire::actingAs($intruder)
        ->test('pages::frontend.wallet-topups')
        ->set('search', (string) $request->public_ref)
        ->assertSeeHtml('data-test="topups-empty-filtered"')
        ->assertDontSeeHtml('data-test="topup-row"');
});
