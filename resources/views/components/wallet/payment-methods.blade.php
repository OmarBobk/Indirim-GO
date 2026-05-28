@props([
    'methods',
    'instance' => 'main',
])

@if ($methods->isNotEmpty())
    <section {{ $attributes->class(['space-y-4']) }} data-test="wallet-payment-methods">
        <div>
            <flux:heading size="sm" class="text-zinc-900 dark:text-zinc-100">
                {{ __('messages.wallet_payment_methods_heading') }}
            </flux:heading>
            <flux:text class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                {{ __('messages.wallet_payment_methods_hint') }}
            </flux:text>
        </div>

        <div class="flex flex-col gap-4">
            @foreach ($methods as $method)
                <article
                    wire:key="wallet-payment-method-{{ $instance }}-{{ $method->id }}"
                    x-data="walletPaymentMethodCard({
                        methodId: {{ $method->id }},
                        copyAriaLabel: @js(__('messages.copy_account_details')),
                        copiedAnnouncement: @js(__('messages.account_details_copied')),
                    })"
                    class="overflow-hidden rounded-2xl border-2 bg-white shadow-sm transition dark:bg-zinc-900"
                    x-bind:class="paymentRoot().selectedId === {{ $method->id }}
                        ? 'border-(--color-accent) ring-2 ring-(--color-accent)/25'
                        : 'border-zinc-200 dark:border-zinc-700'"
                >
                    <button
                        type="button"
                        class="flex w-full cursor-pointer flex-col gap-4 p-4 text-start sm:p-5"
                        x-on:click="paymentRoot().selectedId = {{ $method->id }}"
                    >
                        <div class="flex w-full items-center justify-between gap-3">
                            <flux:heading size="sm" class="text-zinc-900 dark:text-zinc-100">
                                {{ $method->name }}
                            </flux:heading>
                            <flux:badge
                                color="amber"
                                x-show="paymentRoot().selectedId === {{ $method->id }}"
                                x-cloak
                            >
                                {{ __('messages.wallet_payment_method_selected') }}
                            </flux:badge>
                        </div>

                        @if ($method->imageUrl())
                            <div class="mx-auto w-full max-w-sm rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-600">
                                <img
                                    src="{{ $method->imageUrl() }}"
                                    alt="{{ $method->name }}"
                                    class="mx-auto max-h-64 w-full object-contain"
                                    width="320"
                                    height="320"
                                    loading="lazy"
                                    decoding="async"
                                />
                            </div>
                        @endif

                        <div
                            class="w-full rounded-xl border border-zinc-200 bg-zinc-50 p-4 transition-[box-shadow] duration-300 dark:border-zinc-700 dark:bg-zinc-800/80"
                            x-bind:class="copied ? 'ring-2 ring-emerald-500/70 dark:ring-emerald-400/60' : ''"
                        >
                            <flux:text class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                                {{ __('messages.payment_method_account_text') }}
                            </flux:text>
                            <p
                                dir="ltr"
                                class="mt-2 font-mono text-base leading-relaxed tracking-wide break-all text-zinc-900 sm:text-lg dark:text-zinc-100"
                            >{!! $method->accountTextHtml() !!}</p>
                        </div>
                    </button>

                    <div class="border-t border-zinc-100 px-4 pb-4 pt-3 dark:border-zinc-800 sm:px-5">
                        <span class="sr-only whitespace-pre-line" x-ref="accountText">{{ $method->accountTextPlain() }}</span>
                        <p class="sr-only" aria-live="polite" x-text="copied ? copiedAnnouncement : ''"></p>
                        <button
                            type="button"
                            class="inline-flex h-10 w-full cursor-pointer items-center justify-center gap-2 rounded-lg border border-black/10 text-sm font-medium shadow-[inset_0px_1px_--theme(--color-white/.2)] transition-colors duration-300 dark:border-0"
                            x-bind:class="copied
                                ? 'bg-emerald-600 text-white hover:bg-emerald-600'
                                : 'bg-(--color-accent) text-(--color-accent-foreground) hover:bg-accent-hover'"
                            x-bind:aria-label="copied ? copiedAnnouncement : copyAriaLabel"
                            x-on:click.stop="copyAccount()"
                        >
                            <flux:icon
                                icon="check"
                                class="size-4 shrink-0"
                                x-show="copied"
                                x-cloak
                            />
                            <flux:icon
                                icon="clipboard-document"
                                class="size-4 shrink-0"
                                x-show="!copied"
                            />
                            <span x-show="!copied">{{ __('messages.copy_account_details') }}</span>
                            <span x-show="copied" x-cloak class="font-semibold">{{ __('messages.copied') }}</span>
                        </button>
                    </div>
                </article>
            @endforeach
        </div>

        @error('paymentMethodId')
            <flux:text class="text-xs text-red-600">{{ $message }}</flux:text>
        @enderror
    </section>
@endif
