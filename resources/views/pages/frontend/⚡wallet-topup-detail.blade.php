<?php

use App\Actions\Topups\GetCustomerTopupDetail;
use App\Models\WebsiteSetting;
use App\Support\CustomerTopupPresenter;
use App\Support\Financial\CustomerFinancialRealtimeScope;
use App\Support\TopupRequestPublicRef;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

new #[Layout('layouts::frontend')] class extends Component
{
    public string $publicRef = '';

    /** @var array<string, mixed>|null */
    public ?array $detail = null;

    public bool $isBusy = false;

    public function mount(string $topup): void
    {
        abort_unless(auth()->check(), 403);

        $this->publicRef = TopupRequestPublicRef::normalize($topup);
        $this->loadDetail();
    }

    #[On('customer-financial-invalidate')]
    public function handleFinancialInvalidate(array $reasons = [], bool $isReconcile = false): void
    {
        if (! CustomerFinancialRealtimeScope::isRelevant(
            ['reasons' => $reasons, 'isReconcile' => $isReconcile],
            CustomerFinancialRealtimeScope::SURFACE_TOPUP_DETAIL,
        )) {
            $this->skipRender();

            return;
        }

        $this->loadDetail();
        $this->dispatch('topup-detail-updated');
    }

    private function loadDetail(): void
    {
        $user = auth()->user();
        abort_unless($user !== null, 403);

        $this->isBusy = true;

        try {
            $dto = app(GetCustomerTopupDetail::class)->handle($user, $this->publicRef);
            $this->detail = app(CustomerTopupPresenter::class)->presentDetail(
                $dto,
                $user,
                WebsiteSetting::getPricesVisible()
            );
        } catch (NotFoundHttpException) {
            abort(404);
        }

        $this->isBusy = false;
    }

    public function render(): View
    {
        return $this->view()->title(__('messages.topup_detail_title'));
    }
};
?>

@php
    $detail = $this->detail ?? [];
@endphp

<x-storefront.page
    width="work"
    data-test="wallet-topup-detail-page"
    data-financial-surface="topup-detail"
    aria-busy="{{ $this->isBusy ? 'true' : 'false' }}"
>
    <div class="storefront-section-stack">
        <x-storefront.page-header
            :title="__('messages.topup_detail_title')"
            :description="$detail['public_reference'] ?? ''"
            :show-back="true"
            :back-fallback="route('wallet.topups.index')"
        />

        <x-wallet.financial-centre-nav active="topups" />

        <x-wallet.topup-status-header :detail="$detail" />

        <x-wallet.topup-timeline :timeline="$detail['timeline'] ?? []" />

        @if (! empty($detail['payment_instructions']))
            <section class="storefront-card storefront-card--pad-md" data-test="topup-payment-instructions">
                <flux:heading size="sm">{{ __('messages.wallet_payment_methods_heading') }}</flux:heading>
                <flux:text class="mt-1 text-sm font-medium text-zinc-900 dark:text-zinc-100">
                    {{ $detail['payment_method_name'] ?? '' }}
                </flux:text>
                <p class="mt-3 whitespace-pre-line text-sm text-zinc-600 dark:text-zinc-300">{{ $detail['payment_instructions'] }}</p>
            </section>
        @endif

        <x-wallet.topup-proof :detail="$detail" />

        <x-wallet.topup-recovery :detail="$detail" />
    </div>
</x-storefront.page>
