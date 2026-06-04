<div
    class="flex h-full w-full flex-1 flex-col gap-4"
    x-data="{
        panelOpen: false,
        focusScreenshots: false,
        lightbox: { open: false, items: [], index: 0, src: '', alt: '' },
        openPanel(focus = false) {
            this.focusScreenshots = focus;
            this.panelOpen = true;
            this.$nextTick(() => {
                if (focus) {
                    this.$refs.screenshotsSection?.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            });
        },
        closePanel() {
            this.panelOpen = false;
            this.focusScreenshots = false;
        },
        openLightbox(items, index = 0) {
            if (!items.length) return;
            const i = Math.max(0, Math.min(index, items.length - 1));
            this.lightbox = {
                open: true,
                items,
                index: i,
                src: items[i].src,
                alt: items[i].alt,
            };
        },
        closeLightbox() {
            this.lightbox.open = false;
        },
        nextImage() {
            if (!this.lightbox.items.length) return;
            const next = (this.lightbox.index + 1) % this.lightbox.items.length;
            this.lightbox.index = next;
            this.lightbox.src = this.lightbox.items[next].src;
            this.lightbox.alt = this.lightbox.items[next].alt;
        },
        prevImage() {
            if (!this.lightbox.items.length) return;
            const prev = (this.lightbox.index - 1 + this.lightbox.items.length) % this.lightbox.items.length;
            this.lightbox.index = prev;
            this.lightbox.src = this.lightbox.items[prev].src;
            this.lightbox.alt = this.lightbox.items[prev].alt;
        },
        expandedGroups: {},
        toggleGroup(fulfillmentId) {
            this.expandedGroups[fulfillmentId] = !this.expandedGroups[fulfillmentId];
        },
        isGroupExpanded(fulfillmentId) {
            return !!this.expandedGroups[fulfillmentId];
        },
    }"
    x-on:open-panel.window="openPanel($event.detail.focusScreenshots ?? false)"
    x-on:close-panel.window="closePanel()"
    x-on:keydown.escape.window="lightbox.open ? closeLightbox() : (panelOpen ? (closePanel(), $wire.closePanel()) : null)"
    x-on:keydown.arrow-right.window="lightbox.open && nextImage()"
    x-on:keydown.arrow-left.window="lightbox.open && prevImage()"
