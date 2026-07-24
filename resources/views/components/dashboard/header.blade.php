@props([
    'activeRange' => '30d',
    'referralLink' => '',
    /** When null, welcome line uses the signed-in user. */
    'displayName' => null,
])

<section class="glass-card relative overflow-hidden p-4 sm:p-5">
    <div class="pointer-events-none absolute inset-0 opacity-40 [background-image:radial-gradient(circle_at_0%_20%,hsl(var(--accent-customers)/0.24),transparent_42%),radial-gradient(circle_at_100%_0%,hsl(var(--accent-orders)/0.20),transparent_48%)]"></div>

    <div class="relative flex flex-wrap items-center justify-between gap-4">
        <div class="flex items-center gap-3">
            <div class="grid size-11 place-content-center rounded-xl bg-[linear-gradient(135deg,hsl(var(--accent-customers)/0.35),hsl(var(--accent-orders)/0.35))] ring-1 ring-white/15">
                <span class="text-sm font-semibold text-white">OM</span>
            </div>
            <div class="space-y-0.5">
                <p class="label-eyebrow">{{ __('messages.salesperson_dashboard') }}</p>
                @php
                    $welcomeName = $displayName ?? auth()->user()?->name ?? __('messages.salesperson_dashboard_fallback_name');
                @endphp
                <h1 class="text-lg font-semibold text-white sm:text-xl">{{ __('messages.salesperson_dashboard_welcome_back', ['name' => $welcomeName]) }}</h1>
                <p class="text-xs text-zinc-400">{{ __('messages.salesperson_dashboard_intro') }}</p>
            </div>
        </div>

        <div
            class="flex flex-wrap items-center justify-end gap-2"
            x-data="{
                copied: false,
                copy() {
                    navigator.clipboard.writeText(@js($referralLink)).then(() => {
                        this.copied = true;
                        setTimeout(() => this.copied = false, 1800);
                    });
                }
            }"
        >

            <div class="inline-flex items-center gap-2 rounded-xl border border-white/10 bg-[hsl(var(--surface-2)/0.72)] px-3 py-2">
                <span class="max-w-40 truncate text-xs text-zinc-400 sm:max-w-52">{{ $referralLink }}</span>
                <button type="button" class="rounded-md bg-[hsl(var(--accent-customers)/0.18)] px-2 py-1 text-xs font-medium text-[hsl(var(--accent-customers))] transition duration-300 hover:scale-[1.04] hover:shadow-[0_0_24px_hsl(var(--accent-customers)/0.35)]" x-on:click="copy()">
                    <span x-show="!copied">Copy</span>
                    <span x-show="copied" x-cloak>Copied</span>
                </button>
            </div>
        </div>
    </div>
</section>
