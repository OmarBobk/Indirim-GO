<div class="flex h-full w-full flex-1 flex-col gap-6">
    <section class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
        <div class="space-y-1">
            <flux:heading size="lg" class="text-zinc-900 dark:text-zinc-100">
                {{ __('messages.commissions') }}
            </flux:heading>
            <flux:text class="text-sm text-zinc-600 dark:text-zinc-400">
                {{ __('messages.commissions_admin_intro') }}
            </flux:text>
        </div>

        <div class="mt-4 flex flex-col gap-3 rounded-2xl border border-zinc-100 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                <div class="w-full sm:max-w-md">
                    <flux:field>
                        <flux:label>{{ __('messages.payout_notes_optional') }}</flux:label>
                        <flux:textarea wire:model="payoutNotes" rows="2" />
                    </flux:field>
                </div>
                <flux:button variant="primary" wire:click="createPayout" wire:loading.attr="disabled" wire:target="createPayout">
                    {{ __('messages.credit_to_wallet') }}
                </flux:button>
            </div>

            <div class="overflow-hidden rounded-2xl border border-zinc-100 bg-white dark:border-zinc-800 dark:bg-zinc-900">
                <div class="overflow-x-auto">
                @if ($commissions->isEmpty())
                    <div class="flex flex-col items-center justify-center gap-2 px-6 py-16 text-center">
                        <flux:heading size="sm" class="text-zinc-900 dark:text-zinc-100">
                            {{ __('messages.no_commissions_yet') }}
                        </flux:heading>
                    </div>
                @else
                    <table class="min-w-full divide-y divide-zinc-100 text-sm dark:divide-zinc-800">
                        <thead class="bg-zinc-50 text-xs uppercase tracking-wide text-zinc-500 dark:bg-zinc-800/60 dark:text-zinc-400">
                            <tr>
                                <th class="px-5 py-3 text-start font-semibold">{{ __('messages.select') }}</th>
                                <th class="px-5 py-3 text-start font-semibold">{{ __('messages.commission_order_id') }}</th>
                                <th class="px-5 py-3 text-start font-semibold">{{ __('messages.commission_salesperson') }}</th>
                                <th class="px-5 py-3 text-start font-semibold">{{ __('messages.commission_amount') }}</th>
                                <th class="px-5 py-3 text-start font-semibold">{{ __('messages.commission_rate_percent') }}</th>
                                <th class="px-5 py-3 text-start font-semibold">{{ __('messages.commission_status') }}</th>
                                <th class="px-5 py-3 text-start font-semibold">{{ __('messages.payout_batch') }}</th>
                                <th class="px-5 py-3 text-end font-semibold">{{ __('messages.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                            @foreach ($commissions as $commission)
                                <tr class="transition hover:bg-zinc-50 dark:hover:bg-zinc-800/60" wire:key="commission-{{ $commission->id }}">
                                    <td class="px-5 py-4">
                                        @php
                                            $eligibleForPayout = $this->isEligibleForPayout($commission);
                                            $ineligibleReason = $eligibleForPayout ? null : $this->payoutIneligibilityReason($commission);
                                        @endphp
                                        <div class="space-y-1">
                                            <flux:checkbox
                                                wire:key="commission-select-{{ $commission->id }}"
                                                wire:model.live="selectedCommissionIds"
                                                :value="$commission->id"
                                                :disabled="! $eligibleForPayout"
                                            />
                                            @if (! $eligibleForPayout && $ineligibleReason !== null)
                                                <div class="max-w-40 text-[11px] text-zinc-500 dark:text-zinc-400">
                                                    {{ $ineligibleReason }}
                                                </div>
                                            @endif
                                        </div>
                                    </td>
                                    <td class="px-5 py-4">
                                        <a
                                            href="{{ route('admin.orders.show', $commission->order) }}"
                                            wire:navigate
                                            class="font-semibold text-(--color-accent) hover:underline"
                                        >
                                            #{{ $commission->order_id }}
                                        </a>
                                    </td>
                                    <td class="px-5 py-4 text-zinc-900 dark:text-zinc-100">
                                        {{ $commission->salesperson?->name ?? '—' }}
                                    </td>
                                    <td class="px-5 py-4 text-zinc-700 dark:text-zinc-200" dir="ltr">
                                        ${{ number_format((float) $commission->commission_amount, 2) }}
                                    </td>
                                    <td class="px-5 py-4">
                                        <flux:badge size="sm" color="sky" variant="subtle" dir="ltr">
                                            {{ number_format((float) $commission->commission_rate_percent, 2) }}%
                                        </flux:badge>
                                    </td>
                                    <td class="px-5 py-4">
                                        @if ($commission->status === \App\Enums\CommissionStatus::Pending)
                                            <flux:badge color="amber">{{ __('messages.commission_status_pending') }}</flux:badge>
                                        @elseif ($commission->status === \App\Enums\CommissionStatus::Failed)
                                            <flux:badge color="red">{{ __('messages.commission_status_failed') }}</flux:badge>
                                        @else
                                            <flux:badge color="green">{{ __('messages.commission_status_credited') }}</flux:badge>
                                        @endif
                                    </td>
                                    <td class="px-5 py-4 text-zinc-700 dark:text-zinc-200">
                                        {{ $commission->payout_batch_id ?? '—' }}
                                    </td>
                                    <td class="px-5 py-4 text-end">
                                        @if ($commission->status === \App\Enums\CommissionStatus::Pending)
                                            @php
                                                $canMarkPaid = $this->canMarkPaid($commission);
                                            @endphp
                                            <flux:button
                                                size="sm"
                                                variant="primary"
                                                wire:click="markPaid({{ $commission->id }})"
                                                :disabled="! $canMarkPaid"
                                            >
                                                {{ __('messages.mark_commission_credited') }}
                                            </flux:button>
                                            @if (! $canMarkPaid)
                                                <div class="mt-1 text-xs text-amber-600 dark:text-amber-400">
                                                    Complete fulfillments first.
                                                </div>
                                            @endif
                                        @else
                                            —
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
                </div>
            </div>
        </div>

        <div class="mt-4 border-t border-zinc-100 px-5 py-4 dark:border-zinc-800">
            {{ $commissions->links() }}
        </div>
    </section>
</div>
