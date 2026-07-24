<x-layouts::auth :title="__('messages.' . str_replace(' ', '_', strtolower(config('app.name'))))">
    <div class="flex flex-col gap-7">
        <x-auth-header title="{{ __('messages.create_an_account') }}" description="{{ __('messages.enter_your_details_below_to_create_your_account') }}" />

        <x-auth-session-status class="text-center" :status="session('status')" />

        <form
            method="POST"
            action="{{ route('register.store') }}"
            id="register-form"
            class="relative flex flex-col gap-6"
            x-data="{ submitting: false, passScore: 0 }"
            x-on:submit="submitting = true"
            x-on:input.capture="
                const t = $event.target;
                if (! t || t.name !== 'password') {
                    return;
                }
                const v = t.value;
                let s = 0;
                if (v.length >= 8) {
                    s++;
                }
                if (/[a-z]/.test(v) && /[A-Z]/.test(v)) {
                    s++;
                }
                if (/\d/.test(v)) {
                    s++;
                }
                if (/[^A-Za-z0-9]/.test(v)) {
                    s++;
                }
                passScore = Math.min(4, s);
            "
        >
            @csrf

            {{-- Honeypot: hidden from humans, filled by naive bots --}}
            @php($honeypotField = config('security.registration.honeypot_field', 'website'))
            <div class="pointer-events-none absolute -left-[9999px] h-0 w-0 overflow-hidden opacity-0" aria-hidden="true">
                <label for="{{ $honeypotField }}">Website</label>
                <input
                    type="text"
                    name="{{ $honeypotField }}"
                    id="{{ $honeypotField }}"
                    value=""
                    tabindex="-1"
                    autocomplete="off"
                />
            </div>

            <input type="hidden" name="timezone_detected" id="timezone-detected" value="">

            <div class="grid gap-5 md:grid-cols-2">
                <flux:field>
                    <flux:label>{{ __('messages.name') }}</flux:label>
                    <flux:input
                        name="name"
                        :value="old('name')"
                        type="text"
                        required
                        autofocus
                        autocomplete="name"
                        :placeholder="__('messages.full_name')"
                        class="w-full"
                        class:input="focus:!border-(--color-accent) focus:!border-1 focus:!ring-0 focus:!outline-none focus:!ring-offset-0"
                    />
                    <flux:error name="name" />
                </flux:field>
                <flux:field>
                    <flux:label>{{ __('messages.username') }}</flux:label>
                    <flux:input.group>
                        <flux:input.group.prefix>@</flux:input.group.prefix>
                        <flux:input
                            name="username"
                            :value="old('username')"
                            type="text"
                            required
                            autocomplete="username"
                            :placeholder="__('messages.username')"
                            class="w-full"
                            class:input="focus:!border-(--color-accent) focus:!border-1 focus:!ring-0 focus:!outline-none focus:!ring-offset-0"
                        />
                    </flux:input.group>
                    <flux:error name="username" />
                </flux:field>
                <flux:field class="md:col-span-2">
                    <flux:label>{{ __('messages.email_address') }}</flux:label>
                    <flux:input
                        name="email"
                        :value="old('email')"
                        type="email"
                        required
                        autocomplete="email"
                        :placeholder="__('messages.email_placeholder')"
                        class="w-full"
                        class:input="focus:!border-(--color-accent) focus:!border-1 focus:!ring-0 focus:!outline-none focus:!ring-offset-0"
                    />
                    <flux:error name="email" />
                </flux:field>
                <flux:field class="md:col-span-2">
                    <flux:label>{{ __('messages.password') }}</flux:label>
                    <flux:input
                        name="password"
                        type="password"
                        required
                        autocomplete="new-password"
                        :placeholder="__('messages.password')"
                        viewable
                        class="w-full"
                        class:input="focus:!border-(--color-accent) focus:!border-1 focus:!ring-0 focus:!outline-none focus:!ring-offset-0"
                    />
                    <flux:error name="password" />
                    <div class="mt-3 flex gap-1.5" role="status" aria-live="polite">
                        <template x-for="i in [1, 2, 3, 4]" :key="i">
                            <div
                                class="h-1.5 flex-1 rounded-full transition-colors duration-300"
                                :class="passScore >= i ? 'bg-accent shadow-[0_0_12px_color-mix(in_oklab,var(--color-accent)_35%,transparent)]' : 'bg-zinc-200 dark:bg-zinc-700'"
                            ></div>
                        </template>
                    </div>
                </flux:field>
                <flux:field>
                    <flux:label>{{ __('messages.confirm_password') }}</flux:label>
                    <flux:input
                        name="password_confirmation"
                        type="password"
                        required
                        autocomplete="new-password"
                        :placeholder="__('messages.confirm_password')"
                        viewable
                        class="w-full"
                        class:input="focus:!border-(--color-accent) focus:!border-1 focus:!ring-0 focus:!outline-none focus:!ring-offset-0"
                    />
                    <flux:error name="password_confirmation" />
                </flux:field>
                <flux:field>
                    <flux:label>{{ __('messages.currency') }}</flux:label>
                    <flux:select
                        name="preferred_currency"
                        :value="old('preferred_currency', 'USD')"
                        required
                        class="w-full focus:!border-(--color-accent) focus:!border-1 focus:!ring-0 focus:!outline-none focus:!ring-offset-0"
                    >
                        <flux:select.option value="USD">USD</flux:select.option>
                        <flux:select.option value="TRY">TRY</flux:select.option>
                    </flux:select>
                    <flux:error name="preferred_currency" />
                </flux:field>
                <flux:field class="md:col-span-2">
                    <flux:label>{{ __('messages.phone_number') }}</flux:label>
                    <flux:input.group>
                        <flux:select
                            name="country_code"
                            :value="old('country_code')"
                            autocomplete="tel-country-code"
                            class="max-w-fit focus:!border-(--color-accent) focus:!border-1 focus:!ring-0 focus:!outline-none focus:!ring-offset-0"
                        >
                            <flux:select.option value="">{{ __('messages.select_country_code') }}</flux:select.option>
                            <flux:select.option value="+90">+90</flux:select.option>
                            <flux:select.option value="+963">+963</flux:select.option>
                        </flux:select>
                        <flux:input
                            name="phone"
                            :value="old('phone')"
                            type="text"
                            autocomplete="tel"
                            mask="(999) 999-9999"
                            placeholder="( ___ ) ___-____"
                            class="w-full phone-input-rtl"
                            class:input="focus:!border-(--color-accent) focus:!border-1 focus:!ring-0 focus:!outline-none focus:!ring-offset-0"
                        />
                    </flux:input.group>
                    <flux:error name="phone" />
                </flux:field>
            </div>

            @if (config('services.turnstile.enabled'))
                <flux:field>
                    <div
                        class="cf-turnstile"
                        data-sitekey="{{ config('services.turnstile.site_key') }}"
                        data-theme="auto"
                    ></div>
                    <flux:error name="cf-turnstile-response" />
                </flux:field>
                @once
                    <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
                @endonce
            @endif

            <flux:button
                type="submit"
                variant="primary"
                class="w-full font-semibold shadow-sm shadow-zinc-900/10 dark:shadow-black/30"
                x-bind:disabled="submitting"
                x-bind:aria-busy="submitting"
            >
                {{ __('messages.create_account') }}
            </flux:button>
        </form>

        <div class="flex flex-wrap items-center justify-center gap-1 border-t border-zinc-200/80 pt-6 text-center text-sm text-zinc-600 dark:border-zinc-700/80 dark:text-zinc-400">
            <span>{{ __('messages.already_have_an_account') }}</span>
            <flux:link :href="route('login')" wire:navigate class="font-semibold text-accent hover:underline">
                {{ __('messages.log_in') }}
            </flux:link>
        </div>
    </div>

    <script>
        (function () {
            const timezone = Intl.DateTimeFormat().resolvedOptions().timeZone;
            const timezoneInput = document.getElementById('timezone-detected');

            if (timezoneInput) {
                timezoneInput.value = timezone;
            }

            const countryCodeSelect = document.querySelector('select[name="country_code"]');
            const form = document.getElementById('register-form');

            if (form && timezoneInput && ! timezoneInput.value) {
                form.addEventListener('submit', function () {
                    if (! timezoneInput.value && countryCodeSelect && countryCodeSelect.value) {
                        const countryCodeToTimezone = {
                            '+963': 'Asia/Damascus',
                            '+90': 'Europe/Istanbul',
                        };

                        const mappedTimezone = countryCodeToTimezone[countryCodeSelect.value];
                        if (mappedTimezone) {
                            timezoneInput.value = mappedTimezone;
                        }
                    }
                });
            }
        })();
    </script>
</x-layouts::auth>
