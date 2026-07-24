<?php

use App\Concerns\ProfileValidationRules;
use App\Enums\Timezone;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Masmerise\Toaster\Toaster;

new #[Layout('layouts::frontend')] class extends Component
{
    use ProfileValidationRules;

    public string $name = '';

    public string $username = '';

    public string $email = '';

    public ?string $phone = null;

    public ?string $country_code = null;

    public ?string $timezone = null;

    public string $preferred_currency = 'USD';

    /** Not edited on this page; required by ProfileValidationRules. */
    public ?string $profile_photo = null;

    private const ALLOWED_COUNTRY_CODES = ['+963', '+90'];

    public function mount(): void
    {
        abort_unless(auth()->check(), 403);
        $user = Auth::user();
        $this->name = $user->name;
        $this->username = $user->username ?? '';
        $this->email = $user->email;
        $this->phone = $user->phone;
        $this->country_code = $user->country_code && in_array($user->country_code, self::ALLOWED_COUNTRY_CODES, true)
            ? $user->country_code
            : null;
        $this->timezone = $user->timezone?->value;
        $this->preferred_currency = in_array($user->preferred_currency, ['USD', 'TRY'], true) ? $user->preferred_currency : 'USD';
    }

    private function normalizeCountryCode(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return in_array($value, self::ALLOWED_COUNTRY_CODES, true) ? $value : null;
    }

    public function updateProfileInformation(): void
    {
        $user = Auth::user();
        $rules = array_merge($this->profileRules($user->id), [
            'timezone' => ['nullable', 'string', Rule::in(Timezone::values())],
        ]);
        $validated = $this->validate($rules);
        $fillable = array_diff_key($validated, array_flip(['profile_photo']));
        $fillable['country_code'] = $this->normalizeCountryCode($fillable['country_code'] ?? null);
        $user->fill($fillable);
        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }
        $user->save();
        Toaster::success(__('messages.saved'));
        Session::flash('profile-updated', true);
        $this->redirect(route('profile'), navigate: true);
    }

    public function resendVerificationNotification(): void
    {
        $user = Auth::user();
        if ($user->hasVerifiedEmail()) {
            return;
        }
        $user->sendEmailVerificationNotification();
        Toaster::info(__('messages.verification_link_sent'));
        Session::flash('status', 'verification-link-sent');
    }

    #[Computed]
    public function hasUnverifiedEmail(): bool
    {
        return Auth::user() instanceof MustVerifyEmail && ! Auth::user()->hasVerifiedEmail();
    }

    public function render(): View
    {
        return $this->view()->title(__('messages.update_your_profile_information'));
    }
};
?>

<x-storefront.page width="work">
    <x-storefront.page-header
        :title="__('messages.update_your_profile_information')"
        :description="__('messages.profile')"
        :show-back="true"
        :back-fallback="route('profile')"
    />

    <x-storefront.card>
        <form wire:submit="updateProfileInformation" class="space-y-6">
            <div class="grid gap-4 md:grid-cols-2">
                <flux:field>
                    <flux:label>{{ __('messages.name') }}</flux:label>
                    <flux:input wire:model.defer="name" type="text" required autofocus autocomplete="name" class="w-full" class:input="focus:!border-(--color-accent) focus:!border-1 focus:!ring-0 focus:!outline-none focus:!ring-offset-0" />
                    <flux:error name="name" />
                </flux:field>
                <flux:field>
                    <flux:label>{{ __('messages.username') }}</flux:label>
                    <flux:input.group>
                        <flux:input.group.prefix>@</flux:input.group.prefix>
                        <flux:input wire:model.defer="username" type="text" required autocomplete="username" class="w-full" class:input="focus:!border-(--color-accent) focus:!border-1 focus:!ring-0 focus:!outline-none focus:!ring-offset-0" />
                    </flux:input.group>
                    <flux:error name="username" />
                </flux:field>
                <flux:field>
                    <flux:label>{{ __('messages.email') }}</flux:label>
                    <flux:input wire:model.defer="email" type="email" required autocomplete="email" class="w-full" class:input="focus:!border-(--color-accent) focus:!border-1 focus:!ring-0 focus:!outline-none focus:!ring-offset-0" />
                    @if ($this->hasUnverifiedEmail)
                        <flux:text class="mt-2 text-sm text-zinc-600 dark:text-zinc-400">
                            {{ __('messages.your_email_address_is_unverified') }}
                            <flux:link class="cursor-pointer" wire:click.prevent="resendVerificationNotification">
                                {{ __('messages.click_here_to_re_send_the_verification_email') }}
                            </flux:link>
                        </flux:text>
                        @if (session('status') === 'verification-link-sent')
                            <flux:text class="mt-2 text-sm font-medium text-green-600 dark:text-green-400">
                                {{ __('messages.a_new_verification_link_has_been_sent_to_your_email_address') }}
                            </flux:text>
                        @endif
                    @endif
                    <flux:error name="email" />
                </flux:field>
                <flux:field>
                    <flux:label>{{ __('messages.phone') }}</flux:label>
                    <flux:input.group>
                        <flux:select wire:model.defer="country_code" class="max-w-fit focus:!border-(--color-accent) focus:!border-1 focus:!ring-0 focus:!outline-none focus:!ring-offset-0">
                            <flux:select.option value="">{{ __('messages.select_country_code') }}</flux:select.option>
                            <flux:select.option value="+90">+90</flux:select.option>
                            <flux:select.option value="+963">+963</flux:select.option>
                        </flux:select>
                        <flux:input wire:model.defer="phone" type="text" autocomplete="tel"
                                    mask="(999) 999-9999"
                                    placeholder="( ___ ) ___-____"
                                    class="w-full" class:input="focus:!border-(--color-accent) focus:!border-1 focus:!ring-0 focus:!outline-none focus:!ring-offset-0" />
                    </flux:input.group>
                    <flux:error name="phone" />
                </flux:field>
            </div>

            <flux:field>
                <flux:label>{{ __('messages.timezone_label') }}</flux:label>
                <flux:select wire:model.defer="timezone" :placeholder="__('messages.timezone_optional')" class="w-full focus:!border-(--color-accent) focus:!border-1 focus:!ring-0 focus:!outline-none focus:!ring-offset-0">
                    <flux:select.option value="">{{ __('messages.timezone_optional') }}</flux:select.option>
                    @foreach (Timezone::cases() as $tz)
                        <flux:select.option value="{{ $tz->value }}">{{ $tz->displayName() }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:error name="timezone" />
            </flux:field>

            <flux:field>
                <flux:label>{{ __('messages.currency') }}</flux:label>
                <flux:select wire:model.defer="preferred_currency" class="w-full focus:!border-(--color-accent) focus:!border-1 focus:!ring-0 focus:!outline-none focus:!ring-offset-0">
                    <flux:select.option value="USD">USD</flux:select.option>
                    <flux:select.option value="TRY">TRY</flux:select.option>
                </flux:select>
                <flux:error name="preferred_currency" />
            </flux:field>

            <div class="flex flex-wrap items-center gap-3">
                <flux:button variant="primary" type="submit" icon="check">
                    {{ __('messages.save') }}
                </flux:button>
                <flux:button variant="ghost" href="{{ route('profile') }}" wire:navigate>
                    {{ __('messages.cancel') }}
                </flux:button>
            </div>
        </form>
    </x-storefront.card>
</x-storefront.page>
