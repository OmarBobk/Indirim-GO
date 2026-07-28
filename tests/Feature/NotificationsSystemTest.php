<?php

use App\Actions\Topups\ApproveTopupRequest;
use App\Actions\Topups\CreateTopupRequestAction;
use App\Enums\TopupRequestStatus;
use App\Models\PaymentMethod;
use App\Models\TopupRequest;
use App\Models\User;
use App\Models\Wallet;
use App\Notifications\TopupRequestedNotification;
use App\Services\NotificationRecipientService;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

use function Pest\Laravel\actingAs;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    Role::firstOrCreate(['name' => 'admin']);
    Role::firstOrCreate(['name' => 'customer']);
    Permission::firstOrCreate(['name' => 'manage_topups']);
    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');
    $this->admin->givePermissionTo('manage_topups');
    $this->customer = User::factory()->create();
    $this->customer->assignRole('customer');
});

it('sends topup requested notification to admins after commit', function () {
    actingAs($this->customer);
    $wallet = Wallet::forUser($this->customer);

    DB::transaction(function () use ($wallet) {
        $request = app(CreateTopupRequestAction::class)->handle([
            'user_id' => $this->customer->id,
            'wallet_id' => $wallet->id,
            'payment_method_id' => PaymentMethod::query()->where('name', 'Sham Cash')->value('id'),
            'amount' => 50,
            'currency' => 'USD',
            'status' => TopupRequestStatus::Pending,
        ]);
        activity()
            ->inLog('payments')
            ->event('topup.requested')
            ->performedOn($request)
            ->causedBy($this->customer)
            ->log('Topup requested');
        $requestId = $request->id;
        DB::afterCommit(function () use ($requestId) {
            $r = TopupRequest::query()->find($requestId);
            if ($r !== null) {
                $notification = TopupRequestedNotification::fromTopupRequest($r);
                app(NotificationRecipientService::class)->adminUsers()->each(fn ($admin) => $admin->notify($notification));
            }
        });
    });

    $this->admin->refresh();
    expect($this->admin->notifications()->count())->toBe(1);
});

it('does not send notification when transaction rolls back', function () {
    $wallet = Wallet::forUser($this->customer);

    try {
        DB::transaction(function () use ($wallet) {
            $request = app(CreateTopupRequestAction::class)->handle([
                'user_id' => $this->customer->id,
                'wallet_id' => $wallet->id,
                'payment_method_id' => PaymentMethod::query()->where('name', 'Sham Cash')->value('id'),
                'amount' => 50,
                'currency' => 'USD',
                'status' => TopupRequestStatus::Pending,
            ]);
            $requestId = $request->id;
            DB::afterCommit(function () use ($requestId) {
                $r = TopupRequest::query()->find($requestId);
                if ($r !== null) {
                    $this->admin->notify(TopupRequestedNotification::fromTopupRequest($r));
                }
            });
            throw new \RuntimeException('Rollback');
        });
    } catch (\RuntimeException) {
    }

    $this->admin->refresh();
    expect($this->admin->notifications()->count())->toBe(0);
});

it('does not duplicate topup approved notification on idempotent re-call', function () {
    $wallet = Wallet::forUser($this->customer);
    $topupRequest = app(CreateTopupRequestAction::class)->handle([
        'user_id' => $this->customer->id,
        'wallet_id' => $wallet->id,
        'payment_method_id' => PaymentMethod::query()->where('name', 'Sham Cash')->value('id'),
        'amount' => 100,
        'currency' => 'USD',
        'status' => TopupRequestStatus::Pending,
    ]);
    expect($topupRequest->walletTransaction)->not->toBeNull();

    $approve = app(ApproveTopupRequest::class);
    $approve->handle($this->admin, $topupRequest);
    $customerNotificationCountAfterFirst = $this->customer->notifications()->count();

    $approve->handle($this->admin, $topupRequest->refresh());
    $this->customer->refresh();
    expect($this->customer->notifications()->count())->toBe($customerNotificationCountAfterFirst);
});
