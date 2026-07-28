<?php

declare(strict_types=1);

use App\Actions\Activity\GetCustomerActivity;
use App\Enums\TopupRequestStatus;
use App\Models\User;
use App\Notifications\TopupApprovedNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

it('hides the Operational section when there are no action items', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('home'))
        ->assertOk()
        ->assertSee('data-test="home-operational-livewire"', false)
        ->assertDontSee('data-test="customer-home-operational"', false)
        ->assertDontSee(__('messages.home_operational_title'), false)
        ->assertSee('data-test="customer-home-command"', false)
        ->assertSee('data-test="customer-home-frequently-ordered"', false);
});

it('shows up to three Operational items with View all when more exist', function (): void {
    $user = User::factory()->create();

    foreach (range(1, 4) as $i) {
        homeOpRejectedTopup($user, 10 + $i, "Reason {$i}");
    }
    homeOpFailedOrder($user);

    $response = $this->actingAs($user)->get(route('home'))->assertOk();

    $response->assertSee('data-test="customer-home-operational"', false)
        ->assertSee(__('messages.home_operational_title'), false)
        ->assertSee('data-test="home-operational-view-all"', false)
        ->assertSee(route('activity.index', ['filter' => 'action_required'], false), false)
        ->assertSee('data-test="home-operational-item-cta"', false)
        ->assertDontSee('INTERNAL_SUPPLIER_TRACE', false);

    $html = $response->getContent();
    expect(substr_count($html, 'data-test="home-operational-item"'))->toBe(3);

    $command = strpos($html, 'data-test="customer-home-command"');
    $operational = strpos($html, 'data-test="customer-home-operational"');
    $personal = strpos($html, 'data-test="customer-home-personal"');

    expect($command)->toBeLessThan($operational)
        ->and($operational)->toBeLessThan($personal);
});

it('renders Arabic Operational copy', function (): void {
    $user = User::factory()->create(['locale' => 'ar']);
    homeOpRejectedTopup($user);

    app()->setLocale('ar');

    $this->actingAs($user)
        ->get(route('home'))
        ->assertOk()
        ->assertSee(__('messages.home_operational_title', [], 'ar'), false)
        ->assertSee(__('messages.home_operational_view_all', [], 'ar'), false);
});

it('refreshes Operational items from customer-activity-invalidate without unread COUNT', function (): void {
    $user = User::factory()->create();

    $component = Livewire::actingAs($user)->test('home-operational-attention');
    expect($component->get('items'))->toBe([]);

    homeOpRejectedTopup($user, 30, 'Needs retry');

    DB::enableQueryLog();
    DB::flushQueryLog();

    $component->dispatch('customer-activity-invalidate', source: 'domain', isReconcile: false)
        ->assertSee(__('messages.activity_action_topup_rejected_title'))
        ->assertSet('lastVisibleCount', 1)
        ->assertSet('announcement', __('messages.home_operational_announce_new'));

    $unreadCounts = collect(DB::getQueryLog())
        ->filter(function (array $q): bool {
            $sql = strtolower($q['query']);

            return str_contains($sql, 'notifications')
                && str_contains($sql, 'count(*)')
                && str_contains($sql, 'read_at');
        })
        ->count();
    DB::disableQueryLog();

    expect($unreadCounts)->toBe(0);
});

it('hides Operational after the final item resolves via invalidation', function (): void {
    $user = User::factory()->create();
    $topup = homeOpRejectedTopup($user);

    $component = Livewire::actingAs($user)
        ->test('home-operational-attention')
        ->assertSee(__('messages.activity_action_topup_rejected_title'));

    $topup->update(['status' => TopupRequestStatus::Approved, 'note' => null]);

    $component->dispatch('customer-activity-invalidate', source: 'domain')
        ->assertDontSee(__('messages.activity_action_topup_rejected_title'))
        ->assertSet('lastVisibleCount', 0)
        ->assertSet('announcement', __('messages.home_operational_announce_cleared'));
});

it('invokes GetCustomerActivity forHomeOperational once per invalidate', function (): void {
    $user = User::factory()->create();
    homeOpRejectedTopup($user);

    $calls = 0;
    app()->bind(GetCustomerActivity::class, function () use (&$calls) {
        $calls++;

        return new GetCustomerActivity(
            app(\App\Support\Activity\NotificationActivityReader::class),
            app(\App\Support\Activity\TopupActionRequiredReader::class),
            app(\App\Support\Activity\OrderActionRequiredReader::class),
            app(\App\Support\Activity\RefundActionRequiredReader::class),
            app(\App\Support\Activity\CustomerActivityMerger::class),
        );
    });

    $component = Livewire::actingAs($user)->test('home-operational-attention');
    $calls = 0;

    $component->dispatch('customer-activity-invalidate');

    expect($calls)->toBe(1);
});

it('does not expose another users action items on Home', function (): void {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    homeOpRejectedTopup($owner, 55, 'Owner only note');

    $this->actingAs($intruder)
        ->get(route('home'))
        ->assertOk()
        ->assertDontSee('Owner only note', false)
        ->assertDontSee('data-test="customer-home-operational"', false);
});

it('ignores crafted browser user targeting because auth scopes the read', function (): void {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    homeOpRejectedTopup($owner, 40, 'Private reason');

    Livewire::actingAs($intruder)
        ->test('home-operational-attention')
        ->dispatch('customer-activity-invalidate', userId: $owner->id, source: 'notification')
        ->assertDontSee('Private reason')
        ->assertSet('lastVisibleCount', 0);
});

it('does not treat ordinary unread notifications as Operational items', function (): void {
    $user = User::factory()->create();
    $user->notifications()->create([
        'id' => (string) Str::uuid(),
        'type' => TopupApprovedNotification::class,
        'data' => [
            'title' => 'Ordinary unread',
            'message' => 'Not action required',
            'url' => route('wallet'),
            'trace_id' => (string) Str::uuid(),
        ],
    ]);

    $this->actingAs($user)
        ->get(route('home'))
        ->assertOk()
        ->assertDontSee('Ordinary unread', false)
        ->assertDontSee('data-test="customer-home-operational"', false);
});
