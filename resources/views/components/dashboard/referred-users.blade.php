@props(['users' => []])

@php
    $usersJson = \Illuminate\Support\Js::from($users);
@endphp

<section
    class="glass-card overflow-hidden rounded-2xl p-5 sm:p-6 md:p-7"
    x-data="{
        users: {{ $usersJson }},
        search: '',
        filtered() {
            const q = this.search.trim().toLowerCase();
            if (q === '') {
                return this.users;
            }

            return this.users.filter((u) => {
                const hay = [
                    u.name ?? '',
                    u.email ?? '',
                    u.username ?? '',
                    u.phone ?? '',
                    u.roles_label ?? '',
                ].join(' ').toLowerCase();

                return hay.includes(q);
            });
        },
    }"
    wire:key="dashboard-referred-users"
>
    <div class="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
        <div class="flex min-w-0 items-start gap-3">
            <span
                class="grid size-9 shrink-0 place-items-center rounded-xl ring-1 ring-[hsl(var(--accent-customers)/0.22)]"
                style="background: hsl(var(--accent-customers) / 0.14);"
            >
                <flux:icon icon="users" variant="outline" class="size-4 text-[hsl(var(--accent-customers))]" />
            </span>
            <div class="min-w-0">
                <h2 class="dashboard-text text-base font-semibold tracking-tight">
                    {{ __('messages.dashboard_referred_users_title') }}
                </h2>
                <p class="mt-0.5 text-xs leading-snug text-[hsl(var(--foreground)/0.48)]">
                    {{ __('messages.dashboard_referred_users_subtitle') }}
                </p>
                <p class="mt-1 text-[11px] text-[hsl(var(--foreground)/0.42)]" role="status" aria-live="polite">
                    <span x-text="filtered().length"></span>
                    <span class="mx-0.5">·</span>
                    {{ __('messages.total') }}
                    <span x-text="users.length"></span>
                </p>
            </div>
        </div>

        <label class="relative flex w-full min-w-0 items-center gap-2 rounded-xl border border-[hsl(var(--foreground)/0.08)] bg-[hsl(var(--surface-2)/0.55)] px-3 py-2 ring-1 ring-[hsl(var(--foreground)/0.06)] md:max-w-sm">
            <flux:icon icon="magnifying-glass" variant="outline" class="size-3.5 shrink-0 text-[hsl(var(--foreground)/0.45)]" />
            <input
                type="search"
                x-model.debounce.250ms="search"
                placeholder="{{ __('messages.dashboard_referred_users_search_placeholder') }}"
                class="min-w-0 flex-1 border-0 bg-transparent text-xs text-[hsl(var(--foreground))] outline-none placeholder:text-[hsl(var(--foreground)/0.38)]"
                autocomplete="off"
            />
        </label>
    </div>

    <div class="mt-5 overflow-x-auto rounded-xl border border-[hsl(var(--foreground)/0.06)] ring-1 ring-[hsl(var(--foreground)/0.06)]">
        <table class="min-w-full divide-y divide-white/[0.06] text-start text-sm">
            <thead class="bg-[hsl(var(--surface-2)/0.45)] text-[11px] font-semibold uppercase tracking-wide text-[hsl(var(--foreground)/0.45)]">
                <tr>
                    <th class="px-4 py-3 text-start">{{ __('messages.dashboard_referred_users_col_name') }}</th>
                    <th class="hidden px-4 py-3 text-start sm:table-cell">{{ __('messages.dashboard_referred_users_col_email') }}</th>
                    <th class="hidden px-4 py-3 text-start md:table-cell">{{ __('messages.dashboard_referred_users_col_username') }}</th>
                    <th class="hidden px-4 py-3 text-start lg:table-cell">{{ __('messages.dashboard_referred_users_col_roles') }}</th>
                    <th class="px-4 py-3 text-start">{{ __('messages.dashboard_referred_users_col_status') }}</th>
                    <th class="hidden px-4 py-3 text-start xl:table-cell">{{ __('messages.dashboard_referred_users_col_last_login') }}</th>
                    <th class="px-4 py-3 text-end">{{ __('messages.actions') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-white/[0.05]">
                <tr x-show="!users.length">
                    <td colspan="7" class="px-4 py-10 text-center text-sm text-[hsl(var(--foreground)/0.5)]">
                        {{ __('messages.dashboard_referred_users_empty') }}
                    </td>
                </tr>
                <tr x-show="users.length && !filtered().length" x-cloak>
                    <td colspan="7" class="px-4 py-10 text-center text-sm text-[hsl(var(--foreground)/0.5)]">
                        {{ __('messages.dashboard_referred_users_no_matches') }}
                    </td>
                </tr>
                <template x-for="row in filtered()" :key="row.id">
                    <tr class="bg-[hsl(var(--surface-1)/0.25)] transition hover:bg-[hsl(var(--surface-2)/0.4)]">
                        <td class="dashboard-text max-w-[10rem] px-4 py-3 font-medium sm:max-w-none">
                            <template x-if="row.show_url">
                                <a
                                    :href="row.show_url"
                                    wire:navigate
                                    class="block truncate text-[hsl(var(--accent-customers))] underline-offset-2 hover:underline"
                                    x-text="row.name || '—'"
                                ></a>
                            </template>
                            <template x-if="!row.show_url">
                                <span class="block truncate" x-text="row.name || '—'"></span>
                            </template>
                            <p class="mt-0.5 truncate text-[11px] text-[hsl(var(--foreground)/0.48)] sm:hidden" dir="ltr" x-text="row.email || '—'"></p>
                        </td>
                        <td class="hidden max-w-[12rem] px-4 py-3 text-[hsl(var(--foreground)/0.78)] sm:table-cell">
                            <span class="block truncate" dir="ltr" x-text="row.email || '—'"></span>
                        </td>
                        <td class="hidden px-4 py-3 text-[hsl(var(--foreground)/0.78)] md:table-cell">
                            <span class="block truncate" dir="ltr" x-text="row.username || '—'"></span>
                        </td>
                        <td class="hidden max-w-[10rem] px-4 py-3 text-[hsl(var(--foreground)/0.72)] lg:table-cell">
                            <span class="line-clamp-2 text-xs" x-text="row.roles_label || '—'"></span>
                        </td>
                        <td class="px-4 py-3">
                            <span
                                class="inline-flex rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide ring-1"
                                :class="row.active ? 'bg-emerald-500/15 text-emerald-300 ring-emerald-500/25' : 'bg-red-500/12 text-red-300 ring-red-500/25'"
                                x-text="row.active ? @js(__('messages.active')) : @js(__('messages.blocked'))"
                            ></span>
                        </td>
                        <td class="hidden whitespace-nowrap px-4 py-3 text-xs text-[hsl(var(--foreground)/0.65)] xl:table-cell" dir="ltr" x-text="row.last_login_at || '—'"></td>
                        <td class="px-4 py-3 text-end">
                            <template x-if="row.show_url">
                                <a
                                    :href="row.show_url"
                                    wire:navigate
                                    class="text-xs font-medium text-[hsl(var(--accent-customers))] underline-offset-2 hover:underline"
                                >
                                    {{ __('messages.view_customer') }}
                                </a>
                            </template>
                            <template x-if="!row.show_url">
                                <span class="text-[11px] text-[hsl(var(--foreground)/0.35)]">—</span>
                            </template>
                        </td>
                    </tr>
                </template>
            </tbody>
        </table>
    </div>
</section>
