<div>
    @if ($canReport)
        <div
            class="storefront-fab fixed bottom-4 end-4 z-[70]"
            wire:key="quick-report-button-wrapper"
            data-test="storefront-bug-fab"
        >
            <flux:button variant="primary" icon="bug-ant" wire:click="show">
                {{ __('Report Bug') }}
            </flux:button>

            @if ($open)
                <div class="fixed inset-0 z-[80] flex items-center justify-center bg-black/50 p-4">
                    <div class="w-full max-w-2xl rounded-xl border border-zinc-200 bg-white p-4 shadow-xl dark:border-zinc-700 dark:bg-zinc-900">
                        <div class="space-y-4">
                            <div class="flex items-center justify-between">
                                <flux:heading size="lg">{{ __('Bug Report') }}</flux:heading>
                                <flux:button variant="ghost" icon="x-mark" wire:click="hide" />
                            </div>

                            <div class="space-y-4">
                                <div class="flex items-center justify-between">
                                    <flux:heading size="md">{{ __('Report a bug') }}</flux:heading>
                                    <flux:text class="text-xs text-zinc-500">{{ __('Step') }} {{ $currentStep }}/5</flux:text>
                                </div>

                                @if ($currentStep === 1)
                                    <flux:select wire:model.live="scenario" label="{{ __('Scenario') }}">
                                        <flux:select.option value="">{{ __('Select scenario') }}</flux:select.option>
                                        <flux:select.option value="notification">{{ __('Notification') }}</flux:select.option>
                                        <flux:select.option value="topup_payment">{{ __('Topup / Payment') }}</flux:select.option>
                                        <flux:select.option value="fulfillment">{{ __('Fulfillment') }}</flux:select.option>
                                        <flux:select.option value="dashboard">{{ __('Dashboard') }}</flux:select.option>
                                        <flux:select.option value="other">{{ __('Other') }}</flux:select.option>
                                    </flux:select>
                                    @error('scenario') <p class="text-xs text-red-500">{{ $message }}</p> @enderror
                                @endif

                                @if ($currentStep === 2)
                                    <flux:select wire:model.live="subtype" label="{{ __('Subtype') }}">
                                        <flux:select.option value="">{{ __('Select subtype') }}</flux:select.option>
                                        @foreach ($this->subtypeOptions() as $option)
                                            <flux:select.option value="{{ $option }}">{{ str_replace('_', ' ', $option) }}</flux:select.option>
                                        @endforeach
                                    </flux:select>
                                    @error('subtype') <p class="text-xs text-red-500">{{ $message }}</p> @enderror
                                @endif

                                @if ($currentStep === 3)
                                    <flux:select wire:model.live="severity" label="{{ __('Severity') }}">
                                        <flux:select.option value="low">{{ __('Low') }}</flux:select.option>
                                        <flux:select.option value="medium">{{ __('Medium') }}</flux:select.option>
                                        <flux:select.option value="high">{{ __('High') }}</flux:select.option>
                                        <flux:select.option value="critical">{{ __('Critical') }}</flux:select.option>
                                    </flux:select>
                                    <flux:textarea wire:model="description" label="{{ __('Short description') }}" rows="3" />
                                    @error('description') <p class="text-xs text-red-500">{{ $message }}</p> @enderror
                                @endif

                                @if ($currentStep === 4)
                                    <div class="space-y-2">
                                        <flux:text class="text-sm">{{ __('Add at least two clear steps.') }}</flux:text>
                                        @foreach ($steps as $index => $step)
                                            <div class="flex items-center gap-2" wire:key="bug-step-{{ $index }}">
                                                <flux:input
                                                    wire:model="steps.{{ $index }}"
                                                    placeholder="{{ __('Step') }} {{ $index + 1 }}"
                                                    class="flex-1"
                                                />
                                                <flux:button type="button" variant="ghost" wire:click="removeStep({{ $index }})">
                                                    {{ __('Remove') }}
                                                </flux:button>
                                            </div>
                                        @endforeach
                                        <flux:button type="button" variant="outline" wire:click="addStep">{{ __('Add step') }}</flux:button>
                                        @error('steps') <p class="text-xs text-red-500">{{ $message }}</p> @enderror
                                    </div>
                                @endif

                                @if ($currentStep === 5)
                                    <div class="space-y-2">
                                        <div class="relative" wire:loading.class="pointer-events-none opacity-60" wire:target="screenshots">
                                            <flux:input type="file" wire:model="screenshots" accept="image/*" multiple />
                                        </div>
                                        <div wire:loading.flex wire:target="screenshots" class="items-center gap-2 text-sm text-zinc-600 dark:text-zinc-400">
                                            <flux:icon.loading variant="micro" class="text-zinc-500" />
                                            {{ __('Uploading screenshots…') }}
                                        </div>
                                        <flux:text class="text-xs text-zinc-500">{{ __('Upload 1 to 5 screenshots (max 5MB each).') }}</flux:text>
                                        @error('screenshots') <p class="text-xs text-red-500">{{ $message }}</p> @enderror
                                        @error('screenshots.*') <p class="text-xs text-red-500">{{ $message }}</p> @enderror
                                    </div>
                                @endif

                                <div class="flex items-center justify-between pt-2">
                                    <flux:button
                                        type="button"
                                        variant="ghost"
                                        wire:click="previousStep"
                                        :disabled="$currentStep === 1"
                                        wire:loading.attr="disabled"
                                        wire:target="screenshots"
                                    >
                                        {{ __('Back') }}
                                    </flux:button>

                                    @if ($currentStep < 5)
                                        <flux:button type="button" variant="primary" wire:click="nextStep">
                                            {{ __('Next') }}
                                        </flux:button>
                                    @else
                                        <flux:button
                                            type="button"
                                            variant="primary"
                                            wire:click="submit"
                                            wire:loading.attr="disabled"
                                            wire:target="screenshots,submit"
                                        >
                                            <span wire:loading.remove wire:target="screenshots,submit" class="inline-flex items-center justify-center gap-2">
                                                {{ __('Submit bug report') }}
                                            </span>
                                            <span wire:loading.flex wire:target="screenshots" class="inline-flex items-center justify-center gap-2">
                                                <flux:icon.loading variant="micro" />
                                                {{ __('Uploading screenshots…') }}
                                            </span>
                                            <span wire:loading.flex wire:target="submit" class="inline-flex items-center justify-center gap-2">
                                                <flux:icon.loading variant="micro" />
                                                {{ __('Submitting…') }}
                                            </span>
                                        </flux:button>
                                    @endif
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            @endif
        </div>
    @endif
</div>
