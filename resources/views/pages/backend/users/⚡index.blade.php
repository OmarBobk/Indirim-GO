<?php

use App\Actions\Users\GetUsers;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\View\View;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    public string $search = '';

    public string $statusFilter = 'all';

    public string $sortBy = 'created_at';

    public string $sortDirection = 'desc';

    public int $perPage = 10;

    public bool $showFilters = false;

    protected $listeners = ['users-updated' => '$refresh'];

    public function mount(): void
    {
        $this->authorize('viewAny', User::class);
    }

    public function applyFilters(): void
    {
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset(['search', 'statusFilter', 'sortBy', 'sortDirection', 'perPage']);
        $this->resetPage();
    }

    public function getUsersProperty(): LengthAwarePaginator
    {
        return app(GetUsers::class)->handle(
            $this->search,
            $this->statusFilter,
            $this->sortBy,
            $this->sortDirection,
            $this->perPage,
            $this->getPage()
        );
    }

    public function render(): View
    {
        return $this->view()->title(__('messages.users'));
    }
};
?>

<div
    class="flex h-full w-full flex-1 flex-col gap-6"
    x-data="{ showFilters: false }"
    data-test="admin-users-page"
>
    <section class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-start">
                <div class="flex size-12 items-center justify-center rounded-xl bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">
                    <flux:icon icon="users" class="size-5" />
                </div>
                <div class="flex flex-col gap-2">
                    <div class="flex flex-col gap-1">
                        <flux:heading size="lg" class="text-zinc-900 dark:text-zinc-100">
                            {{ __('messages.users') }}
                        </flux:heading>
                        <flux:text class="text-zinc-600 dark:text-zinc-400">
                            {{ __('messages.users_intro') }}
                        </flux:text>
                    </div>
                    <div class="flex flex-wrap items-center gap-2 text-xs text-zinc-500 dark:text-zinc-400" role="status" aria-live="polite">
                        <span>{{ __('messages.showing') }}</span>
                        <span class="font-semibold text-zinc-900 dark:text-zinc-100">{{ $this->users->count() }}</span>
                        <span>{{ __('messages.of') }}</span>
                        <span class="font-semibold text-zinc-900 dark:text-zinc-100">{{ $this->users->total() }}</span>
                        <span>{{ __('messages.users') }}</span>
                        @if ($statusFilter !== 'all')
                            <flux:badge class="capitalize">
                                {{ $statusFilter === 'active' ? __('messages.active') : __('messages.blocked') }}
                            </flux:badge>
                        @endif
                    </div>
                </div>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <flux:button
                    type="button"
                    variant="primary"
                    icon="plus"
                    class="!bg-accent !text-accent-foreground hover:!bg-accent-hover"
                    wire:click="$dispatch('open-create-modal')"
                >
                    {{ __('messages.create_user') }}
                </flux:button>
                <flux:button
                    type="button"
                    variant="ghost"
                    icon="adjustments-horizontal"
                    x-on:click="showFilters = !showFilters"
                >
                    {{ __('messages.filters') }}
                </flux:button>
                <flux:button
                    type="button"
                    variant="ghost"
                    icon="arrow-path"
                    wire:click="$refresh"
                    wire:loading.attr="disabled"
                >
                    {{ __('messages.refresh') }}
                </flux:button>
                <flux:button
                    type="button"
                    variant="ghost"
                    icon="arrow-down-tray"
                    href="{{ route('admin.users.export') }}"
                    target="_blank"
                    rel="noopener"
                >
                    {{ __('messages.export') }}
                </flux:button>
            </div>
        </div>

        <form
            class="grid gap-4 pt-4"
            wire:submit.prevent="applyFilters"
            x-show="showFilters"
            x-cloak
            data-test="users-filters"
        >
            <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                <div class="grid gap-2">
                    <flux:input
                        name="search"
                        :label="__('messages.search')"
                        :placeholder="__('messages.users_search_placeholder')"
                        wire:model.defer="search"
                        class:input="focus:!border-(--color-accent) focus:!border-1 focus:!ring-0 focus:!outline-none focus:!ring-offset-0"
                    />
                </div>
                <div class="grid gap-2">
                    <flux:select class="focus:!border-(--color-accent) focus:!border-1 focus:!ring-0 focus:!outline-none focus:!ring-offset-0" name="sortBy" :label="__('messages.sort_by')" wire:model.defer="sortBy">
                        <flux:select.option value="created_at">{{ __('messages.created') }}</flux:select.option>
                        <flux:select.option value="last_login_at">{{ __('messages.last_login') }}</flux:select.option>
                        <flux:select.option value="status">{{ __('messages.status') }}</flux:select.option>
                    </flux:select>
                </div>
                <div class="grid gap-2">
                    <flux:select class="focus:!border-(--color-accent) focus:!border-1 focus:!ring-0 focus:!outline-none focus:!ring-offset-0" name="sortDirection" :label="__('messages.direction')" wire:model.defer="sortDirection">
                        <flux:select.option value="asc">{{ __('messages.ascending') }}</flux:select.option>
                        <flux:select.option value="desc">{{ __('messages.descending') }}</flux:select.option>
                    </flux:select>
                </div>
                <div class="grid gap-2">
                    <flux:select class="focus:!border-(--color-accent) focus:!border-1 focus:!ring-0 focus:!outline-none focus:!ring-offset-0" name="perPage" :label="__('messages.per_page')" wire:model.defer="perPage">
                        <flux:select.option value="10">10</flux:select.option>
                        <flux:select.option value="25">25</flux:select.option>
                        <flux:select.option value="50">50</flux:select.option>
                    </flux:select>
                </div>
            </div>
            <div class="flex flex-wrap justify-between gap-0 sm:justify-start sm:gap-3">
                <flux:select
                    class="focus:!border-(--color-accent) focus:!border-1 focus:!ring-0 focus:!outline-none focus:!ring-offset-0 !pe-0"
                    name="statusFilter"
                    :label="__('messages.status')"
                    wire:model.defer="statusFilter"
                >
                    <flux:select.option value="all">{{ __('messages.all') }}</flux:select.option>
                    <flux:select.option value="active">{{ __('messages.active') }}</flux:select.option>
                    <flux:select.option value="blocked">{{ __('messages.blocked') }}</flux:select.option>
                </flux:select>
                <div class="flex h-full flex-wrap items-end gap-2">
                    <flux:button type="submit" variant="primary" icon="magnifying-glass">
                        {{ __('messages.apply') }}
                    </flux:button>
                    <flux:button type="button" variant="ghost" icon="arrow-path" wire:click="resetFilters">
                        {{ __('messages.reset') }}
                    </flux:button>
                </div>
            </div>
        </form>
    </section>

    <section class="rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-zinc-100 px-5 py-4 dark:border-zinc-800">
            <flux:heading size="sm" class="text-zinc-900 dark:text-zinc-100">
                {{ __('messages.users') }}
            </flux:heading>
            <div class="flex items-center gap-2 text-xs text-zinc-500 dark:text-zinc-400">
                <span>{{ __('messages.total') }}</span>
                <span class="font-semibold text-zinc-900 dark:text-zinc-100">{{ $this->users->total() }}</span>
            </div>
        </div>

        <div
            class="overflow-x-auto"
            wire:loading.class="opacity-60"
            wire:target="applyFilters,resetFilters,$refresh,nextPage,previousPage,gotoPage"
        >
            <div
                class="p-6"
                wire:loading.delay
                wire:target="applyFilters,resetFilters,$refresh,nextPage,previousPage,gotoPage"
            >
                <div class="grid gap-3">
                    <flux:skeleton class="h-4 w-36" />
                    <div class="grid gap-2">
                        <flux:skeleton class="h-10 w-full" />
                        <flux:skeleton class="h-10 w-full" />
                        <flux:skeleton class="h-10 w-full" />
                        <flux:skeleton class="h-10 w-full" />
                        <flux:skeleton class="h-10 w-full" />
                    </div>
                </div>
            </div>
            <div wire:loading.delay.remove>
                @if ($this->users->count() === 0)
                    <div class="flex flex-col items-center gap-3 p-10 text-center">
                        <div class="flex size-12 items-center justify-center rounded-full bg-zinc-100 text-zinc-500 dark:bg-zinc-800 dark:text-zinc-300">
                            <flux:icon icon="users" class="size-5" />
                        </div>
                        <div class="flex flex-col gap-1">
                            <flux:heading size="sm" class="text-zinc-900 dark:text-zinc-100">
                                {{ __('messages.no_users_yet') }}
                            </flux:heading>
                            <flux:text class="text-zinc-600 dark:text-zinc-400">
                                {{ __('messages.create_first_user') }}
                            </flux:text>
                        </div>
                        <flux:button
                            type="button"
                            variant="primary"
                            icon="plus"
                            class="!bg-accent !text-accent-foreground hover:!bg-accent-hover"
                            wire:click="$dispatch('open-create-modal')"
                        >
                            {{ __('messages.create_user') }}
                        </flux:button>
                    </div>
                @else
                    <table class="min-w-full divide-y divide-zinc-100 text-sm dark:divide-zinc-800" data-test="users-table">
                        <thead class="bg-zinc-50 text-xs uppercase tracking-wide text-zinc-500 dark:bg-zinc-800/60 dark:text-zinc-400">
                            <tr>
                                <th class="px-5 py-3 text-start font-semibold">{{ __('messages.id') }}</th>
                                <th class="px-5 py-3 text-start font-semibold">{{ __('messages.name') }}</th>
                                <th class="px-5 py-3 text-start font-semibold">{{ __('messages.email') }}</th>
                                <th class="px-5 py-3 text-start font-semibold">{{ __('messages.phone') }}</th>
                                <th class="px-5 py-3 text-start font-semibold">{{ __('messages.username') }}</th>
                                <th class="px-5 py-3 text-start font-semibold">{{ __('messages.user_registration') }}</th>
                                <th class="px-5 py-3 text-start font-semibold">{{ __('messages.roles') }}</th>
                                <th class="px-5 py-3 text-start font-semibold">{{ __('messages.status') }}</th>
                                <th class="px-5 py-3 text-start font-semibold">{{ __('messages.email_verified') }}</th>
                                <th class="px-5 py-3 text-start font-semibold">{{ __('messages.last_login') }}</th>
                                <th class="px-5 py-3 text-end font-semibold">{{ __('messages.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                            @foreach ($this->users as $u)
                                <tr
                                    class="transition hover:bg-zinc-50 dark:hover:bg-zinc-800/60"
                                    wire:key="user-{{ $u->id }}"
                                >
                                    <td class="px-5 py-4 text-zinc-600 dark:text-zinc-300 tabular-nums">{{ $u->id }}</td>
                                    <td class="px-5 py-4 font-medium text-zinc-900 dark:text-zinc-100">{{ $u->name }}</td>
                                    <td class="px-5 py-4 text-zinc-600 dark:text-zinc-300">{{ $u->email }}</td>
                                    <td class="px-5 py-4 text-zinc-600 dark:text-zinc-300" dir="ltr">{{ ($u->country_code || $u->phone) ? trim(($u->country_code ?? '') . ' ' . ($u->phone ?? '')) : '—' }}</td>
                                    <td class="px-5 py-4 text-zinc-600 dark:text-zinc-300">{{ $u->username }}</td>
                                    <td class="max-w-xs px-5 py-4 text-xs leading-snug text-zinc-600 dark:text-zinc-300">
                                        {{ $u->registration_summary ?: '—' }}
                                    </td>
                                    <td class="px-5 py-4 text-zinc-600 dark:text-zinc-300">
                                        {{
                                            $u->roles
                                                ->pluck('name')
                                                ->map(fn ($roleName) => \Illuminate\Support\Facades\Lang::has('messages.role_'.$roleName)
                                                    ? __('messages.role_'.$roleName)
                                                    : str_replace('_', ' ', \Illuminate\Support\Str::headline($roleName)))
                                                ->implode(', ') ?: '—'
                                        }}
                                    </td>
                                    <td class="px-5 py-4">
                                        @if ($u->blocked_at)
                                            <flux:badge color="red">{{ __('messages.blocked') }}</flux:badge>
                                        @else
                                            <flux:badge color="green">{{ __('messages.active') }}</flux:badge>
                                        @endif
                                    </td>
                                    <td class="px-5 py-4 text-zinc-600 dark:text-zinc-300">
                                        {{ $u->email_verified_at ? $u->email_verified_at->format('M d, Y') : '—' }}
                                    </td>
                                    <td class="px-5 py-4 text-zinc-600 dark:text-zinc-300">
                                        {{ $u->last_login_at ? $u->last_login_at->format('M d, Y H:i') : '—' }}
                                    </td>
                                    <td class="px-5 py-4 text-end">
                                        <flux:dropdown position="bottom" align="end">
                                            <flux:button variant="ghost" icon="ellipsis-vertical" />
                                            <flux:menu>
                                                <flux:menu.item icon="eye" :href="route('admin.users.show', $u)" wire:navigate>
                                                    {{ __('messages.view_customer') }}
                                                </flux:menu.item>
                                                <flux:menu.item icon="pencil" wire:click="$dispatch('open-edit-modal', { userId: {{ $u->id }} })">
                                                    {{ __('messages.edit') }}
                                                </flux:menu.item>
                                                @if ($u->blocked_at)
                                                    <flux:menu.item icon="lock-open" wire:click="$dispatch('open-unblock-modal', { userId: {{ $u->id }} })">
                                                        {{ __('messages.unblock') }}
                                                    </flux:menu.item>
                                                @else
                                                    <flux:menu.item icon="lock-closed" wire:click="$dispatch('open-block-modal', { userId: {{ $u->id }} })">
                                                        {{ __('messages.block') }}
                                                    </flux:menu.item>
                                                @endif
                                                <flux:menu.item icon="key" wire:click="$dispatch('open-reset-password-modal', { userId: {{ $u->id }} })">
                                                    {{ __('messages.reset_password') }}
                                                </flux:menu.item>
                                                @if (! $u->email_verified_at)
                                                    <flux:menu.item icon="envelope" wire:click="$dispatch('open-verify-email-modal', { userId: {{ $u->id }} })">
                                                        {{ __('messages.verify_email') }}
                                                    </flux:menu.item>
                                                @endif
                                                <flux:menu.separator />
                                                <flux:menu.item
                                                    variant="danger"
                                                    icon="trash"
                                                    wire:click="$dispatch('open-delete-modal', { userId: {{ $u->id }} })"
                                                >
                                                    {{ __('messages.delete') }}
                                                </flux:menu.item>
                                            </flux:menu>
                                        </flux:dropdown>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        </div>

        <div class="border-t border-zinc-100 px-5 py-4 dark:border-zinc-800">
            {{ $this->users->links() }}
        </div>
    </section>

    <livewire:users.user-modals />
</div>
