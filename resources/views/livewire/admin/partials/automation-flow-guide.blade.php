<details class="group rounded-2xl border border-cyan-200/80 bg-gradient-to-br from-cyan-50/50 to-white shadow-sm open:shadow-md dark:border-cyan-900/50 dark:from-cyan-950/20 dark:to-zinc-900">
    <summary class="flex cursor-pointer list-none items-center gap-3 px-4 py-3 marker:content-none [&::-webkit-details-marker]:hidden">
        <span class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-cyan-500/15 text-cyan-700 dark:text-cyan-300">
            <flux:icon icon="information-circle" class="size-5" />
        </span>
        <span class="min-w-0 flex-1">
            <span class="block text-sm font-semibold text-zinc-900 dark:text-zinc-100">
                {{ __('messages.automation_flow_title') }}
            </span>
            <span class="block text-xs text-zinc-600 dark:text-zinc-400">
                {{ __('messages.automation_flow_summary') }}
            </span>
        </span>
        <flux:icon
            icon="chevron-down"
            class="size-5 shrink-0 text-zinc-400 transition-transform duration-150 group-open:rotate-180"
        />
    </summary>

    <div class="space-y-4 border-t border-cyan-200/60 px-4 pb-4 pt-3 dark:border-cyan-900/40">
        <div class="grid gap-4 lg:grid-cols-2">
            {{-- Phase 1 --}}
            <div class="rounded-xl border border-blue-200 bg-blue-50/60 p-4 dark:border-blue-900/50 dark:bg-blue-950/30">
                <div class="flex items-center gap-2">
                    <span class="inline-flex rounded-full bg-blue-600 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-white">
                        {{ __('messages.automation_flow_phase_1') }}
                    </span>
                    <flux:heading size="sm" class="text-blue-900 dark:text-blue-100">
                        {{ __('messages.automation_flow_phase_purchase') }}
                    </flux:heading>
                </div>
                <ol class="mt-3 flex flex-col gap-2 text-sm text-blue-950/90 dark:text-blue-100/90">
                    <li class="flex gap-2">
                        <span class="font-mono text-xs font-semibold text-blue-600 dark:text-blue-300">1</span>
                        <span>{{ __('messages.automation_flow_purchase_step_1') }}</span>
                    </li>
                    <li class="flex gap-2">
                        <span class="font-mono text-xs font-semibold text-blue-600 dark:text-blue-300">2</span>
                        <span>{{ __('messages.automation_flow_purchase_step_2') }}</span>
                    </li>
                    <li class="flex gap-2">
                        <span class="font-mono text-xs font-semibold text-blue-600 dark:text-blue-300">3</span>
                        <span>{{ __('messages.automation_flow_purchase_step_3') }}</span>
                    </li>
                </ol>
                <p class="mt-3 rounded-lg bg-white/70 px-3 py-2 font-mono text-[11px] text-blue-800 dark:bg-zinc-900/50 dark:text-blue-200">
                    {{ __('messages.automation_flow_outcome_submitted') }}
                </p>
            </div>

            {{-- Phase 2 --}}
            <div class="rounded-xl border border-violet-200 bg-violet-50/60 p-4 dark:border-violet-900/50 dark:bg-violet-950/30">
                <div class="flex items-center gap-2">
                    <span class="inline-flex rounded-full bg-violet-600 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-white">
                        {{ __('messages.automation_flow_phase_2') }}
                    </span>
                    <flux:heading size="sm" class="text-violet-900 dark:text-violet-100">
                        {{ __('messages.automation_flow_phase_reconcile') }}
                    </flux:heading>
                </div>
                <ol class="mt-3 flex flex-col gap-2 text-sm text-violet-950/90 dark:text-violet-100/90">
                    <li class="flex gap-2">
                        <span class="font-mono text-xs font-semibold text-violet-600 dark:text-violet-300">1</span>
                        <span>{{ __('messages.automation_flow_reconcile_step_1') }}</span>
                    </li>
                    <li class="flex gap-2">
                        <span class="font-mono text-xs font-semibold text-violet-600 dark:text-violet-300">2</span>
                        <span>{{ __('messages.automation_flow_reconcile_step_2') }}</span>
                    </li>
                    <li class="flex gap-2">
                        <span class="font-mono text-xs font-semibold text-violet-600 dark:text-violet-300">3</span>
                        <span>{{ __('messages.automation_flow_reconcile_step_3') }}</span>
                    </li>
                </ol>
            </div>
        </div>

        {{-- Outcomes --}}
        <div class="rounded-xl border border-zinc-200 bg-zinc-50/80 p-4 dark:border-zinc-700 dark:bg-zinc-800/50">
            <flux:heading size="sm" class="text-zinc-900 dark:text-zinc-100">
                {{ __('messages.automation_flow_outcomes_heading') }}
            </flux:heading>
            <div class="mt-3 grid gap-2 sm:grid-cols-3">
                <div class="flex flex-col gap-1 rounded-lg border border-red-200 bg-red-50/80 p-3 dark:border-red-900/50 dark:bg-red-950/30">
                    <span class="text-xs font-semibold uppercase tracking-wide text-red-700 dark:text-red-300">
                        {{ __('messages.automation_flow_tab_cancelled') }}
                    </span>
                    <span class="text-sm text-red-900/90 dark:text-red-100/90">{{ __('messages.automation_flow_outcome_cancelled') }}</span>
                </div>
                <div class="flex flex-col gap-1 rounded-lg border border-emerald-200 bg-emerald-50/80 p-3 dark:border-emerald-900/50 dark:bg-emerald-950/30">
                    <span class="text-xs font-semibold uppercase tracking-wide text-emerald-700 dark:text-emerald-300">
                        {{ __('messages.automation_flow_tab_completed') }}
                    </span>
                    <span class="text-sm text-emerald-900/90 dark:text-emerald-100/90">{{ __('messages.automation_flow_outcome_completed') }}</span>
                </div>
                <div class="flex flex-col gap-1 rounded-lg border border-amber-200 bg-amber-50/80 p-3 dark:border-amber-900/50 dark:bg-amber-950/30">
                    <span class="text-xs font-semibold uppercase tracking-wide text-amber-700 dark:text-amber-300">
                        {{ __('messages.automation_flow_tab_new') }}
                    </span>
                    <span class="text-sm text-amber-900/90 dark:text-amber-100/90">{{ __('messages.automation_flow_outcome_pending') }}</span>
                </div>
            </div>
            <p class="mt-3 text-xs text-zinc-600 dark:text-zinc-400">
                {{ __('messages.automation_flow_reconcile_note') }}
            </p>
        </div>
    </div>
</details>
