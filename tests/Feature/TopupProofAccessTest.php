<?php

use App\Actions\Topups\CreateTopupRequestAction;
use App\Enums\TopupRequestStatus;
use App\Models\PaymentMethod;
use App\Models\TopupProof;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

test('other user cannot access proof', function () {
    Storage::fake('local');
    $owner = User::factory()->create();
    $otherUser = User::factory()->create();
    $wallet = Wallet::forUser($owner);

    $topupRequest = app(CreateTopupRequestAction::class)->handle([
        'user_id' => $owner->id,
        'wallet_id' => $wallet->id,
        'payment_method_id' => PaymentMethod::query()->where('name', 'Sham Cash')->value('id'),
        'amount' => 50,
        'currency' => $wallet->currency,
        'status' => TopupRequestStatus::Pending,
    ]);

    $path = 'topups/proofs/'.$topupRequest->id.'/'.fake()->uuid().'.pdf';
    Storage::disk('local')->put($path, 'dummy content');

    $proof = TopupProof::create([
        'topup_request_id' => $topupRequest->id,
        'file_path' => $path,
        'file_original_name' => 'proof.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 13,
    ]);

    $this->actingAs($otherUser)
        ->get(route('topup-proofs.show', $proof))
        ->assertForbidden();
});

test('owner can access own proof', function () {
    Storage::fake('local');
    $owner = User::factory()->create();
    $wallet = Wallet::forUser($owner);

    $topupRequest = app(CreateTopupRequestAction::class)->handle([
        'user_id' => $owner->id,
        'wallet_id' => $wallet->id,
        'payment_method_id' => PaymentMethod::query()->where('name', 'Sham Cash')->value('id'),
        'amount' => 50,
        'currency' => $wallet->currency,
        'status' => TopupRequestStatus::Pending,
    ]);

    $path = 'topups/proofs/'.$topupRequest->id.'/'.fake()->uuid().'.pdf';
    Storage::disk('local')->put($path, 'dummy content');

    $proof = TopupProof::create([
        'topup_request_id' => $topupRequest->id,
        'file_path' => $path,
        'file_original_name' => 'proof.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 13,
    ]);

    $this->actingAs($owner)
        ->get(route('topup-proofs.show', $proof))
        ->assertSuccessful();
});

test('admin can access any proof', function () {
    Storage::fake('local');
    $owner = User::factory()->create();
    $admin = User::factory()->create();
    $admin->givePermissionTo(\Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'manage_topups']));
    $wallet = Wallet::forUser($owner);

    $topupRequest = app(CreateTopupRequestAction::class)->handle([
        'user_id' => $owner->id,
        'wallet_id' => $wallet->id,
        'payment_method_id' => PaymentMethod::query()->where('name', 'Sham Cash')->value('id'),
        'amount' => 50,
        'currency' => $wallet->currency,
        'status' => TopupRequestStatus::Pending,
    ]);

    $path = 'topups/proofs/'.$topupRequest->id.'/'.fake()->uuid().'.pdf';
    Storage::disk('local')->put($path, 'dummy content');

    $proof = TopupProof::create([
        'topup_request_id' => $topupRequest->id,
        'file_path' => $path,
        'file_original_name' => 'proof.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 13,
    ]);

    $this->actingAs($admin)
        ->get(route('topup-proofs.show', $proof))
        ->assertSuccessful();
});
