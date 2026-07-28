<?php

use App\Actions\Activity\GetCustomerActivity;
use App\DTOs\CustomerActivityResult;
use App\Notifications\FulfillmentFailedNotification;
use App\Notifications\PaymentFailedNotification;
use App\Notifications\RefundRejectedNotification;
use App\Notifications\TopupRejectedNotification;
use App\Support\CustomerActivityPresenter;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Masmerise\Toaster\Toastable;

new #[Layout('layouts::frontend')] class extends Component
{
    use Toastable;
    use WithPagination;

    public int $perPage = 15;

    #[Url(except: 'all')]
    public string $filter = 'all';

    #[Url(as: 'category', except: '')]
    public string $category = '';

    public bool $hasPendingRefresh = false;

    public int $unreadCount = 0;

    private ?CustomerActivityResult $activityResult = null;

    public function mount(): void
    {
        abort_unless(auth()->check(), 403);
        $this->normalizeFilters();
        $this->loadActivity();
        $this->unreadCount = $this->resolveUnreadCount();
    }

    public function updatedFilter(): void
    {
        $this->normalizeFilters();
        $this->resetPage();
        $this->hasPendingRefresh = false;
        $this->forgetActivityResult();
    }

    public function updatedCategory(): void
    {
        $this->normalizeFilters();
        $this->resetPage();
        $this->hasPendingRefresh = false;
        $this->forgetActivityResult();
    }

    public function updatedPage(mixed $page): void
    {
        if ((int) $page <= 1) {
            $this->hasPendingRefresh = false;
        }

        $this->forgetActivityResult();
    }

    public function setFilter(string $filter): void
    {
        $this->filter = in_array($filter, ['all', 'unread', 'action_required'], true) ? $filter : 'all';
        $this->resetPage();
        $this->hasPendingRefresh = false;
        $this->forgetActivityResult();
    }

    public function setCategory(string $category): void
    {
        $allowed = ['', 'orders', 'money', 'rewards', 'account'];
        $this->category = in_array($category, $allowed, true) ? $category : '';
        $this->resetPage();
        $this->hasPendingRefresh = false;
        $this->forgetActivityResult();
    }

    public function clearFilters(): void
    {
        $this->filter = 'all';
        $this->category = '';
        $this->resetPage();
        $this->hasPendingRefresh = false;
        $this->forgetActivityResult();
    }

    public function markAsRead(string $notificationId): void
    {
        $user = auth()->user();
        if ($user === null || $notificationId === '') {
            return;
        }

        $notification = $user->notifications()->whereKey($notificationId)->first();
        if ($notification !== null) {
            $notification->markAsRead();
        }

        $this->forgetActivityResult();
        $this->dispatch('customer-notifications-changed');
    }

    public function markAllAsRead(): void
    {
        $user = auth()->user();
        if ($user === null) {
            return;
        }

        $user->unreadNotifications()->update(['read_at' => now()]);
        $this->unreadCount = 0;
        $this->forgetActivityResult();
        $this->dispatch('customer-notifications-changed');
        $this->success(__('messages.activity_marked_all_read'));
    }

    public function applyPendingRefresh(): void
    {
        $this->hasPendingRefresh = false;
        $this->resetPage();
        $this->forgetActivityResult();
        $this->loadActivity();
    }

    #[On('customer-notifications-changed')]
    public function refreshAfterNotificationChange(): void
    {
        $this->forgetActivityResult();
    }

    #[On('customer-unread-count-updated')]
    public function syncUnreadCountFromCoordinator(int $count): void
    {
        $this->unreadCount = $count;

        // Count-only sync: Alpine drives the mark-all control; do not re-fetch the feed.
        $this->skipRender();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    #[On('customer-activity-invalidate')]
    public function handleActivityInvalidate(array $payload = []): void
    {
        $isReconcile = (bool) ($payload['isReconcile'] ?? false);
        $notificationId = is_string($payload['notificationId'] ?? null) ? $payload['notificationId'] : null;
        $notificationType = is_string($payload['notificationType'] ?? null) ? $payload['notificationType'] : null;

        if ($this->getPage() > 1) {
            $this->hasPendingRefresh = true;
            $this->skipRender();

            return;
        }

        $this->hasPendingRefresh = false;
        $this->forgetActivityResult();

        if (! $isReconcile && $this->shouldShowUrgentToast($notificationId, $notificationType)) {
            $this->warning(__('messages.activity_realtime_urgent_update'));
        }
    }

    public function getActivityProperty(): CustomerActivityResult
    {
        return $this->loadActivity();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getItemsProperty(): array
    {
        return app(CustomerActivityPresenter::class)->presentMany(
            $this->activity->items,
            auth()->user(),
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getActionRequiredSummaryProperty(): array
    {
        return app(CustomerActivityPresenter::class)->presentMany(
            $this->activity->actionRequiredSummary,
            auth()->user(),
        );
    }

    public function getPaginatorProperty(): LengthAwarePaginator
    {
        $result = $this->activity;

        return (new LengthAwarePaginator(
            $result->items,
            $result->total,
            $result->perPage,
            $result->currentPage,
            [
                'path' => request()->url(),
                'pageName' => 'page',
            ]
        ))->withQueryString();
    }

    public function render(): View
    {
        return $this->view()->title(__('messages.activity_page_title'));
    }

    private function normalizeFilters(): void
    {
        if (! in_array($this->filter, ['all', 'unread', 'action_required'], true)) {
            $this->filter = 'all';
        }

        if (! in_array($this->category, ['', 'orders', 'money', 'rewards', 'account'], true)) {
            $this->category = '';
        }
    }

    private function loadActivity(): CustomerActivityResult
    {
        if ($this->activityResult !== null) {
            return $this->activityResult;
        }

        $user = auth()->user();
        abort_unless($user !== null, 403);

        $result = app(GetCustomerActivity::class)->handle(
            user: $user,
            filter: $this->filter,
            category: $this->category !== '' ? $this->category : null,
            perPage: $this->perPage,
            page: $this->getPage(),
        );

        if ($result->lastPage > 0 && $this->getPage() > $result->lastPage) {
            $this->setPage($result->lastPage);

            $result = app(GetCustomerActivity::class)->handle(
                user: $user,
                filter: $this->filter,
                category: $this->category !== '' ? $this->category : null,
                perPage: $this->perPage,
                page: $this->getPage(),
            );
        }

        return $this->activityResult = $result;
    }

    private function forgetActivityResult(): void
    {
        $this->activityResult = null;
    }

    private function resolveUnreadCount(): int
    {
        $user = auth()->user();
        if ($user === null) {
            return 0;
        }

        return $user->unreadNotifications()->count();
    }

    private function shouldShowUrgentToast(?string $notificationId, ?string $notificationType): bool
    {
        if ($notificationId === null || $notificationId === '' || $notificationType === null) {
            return false;
        }

        $urgentTypes = [
            PaymentFailedNotification::class,
            FulfillmentFailedNotification::class,
            TopupRejectedNotification::class,
            RefundRejectedNotification::class,
        ];

        if (! in_array($notificationType, $urgentTypes, true)) {
            return false;
        }

        $sessionKey = 'activity_toast_'.$notificationId;
        if (session()->has($sessionKey)) {
            return false;
        }

        session()->put($sessionKey, true);

        return true;
    }
};
?>

<x-storefront.page
    width="work"
    data-test="activity-page"
    data-section="activity-page"
    x-data="{
        newIds: [],
        highlightNotificationId(id) {
            if (! id || this.newIds.includes(id)) return;
            this.newIds.push(id);
            setTimeout(() => {
                const index = this.newIds.indexOf(id);
                if (index !== -1) this.newIds.splice(index, 1);
            }, 8000);
        }
    }"
    x-on:customer-activity-invalidate.window="
        const id = $event.detail?.notificationId;
        if (id && ! ($event.detail?.isReconcile)) highlightNotificationId(id);
    "
>
    <section class="storefront-section-stack">
        <x-storefront.page-header
            :title="__('messages.activity_page_title')"
            :description="__('messages.activity_page_intro')"
            :show-back="true"
            :back-fallback="route('account')"
        >
            <x-slot:actions>
                <div x-data x-cloak x-show="$wire.unreadCount > 0">
                    <flux:button
                        variant="ghost"
                        size="sm"
                        wire:click="markAllAsRead"
                        wire:loading.attr="disabled"
                        wire:target="markAllAsRead"
                        data-test="activity-mark-all-read"
                        aria-label="{{ __('messages.activity_mark_all_read_aria') }}"
                    >
                        <span wire:loading.remove wire:target="markAllAsRead">{{ __('messages.mark_all_read') }}</span>
                        <span wire:loading wire:target="markAllAsRead" aria-live="polite">{{ __('messages.please_wait') }}</span>
                    </flux:button>
                </div>
            </x-slot:actions>
        </x-storefront.page-header>

        <x-activity.filter-bar
            :filter="$this->filter"
            :category="$this->category"
        />

        @if ($this->filter === 'all')
            <x-activity.action-required-section
                :items="$this->actionRequiredSummary"
                :total="$this->activity->actionRequiredTotal"
                :has-more="$this->activity->hasMoreActionRequired"
            />
        @endif

        <x-activity.pending-refresh-banner />

        <div
            id="activity-feed"
            class="relative"
            data-test="activity-feed"
            aria-busy="false"
            wire:loading.attr="aria-busy"
            wire:target="setFilter,setCategory,clearFilters,gotoPage,nextPage,previousPage,markAsRead,markAllAsRead,applyPendingRefresh"
        >
            <div
                wire:loading.delay.flex
                wire:target="setFilter,setCategory,clearFilters,gotoPage,nextPage,previousPage,applyPendingRefresh,customer-activity-invalidate"
                class="absolute inset-0 z-10 hidden items-start bg-white/70 dark:bg-zinc-950/70"
                data-test="activity-loading"
            >
                <x-storefront.skeleton-list :rows="4" class="w-full" />
            </div>

            <div
                class="flex flex-col gap-3"
                wire:loading.class="opacity-60"
                wire:target="setFilter,setCategory,clearFilters,gotoPage,nextPage,previousPage,applyPendingRefresh,customer-activity-invalidate"
                aria-live="polite"
                aria-atomic="true"
                data-test="activity-feed-live"
            >
                @forelse ($this->items as $item)
                    <x-activity.item
                        :item="$item"
                        wire:key="activity-{{ $item['stable_key'] }}"
                    />
                @empty
                    <x-activity.empty
                        :filter="$this->filter"
                        :category="$this->category"
                    />
                @endforelse
            </div>
        </div>

        @if ($this->paginator->hasPages())
            <div class="mt-6" data-test="activity-pagination">
                {{ $this->paginator->links() }}
            </div>
        @endif
    </section>
</x-storefront.page>
