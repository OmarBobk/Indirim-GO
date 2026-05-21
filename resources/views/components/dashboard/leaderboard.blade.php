@props(['customers' => []])

@php
    $customersJson = \Illuminate\Support\Js::from($customers);
@endphp

<article
    class="glass-card rounded-2xl p-6 sm:p-7"
    x-data="{
        showAll: false,
        all: {{ $customersJson }},
        topVal() {
            const cs = (this.all || []).map((c) => parseFloat(c.commission) || 0);
            return cs.length ? Math.max(...cs) : 1;
        },
        ratioFor(customer) {
            const t = this.topVal();
            const v = parseFloat(customer.commission) || 0;
            return t > 0 ? Math.min(100, (v / t) * 100) : 0;
        },
        ringFor(index) {
            return index === 0 ? 'ring-amber-400/35' : (index === 1 ? 'ring-zinc-400/35' : (index === 2 ? 'ring-orange-400/30' : 'ring-white/10'));
        },
    }"
>
    <div class="mb-5 flex items-center justify-between gap-3">
        <div class="flex min-w-0 items-center gap-2.5">
            <span
                class="grid size-9 shrink-0 place-items-center rounded-xl ring-1 ring-[hsl(var(--accent-customers)/0.25)]"
                style="background: hsl(var(--accent-customers) / 0.14);"
            >
                <flux:icon icon="trophy" variant="outline" class="size-4 text-[hsl(var(--accent-customers))]" />
            </span>
            <p class="dashboard-earnings-eyebrow leading-tight">{{ __('messages.dashboard_top_customers') }}</p>
        </div>
        <span class="shrink-0 text-[11px] text-[hsl(var(--foreground)/0.48)]">
            {{ __('messages.dashboard_top_customers_period') }}
        </span>
    </div>

    <ul class="space-y-3">
        <template x-if="!all.length">
            <li
                class="rounded-xl border border-dashed border-white/15 bg-[hsl(var(--surface-2)/0.35)] px-4 py-6 text-center text-sm text-[hsl(var(--foreground)/0.55)]"
            >
                {{ __('messages.dashboard_leaderboard_empty') }}
            </li>
        </template>
        <template x-for="(customer, index) in (showAll ? all : all.slice(0, 3))" :key="(customer.email || '') + '-' + index">
            <li
                class="group rounded-xl border border-white/[0.06] bg-[hsl(var(--surface-2)/0.55)] p-3 ring-1 ring-white/[0.04] transition hover:border-white/[0.1] hover:ring-white/[0.08] sm:p-3.5"
            >
                <div class="flex items-start gap-3">
                    <div class="relative shrink-0">
                        <div
                            class="grid size-10 place-items-center rounded-xl bg-gradient-to-br from-[hsl(var(--accent-orders)/0.35)] to-[hsl(var(--accent-customers)/0.28)] text-sm font-semibold text-white ring-1"
                            x-bind:class="ringFor(index)"
                        >
                            <span
                                class="leading-none"
                                x-text="(() => {
                                    const name = String(customer.name || '—').trim();
                                    const parts = name.split(/\s+/).filter(Boolean);
                                    if (!parts.length) return '—';
                                    if (parts.length >= 2) {
                                        return (parts[0].charAt(0) + parts[1].charAt(0)).toUpperCase();
                                    }
                                    const one = parts[0];
                                    return one.length >= 2 ? one.slice(0, 2).toUpperCase() : (one.charAt(0).toUpperCase() || '—');
                                })()"
                            ></span>
                        </div>
                        <span
                            class="absolute -bottom-0.5 -end-0.5 grid size-5 place-items-center rounded-full border border-[hsl(var(--surface-2))] bg-[hsl(var(--surface-1))] shadow-sm"
                        >
                            <flux:icon icon="star" variant="outline" class="size-3 text-amber-400" x-show="index === 0" />
                            <flux:icon icon="trophy" variant="outline" class="size-3 text-zinc-400" x-show="index === 1" />
                            <flux:icon icon="sparkles" variant="outline" class="size-3 text-orange-400" x-show="index === 2" />
                            <flux:icon icon="user" variant="outline" class="size-3 text-zinc-500" x-show="index > 2" />
                        </span>
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <p class="truncate font-medium text-white" x-text="customer.name || '—'"></p>
                                <div class="mt-0.5 flex items-center justify-between gap-2 text-[11px] text-[hsl(var(--foreground)/0.48)]">
                                    <span class="min-w-0 truncate" dir="ltr" x-text="(customer.email && customer.email.trim()) ? customer.email.trim() : '—'"></span>
                                    <span class="num shrink-0 tabular-nums"><span x-text="parseInt(customer.orders, 10) || 0"></span> {{ __('messages.orders') }}</span>
                                </div>
                            </div>
                            <p class="num shrink-0 text-sm font-semibold text-[hsl(var(--accent-earnings))]">
                                $<span x-text="Number(customer.commission || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })"></span>
                            </p>
                        </div>
                        <div class="mt-2.5 h-1.5 w-full overflow-hidden rounded-full bg-[hsl(var(--surface-3)/0.75)]">
                            <div
                                class="h-full rounded-full bg-gradient-to-r from-[hsl(var(--accent-orders))] to-[hsl(var(--accent-customers))] transition-[width] duration-500"
                                x-bind:style="'width: ' + ratioFor(customer).toFixed(2) + '%; box-shadow: 0 0 14px hsl(var(--accent-customers) / 0.35);'"
                            ></div>
                        </div>
                    </div>
                </div>
            </li>
        </template>
    </ul>

    <button
        type="button"
        class="mt-5 flex w-full items-center justify-center gap-1 text-xs text-[hsl(var(--foreground)/0.48)] transition hover:text-white focus:outline-none focus-visible:text-white"
        x-show="all.length > 3"
        x-cloak
        @click="showAll = !showAll"
    >
        <span x-show="!showAll">{{ __('messages.dashboard_view_all_customers') }}</span>
        <span x-show="showAll" x-cloak>{{ __('messages.dashboard_show_fewer_customers') }}</span>
        <flux:icon
            icon="chevron-right"
            variant="outline"
            class="size-3.5 transition-transform rtl:rotate-180"
            x-bind:class="showAll ? 'rotate-90 rtl:rotate-90' : ''"
        />
    </button>

</article>
