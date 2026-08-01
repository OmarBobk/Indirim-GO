<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Actions\Commissions\GetHistoricalCommissionExposure;
use App\Actions\Commissions\ReviewHistoricalCommissionExposure;
use App\Enums\HistoricalCommissionExposureOutcome;
use App\Enums\HistoricalCommissionExposureReason;
use App\Support\AdminHistoricalCommissionExposurePresenter;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Masmerise\Toaster\Toastable;

#[Layout('layouts.app')]
final class HistoricalCommissionExposureIndex extends Component
{
    use Toastable;
    use WithPagination;

    #[Url]
    public string $filter = 'unreviewed';

    #[Url]
    public string $search = '';

    #[Url]
    public string $refundFrom = '';

    #[Url]
    public string $refundTo = '';

    public ?int $reviewCommissionId = null;

    public ?int $reviewRefundId = null;

    public string $reviewOutcome = '';

    public string $reviewReason = '';

    public string $reviewNote = '';

    public bool $showReviewForm = false;

    public function mount(): void
    {
        abort_unless(auth()->user()?->can('view_historical_commission_exposure'), 404);
        if ($this->refundFrom === '') {
            $this->refundFrom = now()->subMonths(GetHistoricalCommissionExposure::DEFAULT_LOOKBACK_MONTHS)->toDateString();
        }
    }

    public function updatedFilter(): void
    {
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedRefundFrom(): void
    {
        $this->resetPage();
    }

    public function updatedRefundTo(): void
    {
        $this->resetPage();
    }

    public function openReview(int $commissionId, int $refundId): void
    {
        abort_unless(auth()->user()?->can('view_historical_commission_exposure'), 404);
        $this->reviewCommissionId = $commissionId;
        $this->reviewRefundId = $refundId;
        $this->reviewOutcome = '';
        $this->reviewReason = '';
        $this->reviewNote = '';
        $this->showReviewForm = true;
    }

    public function submitReview(): void
    {
        $user = auth()->user();
        abort_unless($user !== null && $user->can('view_historical_commission_exposure'), 404);
        abort_unless($this->reviewCommissionId !== null && $this->reviewRefundId !== null, 404);

        $this->validate([
            'reviewOutcome' => ['required', 'in:'.implode(',', HistoricalCommissionExposureOutcome::values())],
            'reviewReason' => ['required', 'in:'.implode(',', HistoricalCommissionExposureReason::values())],
            'reviewNote' => ['nullable', 'string', 'max:500'],
        ], [
            'reviewOutcome.required' => __('messages.historical_exposure_outcome_required'),
            'reviewReason.required' => __('messages.historical_exposure_reason_required'),
        ]);

        $result = app(ReviewHistoricalCommissionExposure::class)->handle(
            $user,
            (int) $this->reviewCommissionId,
            (int) $this->reviewRefundId,
            $this->reviewOutcome,
            $this->reviewReason,
            $this->reviewNote !== '' ? $this->reviewNote : null,
        );

        $this->showReviewForm = false;
        $this->reviewCommissionId = null;
        $this->reviewRefundId = null;

        if (in_array($result['outcome'], ['reviewed', 'replayed'], true)) {
            $this->success(__($result['message_key']));
        } else {
            $this->error(__($result['message_key']));
        }
    }

    public function render(): View
    {
        $user = auth()->user();
        abort_unless($user !== null, 404);

        $page = app(GetHistoricalCommissionExposure::class)->handle($user, [
            'filter' => $this->filter,
            'search' => $this->search,
            'refund_from' => $this->refundFrom !== '' ? $this->refundFrom : null,
            'refund_to' => $this->refundTo !== '' ? $this->refundTo : null,
            'page' => $this->getPage(),
        ]);

        $presenter = app(AdminHistoricalCommissionExposurePresenter::class);

        return view('livewire.admin.historical-commission-exposure-index', [
            'rows' => $presenter->presentList($page['items']),
            'summary' => $page['summary'],
            'pagination' => [
                'current_page' => $page['current_page'],
                'last_page' => $page['last_page'],
                'total' => $page['total'],
            ],
            'outcomeOptions' => $presenter->outcomeOptions(),
            'reasonOptions' => $presenter->reasonOptions(),
            'inboxHref' => route('admin.commission-clawbacks.index'),
        ])->title(__('messages.historical_exposure_title'));
    }
}
