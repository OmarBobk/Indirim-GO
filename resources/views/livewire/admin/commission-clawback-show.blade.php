<div class="space-y-6" data-test="admin-clawback-detail">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <flux:heading size="lg">
                <span class="font-mono" dir="ltr">{{ $detail['public_ref'] }}</span>
            </flux:heading>
            <flux:text class="mt-1">
                {{ $detail['status_label'] }}
                @if ($detail['is_action_required'])
                    · {{ __('messages.clawback_badge_action_required') }}
                @endif
                @if ($detail['is_disputed'])
                    · {{ __('messages.clawback_badge_disputed') }}
                @endif
            </flux:text>
        </div>
        <div class="flex flex-wrap gap-2">
            <flux:button variant="ghost" :href="$detail['inbox_href']" wire:navigate>
                {{ __('messages.clawback_return_inbox') }}
            </flux:button>
            @if ($detail['can_retry'])
                <flux:button
                    variant="primary"
                    wire:click="retry"
                    wire:confirm="{{ __('messages.clawback_retry_confirm') }}"
                    data-test="admin-clawback-detail-retry"
                >
                    {{ __('messages.clawback_retry_action') }}
                </flux:button>
            @elseif ($detail['retry_denied'])
                <flux:text class="text-sm text-zinc-500">{{ $detail['retry_denied'] }}</flux:text>
            @endif
            @if ($detail['can_waive'])
                <flux:button
                    variant="filled"
                    wire:click="openWaiverForm"
                    data-test="admin-clawback-detail-waive"
                >
                    {{ __('messages.clawback_waiver_action') }}
                </flux:button>
            @elseif ($detail['waiver_denied'])
                <flux:text class="text-sm text-zinc-500">{{ $detail['waiver_denied'] }}</flux:text>
            @endif
            @if ($detail['can_open_dispute'])
                <flux:button
                    variant="filled"
                    wire:click="openDisputeForm"
                    data-test="admin-clawback-detail-dispute-open"
                >
                    {{ __('messages.clawback_dispute_open_action') }}
                </flux:button>
            @elseif ($detail['can_resolve_dispute'])
                <flux:button
                    variant="filled"
                    wire:click="openDisputeResolveForm"
                    data-test="admin-clawback-detail-dispute-resolve"
                >
                    {{ __('messages.clawback_dispute_resolve_action') }}
                </flux:button>
            @elseif ($detail['dispute_denied'])
                <flux:text class="text-sm text-zinc-500">{{ $detail['dispute_denied'] }}</flux:text>
            @endif
            @if ($detail['can_correct'])
                <flux:button
                    variant="filled"
                    wire:click="openCorrectionForm"
                    data-test="admin-clawback-detail-correct"
                >
                    {{ __('messages.clawback_correction_action') }}
                </flux:button>
            @elseif ($detail['correction_denied'])
                <flux:text class="text-sm text-zinc-500">{{ $detail['correction_denied'] }}</flux:text>
            @endif
        </div>
    </div>

    @if ($showWaiverForm)
        <section class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700" aria-labelledby="clawback-waiver-heading" data-test="admin-clawback-waiver-form">
            <h2 id="clawback-waiver-heading" class="text-sm font-semibold">{{ __('messages.clawback_waiver_action') }}</h2>
            <flux:text class="mt-1 text-sm">{{ __('messages.clawback_waiver_confirm_intro') }}</flux:text>
            <div class="mt-3 grid gap-3 sm:grid-cols-2">
                <flux:select wire:model="waiverReason" :label="__('messages.clawback_waiver_reason')">
                    <flux:select.option value="">{{ __('messages.clawback_waiver_reason_placeholder') }}</flux:select.option>
                    @foreach ($detail['waiver_reason_options'] as $option)
                        <flux:select.option value="{{ $option['value'] }}">{{ $option['label'] }}</flux:select.option>
                    @endforeach
                </flux:select>
                @if ($detail['requires_waiver_amount'])
                    <flux:input
                        wire:model="waiverAmount"
                        :label="__('messages.clawback_waiver_amount')"
                        :description="__('messages.clawback_waiver_max', ['amount' => $detail['maximum_waivable']])"
                        dir="ltr"
                        inputmode="decimal"
                    />
                @else
                    <div>
                        <flux:text class="text-sm font-medium">{{ __('messages.clawback_waiver_full_only') }}</flux:text>
                        <p class="mt-1 tabular-nums text-sm" dir="ltr">{{ $detail['maximum_waivable'] }} {{ $detail['currency'] }}</p>
                    </div>
                @endif
                <div class="sm:col-span-2">
                    <flux:textarea wire:model="waiverNote" :label="__('messages.clawback_waiver_admin_note')" rows="3" />
                </div>
            </div>
            <div class="mt-4 flex flex-wrap gap-2">
                <flux:button
                    variant="primary"
                    wire:click="waive"
                    wire:confirm="{{ __('messages.clawback_waiver_confirm') }}"
                    data-test="admin-clawback-waiver-submit"
                >
                    {{ __('messages.clawback_waiver_submit') }}
                </flux:button>
                <flux:button variant="ghost" wire:click="$set('showWaiverForm', false)">
                    {{ __('messages.cancel') }}
                </flux:button>
            </div>
        </section>
    @endif

    @if ($showDisputeOpenForm)
        <section class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700" aria-labelledby="clawback-dispute-open-heading" data-test="admin-clawback-dispute-open-form">
            <h2 id="clawback-dispute-open-heading" class="text-sm font-semibold">{{ __('messages.clawback_dispute_open_action') }}</h2>
            <flux:text class="mt-1 text-sm">{{ __('messages.clawback_dispute_open_intro') }}</flux:text>
            <div class="mt-3 grid gap-3 sm:grid-cols-2">
                <flux:select wire:model="disputeReason" :label="__('messages.clawback_dispute_reason')">
                    <flux:select.option value="">{{ __('messages.clawback_dispute_reason_placeholder') }}</flux:select.option>
                    @foreach ($detail['dispute_reason_options'] as $option)
                        <flux:select.option value="{{ $option['value'] }}">{{ $option['label'] }}</flux:select.option>
                    @endforeach
                </flux:select>
                <div class="sm:col-span-2">
                    <flux:textarea wire:model="disputeNote" :label="__('messages.clawback_dispute_admin_note')" rows="3" />
                </div>
            </div>
            <div class="mt-4 flex flex-wrap gap-2">
                <flux:button variant="primary" wire:click="openDispute" wire:confirm="{{ __('messages.clawback_dispute_open_confirm') }}" data-test="admin-clawback-dispute-open-submit">
                    {{ __('messages.clawback_dispute_open_submit') }}
                </flux:button>
                <flux:button variant="ghost" wire:click="$set('showDisputeOpenForm', false)">{{ __('messages.cancel') }}</flux:button>
            </div>
        </section>
    @endif

    @if ($showDisputeResolveForm)
        <section class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700" aria-labelledby="clawback-dispute-resolve-heading" data-test="admin-clawback-dispute-resolve-form">
            <h2 id="clawback-dispute-resolve-heading" class="text-sm font-semibold">{{ __('messages.clawback_dispute_resolve_action') }}</h2>
            @if ($detail['active_dispute_ref'])
                <flux:text class="mt-1 text-sm font-mono" dir="ltr">{{ $detail['active_dispute_ref'] }} — {{ $detail['active_dispute_reason'] }}</flux:text>
            @endif
            <div class="mt-3 grid gap-3 sm:grid-cols-2">
                <flux:select wire:model.live="disputeResolution" :label="__('messages.clawback_dispute_resolution')">
                    <flux:select.option value="">{{ __('messages.clawback_dispute_resolution_placeholder') }}</flux:select.option>
                    @foreach ($detail['resolution_options'] as $option)
                        <flux:select.option value="{{ $option['value'] }}">{{ $option['label'] }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:input wire:model="disputeResolutionSummary" :label="__('messages.clawback_dispute_safe_summary')" />
                @if (in_array($disputeResolution, ['accepted_as_waiver', 'accepted_as_correction'], true))
                    <flux:select wire:model="disputeFinancialReason" :label="__('messages.clawback_dispute_financial_reason')">
                        <flux:select.option value="">{{ __('messages.clawback_dispute_financial_reason_placeholder') }}</flux:select.option>
                        @if ($disputeResolution === 'accepted_as_waiver')
                            @foreach ($detail['waiver_reason_options'] as $option)
                                <flux:select.option value="{{ $option['value'] }}">{{ $option['label'] }}</flux:select.option>
                            @endforeach
                        @else
                            @foreach ($detail['correction_reason_options'] as $option)
                                <flux:select.option value="{{ $option['value'] }}">{{ $option['label'] }}</flux:select.option>
                            @endforeach
                        @endif
                    </flux:select>
                    <flux:input wire:model="disputeFinancialAmount" :label="__('messages.clawback_dispute_financial_amount')" dir="ltr" inputmode="decimal" />
                @endif
                <div class="sm:col-span-2">
                    <flux:textarea wire:model="disputeResolveNote" :label="__('messages.clawback_dispute_admin_note')" rows="3" />
                </div>
            </div>
            <div class="mt-4 flex flex-wrap gap-2">
                <flux:button variant="primary" wire:click="resolveDispute" wire:confirm="{{ __('messages.clawback_dispute_resolve_confirm') }}" data-test="admin-clawback-dispute-resolve-submit">
                    {{ __('messages.clawback_dispute_resolve_submit') }}
                </flux:button>
                <flux:button variant="ghost" wire:click="$set('showDisputeResolveForm', false)">{{ __('messages.cancel') }}</flux:button>
            </div>
        </section>
    @endif

    @if ($showCorrectionForm)
        <section class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700" aria-labelledby="clawback-correction-heading" data-test="admin-clawback-correction-form">
            <h2 id="clawback-correction-heading" class="text-sm font-semibold">{{ __('messages.clawback_correction_action') }}</h2>
            <flux:text class="mt-1 text-sm">{{ __('messages.clawback_correction_confirm_intro') }}</flux:text>
            <div class="mt-3 grid gap-3 sm:grid-cols-2">
                <flux:select wire:model="correctionReason" :label="__('messages.clawback_correction_reason')">
                    <flux:select.option value="">{{ __('messages.clawback_correction_reason_placeholder') }}</flux:select.option>
                    @foreach ($detail['correction_reason_options'] as $option)
                        <flux:select.option value="{{ $option['value'] }}">{{ $option['label'] }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:input
                    wire:model="correctionAmount"
                    :label="__('messages.clawback_correction_amount')"
                    :description="__('messages.clawback_correction_max', ['amount' => $detail['maximum_correctable']])"
                    dir="ltr"
                    inputmode="decimal"
                />
                <div class="sm:col-span-2">
                    <flux:textarea wire:model="correctionNote" :label="__('messages.clawback_correction_admin_note')" rows="3" />
                </div>
            </div>
            <div class="mt-4 flex flex-wrap gap-2">
                <flux:button variant="primary" wire:click="correct" wire:confirm="{{ __('messages.clawback_correction_confirm') }}" data-test="admin-clawback-correction-submit">
                    {{ __('messages.clawback_correction_submit') }}
                </flux:button>
                <flux:button variant="ghost" wire:click="$set('showCorrectionForm', false)">{{ __('messages.cancel') }}</flux:button>
            </div>
        </section>
    @endif

    @if ($detail['failure_category'] !== 'none')
        <flux:callout variant="warning" data-test="admin-clawback-failure">
            <flux:heading size="sm">{{ $detail['failure_title'] }}</flux:heading>
            <flux:text>{{ $detail['failure_explanation'] }}</flux:text>
        </flux:callout>
    @endif

    <div class="grid gap-4 lg:grid-cols-2">
        <section class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700" aria-labelledby="clawback-financial-heading">
            <h2 id="clawback-financial-heading" class="text-sm font-semibold">{{ __('messages.clawback_section_financial') }}</h2>
            <dl class="mt-3 space-y-2 text-sm">
                <div class="flex justify-between gap-3"><dt>{{ __('messages.clawback_col_amount') }}</dt><dd class="tabular-nums" dir="ltr">{{ $detail['amount'] }} {{ $detail['currency'] }}</dd></div>
                <div class="flex justify-between gap-3"><dt>{{ __('messages.clawback_wallet_balance') }}</dt><dd class="tabular-nums" dir="ltr">{{ $detail['wallet_balance'] }}</dd></div>
                <div class="flex justify-between gap-3"><dt>{{ __('messages.clawback_outstanding_debt') }}</dt><dd class="tabular-nums" dir="ltr">{{ $detail['outstanding_debt'] }}</dd></div>
                <div class="flex justify-between gap-3"><dt>{{ __('messages.clawback_commission_amount') }}</dt><dd class="tabular-nums" dir="ltr">{{ $detail['commission_amount'] }}</dd></div>
                <div class="flex justify-between gap-3"><dt>{{ __('messages.clawback_policy_version') }}</dt><dd dir="ltr">{{ $detail['policy_version'] }}</dd></div>
                <div class="flex justify-between gap-3"><dt>{{ __('messages.clawback_original_credit') }}</dt><dd class="font-mono" dir="ltr">{{ $detail['original_credit_public_ref'] ?? '—' }}</dd></div>
                <div class="flex justify-between gap-3"><dt>{{ __('messages.clawback_reversal') }}</dt><dd class="font-mono" dir="ltr">{{ $detail['reversal_public_ref'] ?? '—' }}</dd></div>
                <div class="flex justify-between gap-3"><dt>{{ __('messages.clawback_refund') }}</dt><dd class="font-mono" dir="ltr">{{ $detail['refund_public_ref'] ?? '—' }}</dd></div>
                <div class="flex justify-between gap-3"><dt>{{ __('messages.clawback_net_collected') }}</dt><dd class="tabular-nums" dir="ltr">{{ $detail['net_collected'] }}</dd></div>
                <div class="flex justify-between gap-3"><dt>{{ __('messages.clawback_remaining_waivable') }}</dt><dd class="tabular-nums" dir="ltr">{{ $detail['remaining_waivable'] }}</dd></div>
                <div class="flex justify-between gap-3"><dt>{{ __('messages.clawback_remaining_correctable') }}</dt><dd class="tabular-nums" dir="ltr">{{ $detail['remaining_correctable'] }}</dd></div>
            </dl>
        </section>

        <section class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700" aria-labelledby="clawback-source-heading">
            <h2 id="clawback-source-heading" class="text-sm font-semibold">{{ __('messages.clawback_section_source') }}</h2>
            <dl class="mt-3 space-y-2 text-sm">
                <div class="flex justify-between gap-3"><dt>{{ __('messages.salesperson') }}</dt><dd>{{ $detail['salesperson_name'] ?? '—' }}</dd></div>
                <div class="flex justify-between gap-3"><dt>{{ __('messages.email') }}</dt><dd dir="ltr">{{ $detail['salesperson_email'] ?? '—' }}</dd></div>
                <div class="flex justify-between gap-3">
                    <dt>{{ __('messages.order_number') }}</dt>
                    <dd class="font-mono" dir="ltr">
                        @if ($detail['order_href'])
                            <a href="{{ $detail['order_href'] }}" wire:navigate class="text-(--color-accent) hover:underline">{{ $detail['order_number'] }}</a>
                        @else
                            {{ $detail['order_number'] ?? '—' }}
                        @endif
                    </dd>
                </div>
                <div class="flex justify-between gap-3"><dt>{{ __('messages.clawback_commission_status') }}</dt><dd>{{ $detail['commission_status'] ?: '—' }}</dd></div>
                <div class="flex justify-between gap-3"><dt>{{ __('messages.clawback_fulfillment_status') }}</dt><dd>{{ $detail['fulfillment_status'] ?? '—' }}</dd></div>
                <div class="flex justify-between gap-3"><dt>{{ __('messages.clawback_refund_status') }}</dt><dd>{{ $detail['refund_status'] ?? '—' }}</dd></div>
            </dl>
        </section>
    </div>

    <section class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700" aria-labelledby="clawback-integrity-heading">
        <h2 id="clawback-integrity-heading" class="text-sm font-semibold">{{ __('messages.clawback_section_integrity') }}</h2>
        <ul class="mt-3 space-y-2 text-sm">
            @foreach ($detail['integrity_checks'] as $check)
                <li class="flex items-center gap-2" wire:key="check-{{ $check['key'] }}">
                    <span class="{{ $check['ok'] ? 'text-emerald-600' : 'text-rose-600' }}" aria-hidden="true">{{ $check['ok'] ? '✓' : '✕' }}</span>
                    <span>{{ $check['label'] }}</span>
                    <span class="sr-only">{{ $check['ok'] ? __('messages.clawback_check_pass') : __('messages.clawback_check_fail') }}</span>
                </li>
            @endforeach
        </ul>
    </section>

    <section class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700" aria-labelledby="clawback-timeline-heading">
        <h2 id="clawback-timeline-heading" class="text-sm font-semibold">{{ __('messages.clawback_section_timeline') }}</h2>
        <ol class="mt-3 space-y-2 text-sm">
            @forelse ($detail['timeline'] as $event)
                <li wire:key="timeline-{{ $event['at_display'] }}-{{ $event['label'] }}">
                    <span class="tabular-nums text-zinc-500" dir="ltr">{{ $event['at_display'] }}</span>
                    — {{ $event['label'] }}
                    @if ($event['detail'])
                        <span class="font-mono text-xs" dir="ltr">({{ $event['detail'] }})</span>
                    @endif
                </li>
            @empty
                <li>{{ __('messages.clawback_timeline_empty') }}</li>
            @endforelse
        </ol>
    </section>

    <section class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700" aria-labelledby="clawback-waivers-heading">
        <h2 id="clawback-waivers-heading" class="text-sm font-semibold">{{ __('messages.clawback_section_waivers') }}</h2>
        <ul class="mt-3 space-y-2 text-sm">
            @forelse ($detail['waiver_decisions'] as $waiver)
                <li wire:key="waiver-{{ $waiver['public_ref'] }}">
                    <span class="font-mono" dir="ltr">{{ $waiver['public_ref'] }}</span>
                    — {{ $waiver['reason_label'] }}
                    @if ($waiver['amount'])
                        <span class="tabular-nums" dir="ltr">({{ $waiver['amount'] }})</span>
                    @endif
                    @if ($waiver['related_wtx'])
                        <span class="font-mono text-xs" dir="ltr">{{ $waiver['related_wtx'] }}</span>
                    @endif
                    <span class="text-zinc-500">{{ $waiver['decided_at_display'] }}</span>
                </li>
            @empty
                <li>{{ __('messages.clawback_waivers_empty') }}</li>
            @endforelse
        </ul>
    </section>

    <section class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700" aria-labelledby="clawback-disputes-heading">
        <h2 id="clawback-disputes-heading" class="text-sm font-semibold">{{ __('messages.clawback_section_disputes') }}</h2>
        <ul class="mt-3 space-y-2 text-sm">
            @forelse ($detail['dispute_decisions'] as $dispute)
                <li wire:key="dispute-{{ $dispute['public_ref'] }}">
                    <span class="font-mono" dir="ltr">{{ $dispute['public_ref'] }}</span>
                    — {{ $dispute['type_label'] }}: {{ $dispute['reason_label'] }}
                    @if ($dispute['safe_summary'])
                        <span class="text-zinc-500">({{ $dispute['safe_summary'] }})</span>
                    @endif
                    <span class="text-zinc-500">{{ $dispute['decided_at_display'] }}</span>
                </li>
            @empty
                <li>{{ __('messages.clawback_disputes_empty') }}</li>
            @endforelse
        </ul>
    </section>

    <section class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700" aria-labelledby="clawback-corrections-heading">
        <h2 id="clawback-corrections-heading" class="text-sm font-semibold">{{ __('messages.clawback_section_corrections') }}</h2>
        <ul class="mt-3 space-y-2 text-sm">
            @forelse ($detail['correction_decisions'] as $correction)
                <li wire:key="correction-{{ $correction['public_ref'] }}">
                    <span class="font-mono" dir="ltr">{{ $correction['public_ref'] }}</span>
                    — {{ $correction['reason_label'] }}
                    @if ($correction['amount'])
                        <span class="tabular-nums" dir="ltr">({{ $correction['amount'] }})</span>
                    @endif
                    @if ($correction['related_wtx'])
                        <span class="font-mono text-xs" dir="ltr">{{ $correction['related_wtx'] }}</span>
                    @endif
                    <span class="text-zinc-500">{{ $correction['decided_at_display'] }}</span>
                </li>
            @empty
                <li>{{ __('messages.clawback_corrections_empty') }}</li>
            @endforelse
        </ul>
    </section>
</div>
