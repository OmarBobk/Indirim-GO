<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Actions\Commissions\CreatePayoutBatch;
use App\Enums\CommissionStatus;
use App\Enums\FulfillmentStatus;
use App\Models\Commission;
use App\Models\WebsiteSetting;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;
use Masmerise\Toaster\Toastable;

#[Layout('layouts.app')]
final class CommissionsTable extends Component
{
    use Toastable;
    use WithPagination;

    public int $perPage = 20;

    /**
     * @var array<int, int|string>
     */
    public array $selectedCommissionIds = [];

    public ?string $payoutNotes = null;

    public function mount(): void
    {
        abort_unless(auth()->user()?->can('manage_settlements'), 403);
    }

    public function markPaid(int $commissionId): void
    {
        abort_unless(auth()->user()?->can('manage_settlements'), 403);
        try {
            app(CreatePayoutBatch::class)->handle([$commissionId], null, false);
        } catch (ValidationException $exception) {
            $this->error((string) collect($exception->errors())->flatten()->first());

            return;
        }

        $this->success(__('messages.commission_marked_credited'));
    }

    public function render(): View
    {
        /** @var LengthAwarePaginator<int, Commission> $commissions */
        $commissions = Commission::query()
            ->select([
                'id',
                'order_id',
                'fulfillment_id',
                'salesperson_id',
                'commission_amount',
                'commission_rate_percent',
                'status',
                'payout_batch_id',
                'wallet_transaction_id',
                'paid_at',
            ])
            ->with([
                'salesperson:id,name,email',
                'order:id,order_number,paid_at',
                'fulfillment:id,status',
                'payoutBatch:id',
                'order.items:id,order_id',
                'order.items.fulfillments:id,order_item_id,status',
            ])
            ->latest('id')
            ->paginate($this->perPage);

        return view('livewire.admin.commissions-table', [
            'commissions' => $commissions,
        ])->title(__('messages.commissions'));
    }

    public function canMarkPaid(Commission $commission): bool
    {
        if ($commission->status !== CommissionStatus::Pending) {
            return false;
        }

        if ($commission->fulfillment !== null) {
            return $commission->fulfillment->status === FulfillmentStatus::Completed;
        }

        $order = $commission->order;
        if ($order === null || $order->items->isEmpty()) {
            return false;
        }

        foreach ($order->items as $item) {
            if ($item->fulfillments->isEmpty()) {
                return false;
            }

            $allCompleted = $item->fulfillments->every(
                fn ($fulfillment): bool => $fulfillment->status === FulfillmentStatus::Completed
            );

            if (! $allCompleted) {
                return false;
            }
        }

        return true;
    }

    public function isEligibleForPayout(Commission $commission): bool
    {
        return $this->payoutIneligibilityReason($commission) === null;
    }

    public function payoutIneligibilityReason(Commission $commission): ?string
    {
        if (! $this->canMarkPaid($commission)) {
            return __('messages.payout_reason_fulfillment_not_completed');
        }

        if ($commission->payout_batch_id !== null) {
            return __('messages.payout_reason_already_in_batch');
        }

        if ($commission->wallet_transaction_id !== null) {
            return __('messages.payout_reason_already_credited');
        }

        if ($commission->order === null || $commission->order->paid_at === null) {
            return __('messages.payout_reason_order_paid_date_missing');
        }

        $payoutWaitDays = WebsiteSetting::getCommissionPayoutWaitDays();

        if ($commission->order->paid_at->greaterThan(CarbonImmutable::now()->subDays($payoutWaitDays))) {
            return __('messages.payout_reason_too_recent', ['days' => $payoutWaitDays]);
        }

        return null;
    }

    public function createPayout(): void
    {
        abort_unless(auth()->user()?->can('manage_settlements'), 403);

        try {
            $batch = app(CreatePayoutBatch::class)->handle(
                array_map(static fn ($id): int => (int) $id, $this->selectedCommissionIds),
                $this->payoutNotes
            );
        } catch (ValidationException $exception) {
            $this->error((string) collect($exception->errors())->flatten()->first());

            return;
        }

        $this->selectedCommissionIds = [];
        $this->payoutNotes = null;
        $this->success(__('messages.payout_batch_created', ['id' => $batch->id]));
        $this->resetPage();
    }
}
