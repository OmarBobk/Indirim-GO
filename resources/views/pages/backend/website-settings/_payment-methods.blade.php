@php use Illuminate\Support\Str; @endphp
<section class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900 sm:p-6" data-test="website-payment-methods">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <flux:heading size="lg" class="text-zinc-900 dark:text-zinc-100">
                {{ __('messages.payment_methods') }}
            </flux:heading>
            <flux:text class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                {{ __('messages.payment_methods_intro') }}
            </flux:text>
        </div>
        <flux:button type="button" variant="outline" wire:click="startCreatePaymentMethod">
            {{ __('messages.payment_method_add') }}
        </flux:button>
    </div>

    @if ($this->paymentMethods->isEmpty())
        <flux:text class="mt-4 text-sm text-zinc-500 dark:text-zinc-400">
            {{ __('messages.payment_methods_empty') }}
        </flux:text>
    @else
        <div class="mt-4 overflow-x-auto rounded-xl border border-zinc-200 dark:border-zinc-700">
            <table class="min-w-full divide-y divide-zinc-200 text-sm dark:divide-zinc-700">
                <thead class="bg-zinc-50 dark:bg-zinc-800/80">
                    <tr>
                        <th class="px-4 py-3 text-start font-medium text-zinc-600 dark:text-zinc-300">{{ __('messages.name') }}</th>
                        <th class="px-4 py-3 text-start font-medium text-zinc-600 dark:text-zinc-300">{{ __('messages.payment_method_account_text') }}</th>
                        <th class="px-4 py-3 text-start font-medium text-zinc-600 dark:text-zinc-300">{{ __('messages.order') }}</th>
                        <th class="px-4 py-3 text-start font-medium text-zinc-600 dark:text-zinc-300">{{ __('messages.status') }}</th>
                        <th class="px-4 py-3 text-end font-medium text-zinc-600 dark:text-zinc-300">{{ __('messages.actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 bg-white dark:divide-zinc-800 dark:bg-zinc-900">
                    @foreach ($this->paymentMethods as $method)
                        <tr wire:key="payment-method-{{ $method->id }}">
                            <td class="px-4 py-3 font-medium text-zinc-900 dark:text-zinc-100">
                                <div class="flex items-center gap-2">
                                    @if ($method->imageUrl())
                                        <img src="{{ $method->imageUrl() }}" alt="" class="size-8 rounded-lg object-cover" width="32" height="32" />
                                    @endif
                                    {{ $method->name }}
                                </div>
                            </td>
                            <td class="max-w-xs truncate px-4 py-3 text-zinc-600 dark:text-zinc-300" title="{{ $method->account_text }}">
                                {{ Str::limit($method->account_text, 48) }}
                            </td>
                            <td class="px-4 py-3 text-zinc-600 dark:text-zinc-300">{{ $method->sort_order }}</td>
                            <td class="px-4 py-3">
                                <flux:badge color="{{ $method->is_active ? 'green' : 'zinc' }}">
                                    {{ $method->is_active ? __('messages.active') : __('messages.inactive_status') }}
                                </flux:badge>
                            </td>
                            <td class="px-4 py-3 text-end">
                                <flux:button type="button" size="sm" variant="ghost" wire:click="editPaymentMethod({{ $method->id }})">
                                    {{ __('messages.edit') }}
                                </flux:button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    @if ($editingPaymentMethodId !== null)
        <form wire:submit="savePaymentMethod" class="mt-6 space-y-4 rounded-xl border border-dashed border-zinc-300 p-4 dark:border-zinc-600">
            <flux:heading size="sm" class="text-zinc-900 dark:text-zinc-100">
                {{ $editingPaymentMethodId > 0 ? __('messages.payment_method_edit') : __('messages.payment_method_add') }}
            </flux:heading>

            <flux:field>
                <flux:label>{{ __('messages.name') }}</flux:label>
                <flux:input wire:model.defer="paymentMethodName" class="w-full max-w-md" class:input="focus:!border-(--color-accent) focus:!border-1 focus:!ring-0 focus:!outline-none focus:!ring-offset-0" />
                <flux:error name="paymentMethodName" />
            </flux:field>

            <flux:field>
                <flux:label>{{ __('messages.payment_method_account_text') }}</flux:label>
                <flux:textarea wire:model.defer="paymentMethodAccountText" rows="4" class="w-full max-w-xl" />
                <flux:text class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">{{ __('messages.payment_method_account_text_hint') }}</flux:text>
                <flux:error name="paymentMethodAccountText" />
            </flux:field>

            <div class="flex flex-wrap gap-4">
                <flux:field>
                    <flux:label>{{ __('messages.order') }}</flux:label>
                    <flux:input wire:model.defer="paymentMethodSortOrder" type="number" min="0" max="9999" class="w-28" class:input="focus:!border-(--color-accent) focus:!border-1 focus:!ring-0 focus:!outline-none focus:!ring-offset-0" />
                    <flux:error name="paymentMethodSortOrder" />
                </flux:field>

                <flux:field>
                    <div class="flex items-center gap-3 pt-6">
                        <flux:label class="!mb-0">{{ __('messages.active') }}</flux:label>
                        <flux:switch wire:model.defer="paymentMethodIsActive" />
                    </div>
                </flux:field>
            </div>

            <flux:field>
                <flux:label>{{ __('messages.payment_method_image') }}</flux:label>
                <input
                    type="file"
                    accept="image/jpeg,image/png,image/webp,image/gif"
                    wire:model="paymentMethodImageFile"
                    class="block w-full max-w-md text-sm text-zinc-600 file:mr-4 file:rounded-lg file:border-0 file:bg-zinc-100 file:px-4 file:py-2 dark:text-zinc-400 dark:file:bg-zinc-700"
                />
                <div wire:loading wire:target="paymentMethodImageFile" class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                    {{ __('messages.please_wait') }}
                </div>
                <flux:error name="paymentMethodImageFile" />
                @if ($editingPaymentMethodId > 0)
                    @php $editingMethod = $this->paymentMethods->firstWhere('id', $editingPaymentMethodId); @endphp
                    @if ($editingMethod?->imageUrl())
                        <div class="mt-2 flex items-center gap-3">
                            <img src="{{ $editingMethod->imageUrl() }}" alt="" class="h-12 rounded-lg object-cover" />
                            <flux:checkbox wire:model.defer="removePaymentMethodImage" label="{{ __('messages.payment_method_remove_image') }}" />
                        </div>
                    @endif
                @endif
            </flux:field>

            <div class="flex flex-wrap gap-2">
                <flux:button type="submit" variant="primary" wire:loading.attr="disabled">
                    {{ __('messages.save') }}
                </flux:button>
                <flux:button type="button" variant="ghost" wire:click="resetPaymentMethodForm">
                    {{ __('messages.cancel') }}
                </flux:button>
                <x-action-message class="ms-2" on="payment-methods-saved">{{ __('messages.saved') }}</x-action-message>
            </div>
        </form>
    @endif
</section>
