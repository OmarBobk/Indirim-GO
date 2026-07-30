<?php

use App\Actions\Refunds\GetCustomerRefundDetail;
use App\Models\WebsiteSetting;
use App\Support\CustomerRefundPresenter;
use App\Support\Financial\CustomerFinancialRealtimeScope;
use App\Support\WalletTransactionPublicRef;
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

    public function mount(string $refund): void
    {
        abort_unless(auth()->check(), 403);

        $this->publicRef = WalletTransactionPublicRef::normalize($refund);
        $this->loadDetail();
    }

    #[On('customer-financial-invalidate')]
    public function handleFinancialInvalidate(array $reasons = [], bool $isReconcile = false): void
    {
        if (! CustomerFinancialRealtimeScope::isRelevant(
            ['reasons' => $reasons, 'isReconcile' => $isReconcile],
            CustomerFinancialRealtimeScope::SURFACE_REFUND_DETAIL,
        )) {
            $this->skipRender();

            return;
        }

        $this->loadDetail();
        $this->dispatch('refund-detail-updated');
    }

    private function loadDetail(): void
    {
        $user = auth()->user();
        abort_unless($user !== null, 403);

        $this->isBusy = true;

        try {
            $dto = app(GetCustomerRefundDetail::class)->handle($user, $this->publicRef);
            $this->detail = app(CustomerRefundPresenter::class)->presentDetail(
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
        return $this->view()->title(__('messages.refund_detail_title'));
    }
};
?>

@php
    $detail = $this->detail ?? [];
@endphp

<x-storefront.page
    width="work"
    data-test="wallet-refund-detail-page"
    data-financial-surface="refund-detail"
    aria-busy="{{ $this->isBusy ? 'true' : 'false' }}"
>
    <div class="storefront-section-stack">
        <x-storefront.page-header
            :title="__('messages.refund_detail_title')"
            :description="$detail['public_reference'] ?? ''"
            :show-back="true"
            :back-fallback="route('wallet.refunds.index')"
        />

        <x-wallet.financial-centre-nav active="refunds" />

        <x-wallet.refund-status-header :detail="$detail" />

        <x-wallet.refund-order-context :detail="$detail" />

        <x-wallet.refund-amount-summary :detail="$detail" />

        <x-wallet.refund-timeline :timeline="$detail['timeline'] ?? []" />

        <x-wallet.refund-recovery :detail="$detail" />
    </div>
</x-storefront.page>
