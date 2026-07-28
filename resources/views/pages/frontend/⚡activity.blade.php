<?php

use App\Actions\Activity\GetCustomerActivity;
use App\DTOs\CustomerActivityResult;
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

    public function mount(): void
    {
        abort_unless(auth()->check(), 403);
        $this->normalizeFilters();
    }

    public function updatedFilter(): void
    {
        $this->normalizeFilters();
        $this->resetPage();
    }

    public function updatedCategory(): void
    {
        $this->normalizeFilters();
        $this->resetPage();
    }

    public function setFilter(string $filter): void
    {
        $this->filter = in_array($filter, ['all', 'unread', 'action_required'], true) ? $filter : 'all';
        $this->resetPage();
    }

    public function setCategory(string $category): void
    {
        $allowed = ['', 'orders', 'money', 'rewards', 'account'];
        $this->category = in_array($category, $allowed, true) ? $category : '';
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->filter = 'all';
        $this->category = '';
        $this->resetPage();
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

        $this->dispatch('customer-notifications-changed');
    }

    public function markAllAsRead(): void
    {
        $user = auth()->user();
        if ($user === null) {
            return;
        }

        $user->unreadNotifications()->update(['read_at' => now()]);
        $this->dispatch('customer-notifications-changed');
        $this->success(__('messages.activity_marked_all_read'));
    }

    #[On('customer-notifications-changed')]
    public function refreshAfterNotificationChange(): void
    {
        // Re-render computed Activity result / unread count.
    }

    public function getActivityProperty(): CustomerActivityResult
    {
        $user = auth()->user();
        abort_unless($user !== null, 403);

        return app(GetCustomerActivity::class)->handle(
            user: $user,
            filter: $this->filter,
            category: $this->category !== '' ? $this->category : null,
            perPage: $this->perPage,
            page: $this->getPage(),
        );
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
};
?>

<x-storefront.page
    width="work"
    data-test="activity-page"
    data-section="activity-page"
    x-data="{ newIds: [] }"
    x-on:notification-received.window="const id = $event.detail?.id; if (id) newIds.push(id); $wire.$refresh(); setTimeout(() => { const i = newIds.indexOf(id); if (i !== -1) newIds.splice(i, 1); }, 8000)"
>
    <section class="storefront-section-stack">
        <x-storefront.page-header
            :title="__('messages.activity_page_title')"
            :description="__('messages.activity_page_intro')"
            :show-back="true"
            :back-fallback="route('account')"
        >
            <x-slot:actions>
                @if ($this->activity->unreadCount > 0)
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
                @endif
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

        <div
            id="activity-feed"
            class="relative"
            data-test="activity-feed"
            aria-busy="false"
            wire:loading.attr="aria-busy"
            wire:target="setFilter,setCategory,clearFilters,gotoPage,nextPage,previousPage,markAsRead,markAllAsRead"
        >
            <div
                wire:loading.delay.flex
                wire:target="setFilter,setCategory,clearFilters,gotoPage,nextPage,previousPage"
                class="absolute inset-0 z-10 hidden items-start bg-white/70 dark:bg-zinc-950/70"
                data-test="activity-loading"
            >
                <x-storefront.skeleton-list :rows="4" class="w-full" />
            </div>

            <div
                class="flex flex-col gap-3"
                wire:loading.class="opacity-60"
                wire:target="setFilter,setCategory,clearFilters,gotoPage,nextPage,previousPage"
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
