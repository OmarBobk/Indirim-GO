<?php

use App\Actions\Users\AdminResetUserPassword;
use App\Actions\Users\CreateUser;
use App\Actions\Users\GetReferredUsers;
use App\Actions\Users\UpdateUser;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Component;
use Livewire\WithPagination;
use Masmerise\Toaster\Toastable;

new class extends Component
{
    use Toastable;
    use WithPagination;

    private const ALLOWED_REFERRER_COUNTRY_CODES = ['+963', '+90'];

    protected $listeners = ['users-updated' => '$refresh'];

    public string $search = '';

    public string $statusFilter = 'all';

    public string $sortBy = 'created_at';

    public string $sortDirection = 'desc';

    public int $perPage = 10;

    public bool $showFilters = false;

    public function mount(): void
    {
        abort_unless(auth()->user()?->can('manage_referred_users'), 403);
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
        $referrerId = (int) auth()->id();

        return app(GetReferredUsers::class)->handle(
            $referrerId,
            $this->search,
            $this->statusFilter,
            $this->sortBy,
            $this->sortDirection,
            $this->perPage,
            $this->getPage()
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{ok: bool, errors?: array<string, array<int, string>>}
     */
    public function referredSaveEdit(array $payload): array
    {
        $id = (int) ($payload['id'] ?? 0);
        $user = User::query()->findOrFail($id);
        $this->authorize('update', $user);

        $input = [
            'name' => (string) ($payload['name'] ?? ''),
            'username' => (string) ($payload['username'] ?? ''),
            'email' => (string) ($payload['email'] ?? ''),
            'phone' => ($payload['phone'] ?? '') !== '' ? (string) $payload['phone'] : null,
            'country_code' => $this->normalizeReferredCountryCode($payload['country_code'] ?? null),
        ];

        try {
            app(UpdateUser::class)->handle($user, $input, (int) auth()->id(), true);
        } catch (ValidationException $e) {
            $this->error(__('messages.validation_failed'));

            return ['ok' => false, 'errors' => $e->errors()];
        }

        $password = trim((string) ($payload['password'] ?? ''));
        if ($password !== '') {
            $user->refresh();
            $this->authorize('resetPassword', $user);
            try {
                app(AdminResetUserPassword::class)->handle($user, [
                    'password' => (string) ($payload['password'] ?? ''),
                    'password_confirmation' => (string) ($payload['password_confirmation'] ?? ''),
                ], (int) auth()->id());
            } catch (ValidationException $e) {
                $this->error(__('messages.validation_failed'));

                return ['ok' => false, 'errors' => $e->errors()];
            }
        }

        $this->success(__('messages.user_updated'));
        $this->dispatch('users-updated');

        return ['ok' => true];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{ok: bool, errors?: array<string, array<int, string>>}
     */
    public function referredResetPassword(array $payload): array
    {
        $id = (int) ($payload['id'] ?? 0);
        $user = User::query()->findOrFail($id);
        $this->authorize('resetPassword', $user);

        try {
            app(AdminResetUserPassword::class)->handle($user, [
                'password' => (string) ($payload['password'] ?? ''),
                'password_confirmation' => (string) ($payload['password_confirmation'] ?? ''),
            ], (int) auth()->id());
        } catch (ValidationException $e) {
            $this->error(__('messages.validation_failed'));

            return ['ok' => false, 'errors' => $e->errors()];
        }

        $this->success(__('messages.password_reset'));
        $this->dispatch('users-updated');

        return ['ok' => true];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{ok: bool, errors?: array<string, array<int, string>>}
     */
    public function referredCreateUser(array $payload): array
    {
        $this->authorize('create', User::class);

        $input = [
            'name' => (string) ($payload['name'] ?? ''),
            'username' => (string) ($payload['username'] ?? ''),
            'email' => (string) ($payload['email'] ?? ''),
            'password' => (string) ($payload['password'] ?? ''),
            'password_confirmation' => (string) ($payload['password_confirmation'] ?? ''),
            'phone' => ($payload['phone'] ?? '') !== '' ? (string) $payload['phone'] : null,
            'country_code' => $this->normalizeReferredCountryCode($payload['country_code'] ?? null),
        ];

        try {
            app(CreateUser::class)->handle($input, (int) auth()->id(), (int) auth()->id());
        } catch (ValidationException $e) {
            $this->error(__('messages.validation_failed'));

            return ['ok' => false, 'errors' => $e->errors()];
        }

        $this->success(__('messages.user_created'));
        $this->dispatch('users-updated');

        return ['ok' => true];
    }

    private function normalizeReferredCountryCode(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $s = (string) $value;

        return in_array($s, self::ALLOWED_REFERRER_COUNTRY_CODES, true) ? $s : null;
    }

    public function render(): View
    {
        return $this->view()->title(__('messages.users_under_salesperson'));
    }
};
?>

<div
    class="flex h-full w-full flex-1 flex-col gap-6"
    x-data="salespersonReferredUsersPage()"
    @keydown.escape.window="if (editOpen) { closeEdit(); } else if (resetOpen) { closeReset(); } else if (createOpen) { closeCreate(); }"
    data-test="salesperson-users-page"
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
                            {{ __('messages.users_under_salesperson') }}
                        </flux:heading>
                        <flux:text class="text-zinc-600 dark:text-zinc-400">
                            {{ __('messages.users_under_salesperson_intro') }}
                        </flux:text>
                    </div>
                    <div class="flex flex-wrap items-center gap-2 text-xs text-zinc-500 dark:text-zinc-400" role="status" aria-live="polite">
                        <span>{{ __('messages.showing') }}</span>
                        <span class="font-semibold text-zinc-900 dark:text-zinc-100">{{ $this->users->count() }}</span>
                        <span>{{ __('messages.of') }}</span>
                        <span class="font-semibold text-zinc-900 dark:text-zinc-100">{{ $this->users->total() }}</span>
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
                    @click="openCreateModal()"
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
            </div>
        </div>

        <form
            class="grid gap-4 pt-4"
            wire:submit.prevent="applyFilters"
            x-show="showFilters"
            x-cloak
            data-test="salesperson-users-filters"
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
                                {{ __('messages.no_referred_users_yet') }}
                            </flux:heading>
                            <flux:text class="text-zinc-600 dark:text-zinc-400">
                                {{ __('messages.no_referred_users_hint') }}
                            </flux:text>
                        </div>
                        <flux:button
                            type="button"
                            variant="primary"
                            icon="plus"
                            class="!bg-accent !text-accent-foreground hover:!bg-accent-hover"
                            @click="openCreateModal()"
                        >
                            {{ __('messages.create_user') }}
                        </flux:button>
                    </div>
                @else
                    <table class="min-w-full divide-y divide-zinc-100 text-sm dark:divide-zinc-800" data-test="salesperson-users-table">
                        <thead class="bg-zinc-50 text-xs uppercase tracking-wide text-zinc-500 dark:bg-zinc-800/60 dark:text-zinc-400">
                            <tr>
                                <th class="px-5 py-3 text-start font-semibold">{{ __('messages.name') }}</th>
                                <th class="px-5 py-3 text-start font-semibold">{{ __('messages.email') }}</th>
                                <th class="px-5 py-3 text-start font-semibold">{{ __('messages.username') }}</th>
                                <th class="px-5 py-3 text-start font-semibold">{{ __('messages.roles') }}</th>
                                <th class="px-5 py-3 text-start font-semibold">{{ __('messages.status') }}</th>
                                <th class="px-5 py-3 text-start font-semibold">{{ __('messages.last_login') }}</th>
                                <th class="px-5 py-3 text-end font-semibold">{{ __('messages.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                            @foreach ($this->users as $u)
                                @php
                                    $referredRowPayload = [
                                        'id' => $u->id,
                                        'name' => $u->name,
                                        'username' => $u->username,
                                        'email' => $u->email,
                                        'phone' => $u->phone,
                                        'country_code' => $u->country_code,
                                    ];
                                    $referredRowPayloadB64 = base64_encode(json_encode($referredRowPayload, JSON_THROW_ON_ERROR));
                                @endphp
                                <tr
                                    class="transition hover:bg-zinc-50 dark:hover:bg-zinc-800/60"
                                    wire:key="referred-user-{{ $u->id }}"
                                >
                                    <td class="px-5 py-4 font-medium text-zinc-900 dark:text-zinc-100">{{ $u->name }}</td>
                                    <td class="px-5 py-4 text-zinc-600 dark:text-zinc-300">{{ $u->email }}</td>
                                    <td class="px-5 py-4 text-zinc-600 dark:text-zinc-300">{{ $u->username }}</td>
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
                                        {{ $u->last_login_at ? $u->last_login_at->format('M d, Y H:i') : '—' }}
                                    </td>
                                    <td class="px-5 py-4 text-end">
                                        <flux:dropdown position="bottom" align="end">
                                            <flux:button variant="ghost" icon="ellipsis-vertical" />
                                            <flux:menu>
                                                <flux:menu.item icon="eye" :href="route('salesperson.users.show', $u)" wire:navigate>
                                                    {{ __('messages.view_customer') }}
                                                </flux:menu.item>
                                                <flux:menu.item
                                                    icon="pencil"
                                                    data-referred-user="{{ $referredRowPayloadB64 }}"
                                                    @click="openEditFromDataset($event.currentTarget)"
                                                >
                                                    {{ __('messages.edit') }}
                                                </flux:menu.item>
                                                <flux:menu.item
                                                    icon="key"
                                                    data-referred-user="{{ $referredRowPayloadB64 }}"
                                                    @click="openResetFromDataset($event.currentTarget)"
                                                >
                                                    {{ __('messages.reset_password') }}
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

    @include('pages.backend.salesperson-users._referred-user-modals')
</div>
