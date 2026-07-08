<?php

use App\Actions\Topups\SubmitCustomerTopupRequest;
use App\Enums\TopupRequestStatus;
use App\Models\PaymentMethod;
use App\Models\TopupRequest;
use App\Models\WebsiteSetting;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Masmerise\Toaster\Toastable;

new #[Layout('layouts::frontend')] class extends Component
{
    use Toastable;
    use WithFileUploads;

    #[Url(as: 'amount')]
    public ?string $topupAmount = null;

    public ?int $paymentMethodId = null;

    /** @var \Illuminate\Http\UploadedFile|null */
    public $proofFile = null;

    public bool $attachProof = false;

    public function mount(): void
    {
        abort_unless(auth()->check(), 403);

        $firstMethodId = PaymentMethod::query()
            ->active()
            ->ordered()
            ->value('id');

        $this->paymentMethodId = $firstMethodId !== null ? (int) $firstMethodId : null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        $rules = [
            'topupAmount' => ['required', 'numeric', 'min:0.01'],
            'paymentMethodId' => [
                'required',
                'integer',
                Rule::exists('payment_methods', 'id')->where(fn ($query) => $query->where('is_active', true)),
            ],
            'attachProof' => ['boolean'],
        ];

        if ($this->attachProof) {
            $rules['proofFile'] = ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'];
        } else {
            $rules['proofFile'] = ['nullable'];
        }

        return $rules;
    }

    public function updatedAttachProof(bool $value): void
    {
        if (! $value) {
            $this->reset('proofFile');
        }
    }

    #[Computed]
    public function hasPendingTopup(): bool
    {
        return TopupRequest::query()
            ->where('user_id', auth()->id())
            ->where('status', TopupRequestStatus::Pending)
            ->exists();
    }

    /**
     * @return Collection<int, PaymentMethod>
     */
    #[Computed]
    public function activePaymentMethods(): Collection
    {
        return PaymentMethod::query()->active()->ordered()->get();
    }

    public function submitTopup(): void
    {
        if ($this->hasPendingTopup) {
            $this->warning(__('messages.topup_request_pending'));

            return;
        }

        $validated = $this->validate();

        try {
            app(SubmitCustomerTopupRequest::class)->handle(
                auth()->user(),
                (float) $validated['topupAmount'],
                (int) $validated['paymentMethodId'],
                (bool) $validated['attachProof'],
                $this->attachProof ? $this->proofFile : null,
            );
        } catch (ValidationException $exception) {
            throw $exception;
        }

        session()->flash('topup_submitted', true);
        $this->success(__('messages.topup_request_created'));

        $this->redirect(route('wallet'), navigate: true);
    }

    public function render(): View
    {
        return $this->view()->title(__('messages.wallet_add_funds'));
    }
};
?>

@php
    $topupDisplayCurrency = 'USD';
    $topupRate = WebsiteSetting::getUsdTryRate();
    if (strtoupper((string) (auth()->user()?->preferred_currency ?? 'USD')) === 'TRY' && $topupRate !== null && $topupRate > 0) {
        $topupDisplayCurrency = 'TRY';
    }
    $topupCurrencySign = $topupDisplayCurrency === 'TRY' ? '₺' : '$';
@endphp

<div
    class="mx-auto w-full max-w-2xl px-3 py-6 sm:px-0 sm:py-10"
    data-test="wallet-topup-page"
    data-wallet-payment-root
    x-data="{ get selectedId() { return $wire.paymentMethodId }, set selectedId(value) { $wire.paymentMethodId = value } }"
>
    <div class="mb-4 flex items-center gap-3">
        <x-back-button :fallback="route('wallet')" />
    </div>

    <header class="mb-6 space-y-2">
        <flux:heading size="lg" class="text-zinc-900 dark:text-zinc-100">
            {{ __('messages.wallet_add_funds') }}
        </flux:heading>
        <flux:text class="text-sm text-zinc-600 dark:text-zinc-400">
            {{ __('messages.wallet_topup_intro') }}
        </flux:text>
    </header>

    @if ($this->hasPendingTopup)
        <flux:callout class="mb-6" variant="warning" icon="clock">
            {{ __('messages.wallet_topup_pending_banner') }}
        </flux:callout>
    @else
        <section class="rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900 sm:p-6">
            <form class="space-y-5" wire:submit.prevent="submitTopup">
                <flux:input
                    class:input="focus:!border-(--color-accent) focus:!border-1 focus:!ring-0 focus:!outline-none focus:!ring-offset-0"
                    name="topupAmount"
                    label="{{ __('messages.amount').' ('.$topupCurrencySign.')' }}"
                    wire:model.defer="topupAmount"
                    placeholder="0.00"
                />

                <div class="flex flex-wrap gap-2">
                    @foreach ([10, 25, 50, 100] as $preset)
                        <flux:button
                            type="button"
                            variant="ghost"
                            size="sm"
                            wire:click="$set('topupAmount', '{{ $preset }}')"
                        >
                            {{ $topupCurrencySign }}{{ $preset }}
                        </flux:button>
                    @endforeach
                </div>

                <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-zinc-100 bg-zinc-50 px-3 py-3 dark:border-zinc-800 dark:bg-zinc-800/60">
                    <div class="min-w-0 flex-1 space-y-1 pe-2">
                        <flux:text class="text-sm font-medium text-zinc-800 dark:text-zinc-200">{{ __('messages.topup_attach_proof_toggle') }}</flux:text>
                        <flux:text class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('messages.topup_attach_proof_hint') }}</flux:text>
                    </div>
                    <flux:switch
                        class="shrink-0 focus:!border-(--color-accent) focus:!border-1 focus:!ring-0 focus:!outline-none focus:!ring-offset-0"
                        wire:model.live="attachProof"
                    />
                </div>

                @if ($attachProof)
                    <flux:field>
                        <flux:label>{{ __('messages.proof_of_payment') }}</flux:label>
                        <input
                            type="file"
                            name="proofFile"
                            accept=".jpg,.jpeg,.png,.webp,.pdf"
                            wire:model.defer="proofFile"
                            class="block w-full text-sm text-zinc-600 file:mr-4 file:rounded-lg file:border-0 file:bg-zinc-100 file:px-4 file:py-2 file:text-sm file:font-medium file:text-zinc-800 hover:file:bg-zinc-200 dark:text-zinc-400 dark:file:bg-zinc-700 dark:file:text-zinc-200 dark:hover:file:bg-zinc-600"
                        />
                    </flux:field>
                    @error('proofFile')
                        <flux:text class="text-xs text-red-600">{{ $message }}</flux:text>
                    @enderror
                @endif

                <x-wallet.payment-methods
                    :methods="$this->activePaymentMethods"
                />

                <flux:button
                    type="submit"
                    variant="primary"
                    class="w-full justify-center !bg-accent !text-accent-foreground hover:!bg-accent-hover"
                    wire:loading.attr="disabled"
                >
                    <span wire:loading.remove>{{ __('messages.submit_topup') }}</span>
                    <span wire:loading>{{ __('messages.please_wait') }}</span>
                </flux:button>
            </form>
        </section>
    @endif
</div>