>
    {{-- Zone 1: KPI status bar --}}
    <section class="sticky top-0 z-10 rounded-2xl border border-zinc-200 bg-white/95 p-4 shadow-sm backdrop-blur-md dark:border-zinc-700 dark:bg-zinc-900/95">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div class="space-y-1">
                <flux:heading size="lg" class="text-zinc-900 dark:text-zinc-100">
                    {{ __('messages.automation_admin') }}
                </flux:heading>
                <flux:text class="text-sm text-zinc-600 dark:text-zinc-400">
                    {{ __('messages.automation_admin_intro') }}
                </flux:text>
                @php($workerBuild = $this->stats['worker_build'])
                <p @class([
                    'text-xs font-medium',
                    'text-emerald-600 dark:text-emerald-400' => $workerBuild['state'] === 'ok',
                    'text-amber-600 dark:text-amber-400' => $workerBuild['state'] === 'outdated',
                    'text-red-600 dark:text-red-400' => in_array($workerBuild['state'], ['unreachable', 'unknown'], true),
                ])>
                    {{ $workerBuild['label'] }}
                </p>
            </div>
            <span class="inline-flex flex-col items-end gap-0.5 self-start">
                <span class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-1 text-[11px] font-medium text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300">
                    <span class="size-1.5 rounded-full bg-emerald-500"></span>
                    {{ __('messages.automation_live_badge') }}
                </span>
                <span class="text-[10px] text-zinc-400 dark:text-zinc-500">{{ __('messages.automation_live_reverb') }}</span>
            </span>
        </div>

        @php($health = $this->stats['worker_health'])
        <div class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <div class="flex items-center gap-3 rounded-xl border border-blue-200 bg-blue-50/80 p-3 ring-1 ring-blue-100 dark:border-blue-900/60 dark:bg-blue-950/40 dark:ring-blue-900/40">
                <div class="flex size-10 items-center justify-center rounded-lg bg-blue-500/15 text-blue-600 dark:text-blue-300">
                    <flux:icon icon="play" class="size-5" />
                </div>
                <div>
                    <div class="text-2xl font-bold tabular-nums text-blue-700 dark:text-blue-200">{{ $this->stats['running_count'] }}</div>
                    <div class="text-xs font-medium text-blue-800/80 dark:text-blue-300/80">{{ __('messages.automation_kpi_running') }}</div>
                </div>
            </div>

            <div class="flex items-center gap-3 rounded-xl border border-amber-200 bg-amber-50/80 p-3 ring-1 ring-amber-100 dark:border-amber-900/60 dark:bg-amber-950/40 dark:ring-amber-900/40">
                <div class="flex size-10 items-center justify-center rounded-lg bg-amber-500/15 text-amber-600 dark:text-amber-300">
                    <flux:icon icon="exclamation-triangle" class="size-5" />
                </div>
                <div>
                    <div class="text-2xl font-bold tabular-nums text-amber-700 dark:text-amber-200">{{ $this->stats['needs_review_count'] }}</div>
                    <div class="text-xs font-medium text-amber-800/80 dark:text-amber-300/80">{{ __('messages.automation_stat_needs_review') }}</div>
                </div>
            </div>

            <div class="flex items-center gap-3 rounded-xl border border-red-200 bg-red-50/80 p-3 ring-1 ring-red-100 dark:border-red-900/60 dark:bg-red-950/40 dark:ring-red-900/40">
                <div class="flex size-10 items-center justify-center rounded-lg bg-red-500/15 text-red-600 dark:text-red-300">
                    <flux:icon icon="x-circle" class="size-5" />
                </div>
                <div>
                    <div class="text-2xl font-bold tabular-nums text-red-700 dark:text-red-200">{{ $this->stats['failed_today_count'] }}</div>
                    <div class="text-xs font-medium text-red-800/80 dark:text-red-300/80">{{ __('messages.automation_kpi_failed_today') }}</div>
                </div>
            </div>

            <div @class(['flex items-center gap-3 rounded-xl border p-3 ring-1', $this->healthCardClass($health['state'])])>
                <div class="flex size-10 items-center justify-center rounded-lg bg-white/60 dark:bg-zinc-900/40">
                    @if ($health['state'] === 'healthy')
                        <flux:icon icon="signal" class="size-5" />
                    @elseif ($health['state'] === 'slow')
                        <flux:icon icon="clock" class="size-5" />
                    @else
                        <flux:icon icon="signal-slash" class="size-5" />
                    @endif
                </div>
                <div>
                    <div class="text-sm font-bold text-zinc-900 dark:text-zinc-100">{{ $health['label'] }}</div>
                    <div class="text-xs font-medium text-zinc-600 dark:text-zinc-400">{{ __('messages.automation_kpi_worker') }}</div>
                </div>
            </div>
        </div>
    </section>

    @if (! $automationEnabled)
        <flux:callout variant="danger" icon="exclamation-triangle">
            {{ __('messages.automation_disabled_banner') }}
        </flux:callout>
    @endif

    {{-- Zone 4: Controls --}}
    <section class="rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
            <div class="flex items-center gap-3">
                <flux:label class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">{{ __('messages.automation_kill_switch') }}</flux:label>
                <flux:switch
                    wire:click="toggleAutomation"
                    :checked="$automationEnabled"
                    wire:loading.attr="disabled"
                    wire:target="toggleAutomation"
                />
                <span @class([
                    'text-xs font-semibold uppercase tracking-wide',
                    'text-emerald-600 dark:text-emerald-400' => $automationEnabled,
                    'text-red-600 dark:text-red-400' => ! $automationEnabled,
                ])>
                    {{ $automationEnabled ? __('messages.on') : __('messages.off') }}
                </span>
            </div>

            <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
                <div class="flex flex-wrap gap-1 border-b border-zinc-200 dark:border-zinc-700">
                    @foreach (['all', 'running', 'needs_review', 'failed', 'succeeded', 'cancelled'] as $tab)
                        <button
                            type="button"
                            wire:click="$set('statusFilter', '{{ $tab }}')"
                            @class([
                                'border-b-2 px-3 py-2 text-xs font-semibold transition',
                                'border-cyan-500 text-cyan-700 dark:text-cyan-300' => $statusFilter === $tab,
                                'border-transparent text-zinc-500 hover:text-zinc-800 dark:text-zinc-400 dark:hover:text-zinc-200' => $statusFilter !== $tab,
                            ])
                        >
                            @if ($tab === 'all')
                                {{ __('messages.all') }}
                            @elseif ($tab === 'running')
                                {{ __('messages.automation_status_running') }}
                            @else
                                {{ __('messages.automation_status_'.$tab) }}
                            @endif
                        </button>
                    @endforeach
                </div>

                <flux:input
                    wire:model.live.debounce.400ms="search"
                    icon="magnifying-glass"
                    placeholder="{{ __('messages.automation_search_placeholder') }}"
                    class="min-w-[220px]"
                />
            </div>
        </div>

        <div class="mt-4 border-t border-zinc-200 pt-4 dark:border-zinc-700">
            <flux:heading size="sm" class="text-zinc-900 dark:text-zinc-100">
                {{ __('messages.automation_wasim_credentials_heading') }}
            </flux:heading>
            <flux:text class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
                {{ __('messages.automation_wasim_credentials_hint') }}
            </flux:text>

            <form wire:submit="saveWasimCredentials" class="mt-4 grid max-w-xl gap-4">
                <flux:field>
                    <flux:label>{{ __('messages.automation_wasim_username') }}</flux:label>
                    <flux:input wire:model="wasimUsername" autocomplete="off" />
                    <flux:error name="wasimUsername" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('messages.automation_wasim_password') }}</flux:label>
                    <flux:input
                        type="password"
                        wire:model="wasimPassword"
                        placeholder="{{ __('messages.automation_wasim_password_placeholder') }}"
                        autocomplete="new-password"
                    />
                    <flux:error name="wasimPassword" />
                    <flux:description>
                        @if ($wasimPasswordConfigured)
                            {{ __('messages.automation_wasim_password_configured') }}
                        @elseif ($wasimCredentialsFromEnv)
                            {{ __('messages.automation_wasim_password_from_env') }}
                        @else
                            {{ __('messages.automation_wasim_password_missing') }}
                        @endif
                    </flux:description>
                </flux:field>

                <div>
                    <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="saveWasimCredentials">
                        {{ __('messages.save') }}
                    </flux:button>
                </div>
            </form>
        </div>
    </section>

    {{-- Zone 2: Runs table --}}
    <section class="relative overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
        <div
            wire:loading.delay
            wire:target="toggleAutomation, saveWasimCredentials, retryRun, cancelRun, markReviewSucceeded, markReviewFailed"
            class="absolute inset-0 z-10 bg-white/60 dark:bg-zinc-900/60"
        >
            <div class="p-6 space-y-2">
                @for ($i = 0; $i < 6; $i++)
                    <flux:skeleton class="h-12 w-full" />
                @endfor
            </div>
        </div>

        <div
            class="overflow-x-auto"
            wire:loading.remove.delay
            wire:target="toggleAutomation, saveWasimCredentials, retryRun, cancelRun, markReviewSucceeded, markReviewFailed"
        >
            @if ($this->runs->count() === 0)
                <div class="flex flex-col items-center gap-3 p-12 text-center">
                    <div class="flex size-14 items-center justify-center rounded-full bg-zinc-100 text-zinc-500 dark:bg-zinc-800">
                        <flux:icon icon="cpu-chip" class="size-6" />
                    </div>
                    <flux:heading size="sm">{{ __('messages.automation_no_runs') }}</flux:heading>
                </div>
            @else
                <table class="min-w-full divide-y divide-zinc-100 text-sm dark:divide-zinc-800">
                    <thead class="bg-zinc-50 text-xs uppercase tracking-wide text-zinc-500 dark:bg-zinc-800/60 dark:text-zinc-400">
                        <tr>
                            <th class="px-4 py-3 text-start font-semibold">{{ __('messages.id') }}</th>
                            <th class="px-4 py-3 text-start font-semibold">{{ __('messages.status') }}</th>
                            <th class="px-4 py-3 text-start font-semibold">{{ __('messages.automation_run_id') }}</th>
                            <th class="px-4 py-3 text-start font-semibold">{{ __('messages.order') }}</th>
                            <th class="px-4 py-3 text-start font-semibold">{{ __('messages.automation_supplier') }}</th>
                            <th class="px-4 py-3 text-start font-semibold">{{ __('messages.started') }}</th>
                            <th class="px-4 py-3 text-start font-semibold">{{ __('messages.duration') }}</th>
                            <th class="px-4 py-3 text-start font-semibold">{{ __('messages.automation_run_details') }}</th>
                            <th class="px-4 py-3 text-end font-semibold">{{ __('messages.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        @foreach ($this->runGroups as $group)
                            @php($primary = $group->primary)
                            @if ($group->count > 1)
                                <tr
                                    wire:key="automation-group-{{ $group->fulfillment_id }}"
                                    class="bg-zinc-50/80 dark:bg-zinc-800/40"
                                >
                                    <td colspan="9" class="px-4 py-2">
                                        <button
                                            type="button"
                                            class="inline-flex w-full items-center gap-2 text-start text-xs font-medium text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-200"
                                            x-on:click.stop="toggleGroup({{ $group->fulfillment_id }})"
                                        >
                                            <flux:icon
                                                icon="chevron-down"
                                                class="size-4 shrink-0 transition-transform duration-150"
                                                x-bind:class="isGroupExpanded({{ $group->fulfillment_id }}) ? 'rotate-180' : ''"
                                            />
                                            <span>
                                                {{ $primary->fulfillment?->order?->order_number ?? __('messages.automation_fulfillment_group') }}
                                                ·
                                                {{ trans_choice('messages.automation_attempts_count', $group->count, ['count' => $group->count]) }}
                                            </span>
                                            @if (! $this->isGlobalLatestRun($primary, $group->global_latest_uuid))
                                                <span class="rounded-full bg-zinc-200 px-2 py-0.5 text-[10px] font-normal text-zinc-600 dark:bg-zinc-700 dark:text-zinc-300">
                                                    {{ __('messages.automation_older_attempts_on_page') }}
                                                </span>
                                            @endif
                                        </button>
                                    </td>
                                </tr>
                            @endif

                            @include('livewire.admin.partials.automation-run-row', [
                                'run' => $primary,
                                'globalLatestUuid' => $group->global_latest_uuid,
                                'isChild' => false,
                                'showAttemptLabel' => $group->count > 1,
                            ])

                            @foreach ($group->others as $run)
                                @include('livewire.admin.partials.automation-run-row', [
                                    'run' => $run,
                                    'globalLatestUuid' => $group->global_latest_uuid,
                                    'isChild' => true,
                                    'showAttemptLabel' => true,
                                    'expandGroupId' => $group->fulfillment_id,
                                ])
                            @endforeach
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>

        @if ($this->runs->hasPages())
            <div class="border-t border-zinc-100 px-4 py-3 dark:border-zinc-800">
                {{ $this->runs->links() }}
            </div>
        @endif
    </section>

    {{-- Zone 3: Slide-over panel --}}
    <div
        x-show="panelOpen"
        x-cloak
        class="fixed inset-0 z-40 flex justify-end"
        aria-modal="true"
        role="dialog"
    >
        <div
            class="absolute inset-0 bg-black/20 transition-opacity duration-150 ease-out"
            x-on:click="closePanel(); $wire.closePanel()"
        ></div>

        <div
            class="relative flex h-full w-full max-w-[480px] flex-col border-s border-zinc-200 bg-white shadow-xl transition-transform duration-150 ease-out dark:border-zinc-700 dark:bg-zinc-900"
            x-show="panelOpen"
            x-transition:enter="translate-x-full"
            x-transition:enter-end="translate-x-0"
            x-transition:leave="translate-x-0"
            x-transition:leave-end="translate-x-full"
        >
            @if ($this->selectedRun)
                @php($panelRun = $this->selectedRun)
                @php($artifactItems = $this->artifactItemsForRun($panelRun))
                <div class="flex items-start justify-between gap-3 border-b border-zinc-100 p-4 dark:border-zinc-800">
                    <div class="min-w-0 space-y-1">
                        <div class="font-mono text-xs text-zinc-500 dark:text-zinc-400">{{ Str::limit($panelRun->uuid, 20, '…') }}</div>
                        <span class="inline-flex rounded-full border border-zinc-200 bg-zinc-50 px-2 py-0.5 text-xs font-semibold dark:border-zinc-700 dark:bg-zinc-800">
                            {{ __('messages.automation_status_'.$panelRun->status->value) }}
                        </span>
                    </div>
                    <button
                        type="button"
                        class="rounded-lg p-1 text-zinc-500 hover:bg-zinc-100 dark:hover:bg-zinc-800"
                        x-on:click="closePanel(); $wire.closePanel()"
                        aria-label="{{ __('messages.close') }}"
                    >
                        <flux:icon icon="x-mark" class="size-5" />
                    </button>
                </div>

                <div class="flex-1 overflow-y-auto p-4 space-y-5">
                    <dl class="grid grid-cols-2 gap-3 text-sm">
                        <div>
                            <dt class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('messages.id') }}</dt>
                            <dd class="font-mono tabular-nums text-zinc-900 dark:text-zinc-100">{{ $panelRun->id }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('messages.fulfillment') }}</dt>
                            <dd class="font-mono text-zinc-900 dark:text-zinc-100">#{{ $panelRun->fulfillment_id }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('messages.order') }}</dt>
                            <dd class="text-zinc-900 dark:text-zinc-100">{{ $panelRun->fulfillment?->order?->order_number ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('messages.automation_supplier') }}</dt>
                            <dd>{{ $this->supplierLabel($panelRun) }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('messages.started') }}</dt>
                            <dd>{{ $this->runStartedAt($panelRun)?->format('Y-m-d H:i:s') ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('messages.duration') }}</dt>
                            <dd class="font-mono">{{ $this->runDurationLabel($panelRun) }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('messages.ended') }}</dt>
                            <dd>{{ $panelRun->finished_at?->format('Y-m-d H:i:s') ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('messages.automation_dispatched_at') }}</dt>
                            <dd>{{ $panelRun->dispatched_at?->format('Y-m-d H:i:s') ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('messages.automation_webhook_at') }}</dt>
                            <dd>{{ $panelRun->callback_received_at?->format('Y-m-d H:i:s') ?? '—' }}</dd>
                        </div>
                    </dl>

                    <div x-ref="screenshotsSection">
                        <div class="mb-2 flex items-center gap-2">
                            <flux:heading size="sm">{{ __('messages.automation_screenshots') }}</flux:heading>
                            <flux:badge size="sm">{{ count($artifactItems) }}</flux:badge>
                        </div>

                        @if ($artifactItems === [])
                            <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('messages.automation_no_screenshots') }}</p>
                        @else
                            <div class="grid grid-cols-3 gap-2" data-artifacts='@json($artifactItems)'>
                                @foreach ($artifactItems as $index => $artifact)
                                    <button
                                        type="button"
                                        class="group text-start"
                                        x-on:click.stop="openLightbox(JSON.parse($el.parentElement.dataset.artifacts), {{ $index }})"
                                    >
                                        <img
                                            src="{{ $artifact['src'] }}"
                                            alt="{{ $artifact['alt'] }}"
                                            loading="lazy"
                                            class="aspect-video w-full cursor-zoom-in rounded-lg border border-zinc-200 object-cover transition group-hover:ring-2 group-hover:ring-cyan-500 dark:border-zinc-700"
                                        />
                                        <span class="mt-1 block truncate text-[11px] text-zinc-500 dark:text-zinc-400">{{ $artifact['label'] }}</span>
                                    </button>
                                @endforeach
                            </div>
                        @endif
                    </div>

                    @if (in_array($panelRun->status, [\App\Enums\FulfillmentAutomationRunStatus::Failed, \App\Enums\FulfillmentAutomationRunStatus::NeedsReview], true) && $panelRun->error_message)
                        <flux:callout variant="danger" icon="exclamation-triangle">
                            <span class="font-mono text-xs">{{ $panelRun->error_code }}</span>
                            <p class="mt-1 text-sm">{{ $panelRun->error_message }}</p>
                        </flux:callout>
                    @endif

                    @php($logExcerpt = $this->formattedLogExcerpt($panelRun))
                    @if ($logExcerpt !== [])
                        <details class="rounded-lg border border-zinc-200 dark:border-zinc-700">
                            <summary class="cursor-pointer px-3 py-2 text-sm font-medium text-zinc-700 dark:text-zinc-300">{{ __('messages.automation_raw_log') }}</summary>
                            <pre class="max-h-48 overflow-auto p-3 font-mono text-[11px] text-zinc-600 dark:text-zinc-400">{{ json_encode($logExcerpt, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                        </details>
                    @endif
                </div>

                <div class="flex flex-wrap gap-2 border-t border-zinc-100 bg-zinc-50/80 p-4 dark:border-zinc-800 dark:bg-zinc-950/50">
                    @if (! $this->selectedRunIsGlobalLatest)
                        <flux:callout variant="warning" icon="information-circle" class="w-full">
                            {{ __('messages.automation_viewing_older_attempt') }}
                        </flux:callout>
                        @php($latestUuid = $this->latestRunUuidForFulfillment($panelRun->fulfillment_id))
                        @if ($latestUuid !== null && $latestUuid !== $panelRun->uuid)
                            <flux:button variant="ghost" wire:click="selectRun('{{ $latestUuid }}')">
                                {{ __('messages.automation_open_latest_attempt') }}
                            </flux:button>
                        @endif
                    @elseif ($panelRun->status === \App\Enums\FulfillmentAutomationRunStatus::NeedsReview)
                        <flux:button variant="primary" wire:click="markReviewSucceeded('{{ $panelRun->uuid }}')" wire:loading.attr="disabled">
                            {{ __('messages.automation_mark_review_succeeded') }}
                        </flux:button>
                        <flux:button variant="danger" wire:click="markReviewFailed('{{ $panelRun->uuid }}')" wire:loading.attr="disabled">
                            {{ __('messages.automation_mark_review_failed') }}
                        </flux:button>
                    @elseif ($panelRun->status === \App\Enums\FulfillmentAutomationRunStatus::Failed)
                        <flux:button variant="primary" wire:click="retryRun('{{ $panelRun->uuid }}')" wire:loading.attr="disabled">
                            {{ __('messages.retry_automation') }}
                        </flux:button>
                    @elseif ($panelRun->isActive())
                        <flux:button variant="danger" wire:click="cancelRun('{{ $panelRun->uuid }}')" wire:loading.attr="disabled">
                            {{ __('messages.automation_cancel_run') }}
                        </flux:button>
                    @endif
                </div>
            @else
                <div class="flex flex-1 items-center justify-center p-8 text-sm text-zinc-500">
                    {{ __('messages.automation_select_run') }}
                </div>
            @endif
        </div>
    </div>

    {{-- Lightbox --}}
    <div
        x-show="lightbox.open"
        x-cloak
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/90 p-4 transition-opacity duration-100"
        x-on:click.self="closeLightbox()"
    >
        <button type="button" class="absolute end-4 top-4 rounded-full bg-white/10 p-2 text-white hover:bg-white/20" x-on:click="closeLightbox()">
            <flux:icon icon="x-mark" class="size-6" />
        </button>

        <template x-if="lightbox.items.length > 1">
            <button type="button" class="absolute start-4 top-1/2 -translate-y-1/2 rounded-full bg-white/10 p-3 text-white hover:bg-white/20" x-on:click.stop="prevImage()">
                <flux:icon icon="chevron-left" class="size-6" />
            </button>
        </template>

        <img
            :src="lightbox.src"
            :alt="lightbox.alt"
            class="max-h-[90vh] max-w-[90vw] object-contain"
        />

        <template x-if="lightbox.items.length > 1">
            <button type="button" class="absolute end-4 top-1/2 -translate-y-1/2 rounded-full bg-white/10 p-3 text-white hover:bg-white/20" x-on:click.stop="nextImage()">
                <flux:icon icon="chevron-right" class="size-6" />
            </button>
        </template>

        <p class="absolute bottom-4 left-1/2 -translate-x-1/2 text-sm text-white/90" x-text="(lightbox.index + 1) + ' / ' + lightbox.items.length + ' — ' + lightbox.alt"></p>
    </div>
</div>
