<div class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <flux:heading size="sm" class="text-zinc-900 dark:text-zinc-100">{{ __('messages.user_pricing_rules') }}</flux:heading>
        <flux:button variant="primary" size="sm" wire:click="openCreate">{{ __('messages.add_user_pricing_rule') }}</flux:button>
    </div>
    <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('messages.user_pricing_rules_intro') }}</flux:text>

    @if ($rules->isEmpty())
        <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('messages.no_user_pricing_rules_yet') }}</flux:text>
    @else
        <div class="overflow-x-auto rounded-xl border border-zinc-200 dark:border-zinc-700">
            <table class="min-w-full divide-y divide-zinc-200 text-sm dark:divide-zinc-700">
                <thead class="bg-zinc-50 dark:bg-zinc-800/80">
                    <tr>
                        <th class="px-4 py-3 text-start font-semibold text-zinc-900 dark:text-zinc-100">{{ __('messages.pricing_rule_range') }}</th>
                        <th class="px-4 py-3 text-start font-semibold text-zinc-900 dark:text-zinc-100">{{ __('messages.pricing_rule_retail_pct') }}</th>
                        <th class="px-4 py-3 text-start font-semibold text-zinc-900 dark:text-zinc-100">{{ __('messages.pricing_rule_wholesale_pct') }}</th>
                        <th class="px-4 py-3 text-start font-semibold text-zinc-900 dark:text-zinc-100">{{ __('messages.pricing_rule_priority') }}</th>
                        <th class="px-4 py-3 text-start font-semibold text-zinc-900 dark:text-zinc-100">{{ __('messages.status') }}</th>
                        <th class="px-4 py-3 text-start font-semibold text-zinc-900 dark:text-zinc-100">{{ __('messages.note') }}</th>
                        <th class="px-4 py-3 text-end font-semibold text-zinc-900 dark:text-zinc-100">{{ __('messages.options') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                    @foreach ($rules as $rule)
                        <tr wire:key="upr-{{ $rule->id }}">
                            <td class="px-4 py-3 font-mono text-zinc-900 dark:text-zinc-100" dir="ltr">
                                {{ number_format((float) $rule->min_price, 2) }} – {{ number_format((float) $rule->max_price, 2) }}
                            </td>
                            <td class="px-4 py-3 text-zinc-900 dark:text-zinc-100" dir="ltr">{{ $rule->retail_percentage }}%</td>
                            <td class="px-4 py-3 text-zinc-900 dark:text-zinc-100" dir="ltr">{{ $rule->wholesale_percentage }}%</td>
                            <td class="px-4 py-3 text-zinc-600 dark:text-zinc-300">{{ $rule->priority }}</td>
                            <td class="px-4 py-3">
                                @if ($rule->is_active)
                                    <flux:badge color="green" size="sm" variant="subtle">{{ __('messages.active') }}</flux:badge>
                                @else
                                    <flux:badge color="zinc" size="sm" variant="subtle">{{ __('messages.inactive_status') }}</flux:badge>
                                @endif
                            </td>
                            <td class="max-w-xs truncate px-4 py-3 text-zinc-600 dark:text-zinc-300">{{ $rule->note ?? '—' }}</td>
                            <td class="px-4 py-3 text-end">
                                <div class="inline-flex flex-wrap justify-end gap-1">
                                    <flux:button variant="ghost" size="sm" wire:click="openEdit({{ $rule->id }})">{{ __('messages.edit') }}</flux:button>
                                    <flux:button
                                        variant="ghost"
                                        size="sm"
                                        class="text-red-600 hover:text-red-700 dark:text-red-400"
                                        wire:click="deleteRule({{ $rule->id }})"
                                        wire:confirm="{{ __('messages.user_pricing_rule_delete_confirm') }}"
                                    >
                                        {{ __('messages.delete') }}
                                    </flux:button>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <flux:modal wire:model.self="showModal" class="max-w-lg">
        <div class="space-y-4">
            <flux:heading size="lg">{{ $editingRuleId ? __('messages.edit_user_pricing_rule') : __('messages.add_user_pricing_rule') }}</flux:heading>

            <div class="grid gap-4 sm:grid-cols-2">
                <flux:field>
                    <flux:label>{{ __('messages.pricing_rule_min_price') }}</flux:label>
                    <flux:input type="number" step="0.01" min="0" wire:model="minPrice" dir="ltr" />
                    @error('minPrice') <flux:text color="red">{{ $message }}</flux:text> @enderror
                </flux:field>
                <flux:field>
                    <flux:label>{{ __('messages.pricing_rule_max_price') }}</flux:label>
                    <flux:input type="number" step="0.01" min="0" wire:model="maxPrice" dir="ltr" />
                    @error('maxPrice') <flux:text color="red">{{ $message }}</flux:text> @enderror
                </flux:field>
                <flux:field>
                    <flux:label>{{ __('messages.pricing_rule_retail_pct') }}</flux:label>
                    <flux:input type="number" step="0.01" wire:model="retailPercentage" dir="ltr" />
                    @error('retailPercentage') <flux:text color="red">{{ $message }}</flux:text> @enderror
                </flux:field>
                <flux:field>
                    <flux:label>{{ __('messages.pricing_rule_wholesale_pct') }}</flux:label>
                    <flux:input type="number" step="0.01" wire:model="wholesalePercentage" dir="ltr" />
                    @error('wholesalePercentage') <flux:text color="red">{{ $message }}</flux:text> @enderror
                </flux:field>
                <flux:field>
                    <flux:label>{{ __('messages.pricing_rule_priority') }}</flux:label>
                    <flux:input type="number" min="0" wire:model="priority" dir="ltr" />
                    @error('priority') <flux:text color="red">{{ $message }}</flux:text> @enderror
                </flux:field>
                <flux:field class="flex items-end">
                    <flux:switch wire:model="isActive" :label="__('messages.pricing_rule_active')" />
                </flux:field>
            </div>

            <flux:field>
                <flux:label>{{ __('messages.note') }}</flux:label>
                <flux:textarea wire:model="note" rows="2" />
                @error('note') <flux:text color="red">{{ $message }}</flux:text> @enderror
            </flux:field>

            <flux:text class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('messages.user_pricing_rules_role_hint') }}</flux:text>

            <div class="flex flex-wrap justify-end gap-2 pt-2">
                <flux:button variant="ghost" wire:click="closeModal">{{ __('messages.cancel') }}</flux:button>
                <flux:button variant="primary" wire:click="save">{{ __('messages.save') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
