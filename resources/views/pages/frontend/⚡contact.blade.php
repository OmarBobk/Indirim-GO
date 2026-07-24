<?php

use App\Mail\ContactFormMail;
use App\Models\WebsiteSetting;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Mail;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Masmerise\Toaster\Toastable;

new #[Layout('layouts::frontend')] class extends Component
{
    use Toastable;

    public string $name = '';

    public string $email = '';

    public string $subject = '';

    public string $message = '';

    public function submit(): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'subject' => ['required', 'string', 'max:255'],
            'message' => ['required', 'string', 'max:5000'],
        ]);

        $to = WebsiteSetting::getContactEmail();
        if ($to === null || $to === '') {
            $this->addError('form', __('messages.contact_form_unavailable'));

            return;
        }

        Mail::to($to)->send(new ContactFormMail(
            $this->name,
            $this->email,
            $this->subject,
            $this->message
        ));

        $this->success(__('messages.contact_success'));
        $this->reset('name', 'email', 'subject', 'message');
    }

    public function render(): View
    {
        return $this->view()
            ->title(__('messages.contact_us_page_title'))
            ->with([
                'contactEmail' => WebsiteSetting::getContactEmail(),
                'primaryPhone' => WebsiteSetting::getPrimaryPhone(),
                'secondaryPhone' => WebsiteSetting::getSecondaryPhone(),
            ]);
    }
};
?>

<x-storefront.page width="work">
    <x-storefront.page-header
        :title="__('main.contact_us')"
        :description="__('messages.contact_page_intro')"
        :show-back="true"
        :back-fallback="route('account')"
    />

    @if ($contactEmail || $primaryPhone || $secondaryPhone)
        <x-storefront.card class="mb-8">
            <flux:heading size="sm" class="storefront-type-section mb-4 text-zinc-900 dark:text-zinc-100">{{ __('messages.contact_info') }}</flux:heading>
            <div class="flex flex-col gap-3 text-sm">
                @if ($contactEmail)
                    <div class="flex items-center gap-3">
                        <flux:icon icon="envelope" variant="outline" class="size-5 shrink-0 text-zinc-500 dark:text-zinc-400" />
                        <a href="mailto:{{ $contactEmail }}" class="text-(--color-accent) hover:underline">{{ $contactEmail }}</a>
                    </div>
                @endif
                @if ($primaryPhone)
                    <div class="flex items-center gap-3">
                        <flux:icon icon="phone" variant="outline" class="size-5 shrink-0 text-zinc-500 dark:text-zinc-400" />
                        <a href="tel:{{ preg_replace('/\s+/', '', $primaryPhone) }}" class="text-zinc-700 dark:text-zinc-300" dir="ltr">{{ $primaryPhone }}</a>
                    </div>
                @endif
                @if ($secondaryPhone)
                    <div class="flex items-center gap-3">
                        <flux:icon icon="device-phone-mobile" variant="outline" class="size-5 shrink-0 text-zinc-500 dark:text-zinc-400" />
                        <a href="tel:{{ preg_replace('/\s+/', '', $secondaryPhone) }}" class="text-zinc-700 dark:text-zinc-300" dir="ltr">{{ $secondaryPhone }}</a>
                    </div>
                @endif
            </div>
        </x-storefront.card>
    @endif

    <x-storefront.card>
        <flux:heading size="sm" class="storefront-type-section mb-4 text-zinc-900 dark:text-zinc-100">{{ __('messages.send_us_a_message') }}</flux:heading>

        <form wire:submit="submit" class="space-y-6">
            <flux:error name="form" class="mb-4" />

            <div class="grid gap-4 sm:grid-cols-2">
                <flux:field>
                    <flux:label>{{ __('messages.name') }}</flux:label>
                    <flux:input wire:model.defer="name" type="text" required autocomplete="name" class="w-full" class:input="focus:!border-(--color-accent) focus:!border-1 focus:!ring-0 focus:!outline-none focus:!ring-offset-0" />
                    <flux:error name="name" />
                </flux:field>
                <flux:field>
                    <flux:label>{{ __('messages.email') }}</flux:label>
                    <flux:input wire:model.defer="email" type="email" required autocomplete="email" class="w-full" class:input="focus:!border-(--color-accent) focus:!border-1 focus:!ring-0 focus:!outline-none focus:!ring-offset-0" />
                    <flux:error name="email" />
                </flux:field>
            </div>

            <flux:field>
                <flux:label>{{ __('messages.subject') }}</flux:label>
                <flux:input wire:model.defer="subject" type="text" required class="w-full" class:input="focus:!border-(--color-accent) focus:!border-1 focus:!ring-0 focus:!outline-none focus:!ring-offset-0" />
                <flux:error name="subject" />
            </flux:field>

            <flux:field>
                <flux:label>{{ __('messages.message') }}</flux:label>
                <flux:textarea wire:model.defer="message" required rows="5" class="w-full" class:input="focus:!border-(--color-accent) focus:!border-1 focus:!ring-0 focus:!outline-none focus:!ring-offset-0" />
                <flux:error name="message" />
            </flux:field>

            <flux:button type="submit" variant="primary" wire:loading.attr="disabled">
                {{ __('messages.send_message') }}
            </flux:button>
        </form>
    </x-storefront.card>
</x-storefront.page>
