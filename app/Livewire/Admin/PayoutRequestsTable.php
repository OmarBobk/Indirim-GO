<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Actions\Commissions\MarkPayoutRequestProcessed;
use App\Enums\PayoutRequestStatus;
use App\Models\PayoutRequest;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;
use Masmerise\Toaster\Toastable;

#[Layout('layouts.app')]
final class PayoutRequestsTable extends Component
{
    use Toastable;
    use WithPagination;

    /** pending | all — default shows pending and processed together */
    public string $statusFilter = 'all';

    public function mount(): void
    {
        abort_unless(auth()->user()?->can('manage_settlements'), 403);
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function markProcessed(int $id): void
    {
        abort_unless(auth()->user()?->can('manage_settlements'), 403);
        $user = auth()->user();
        abort_unless($user !== null, 403);

        $request = PayoutRequest::query()->findOrFail($id);
        app(MarkPayoutRequestProcessed::class)->handle($request, $user);

        $this->success(__('messages.payout_request_marked_processed'));
    }

    public function render(): View
    {
        $query = PayoutRequest::query()
            ->with(['user:id,name,email', 'processedByUser:id,name']);

        if ($this->statusFilter === 'pending') {
            $query->where('status', PayoutRequestStatus::Pending);
        } else {
            $query->orderByRaw('CASE WHEN status = ? THEN 0 ELSE 1 END', [PayoutRequestStatus::Pending->value]);
        }

        $query->orderByDesc('id');

        /** @var LengthAwarePaginator<int, PayoutRequest> $requests */
        $requests = $query->paginate(25);

        return view('livewire.admin.payout-requests-table', [
            'requests' => $requests,
        ])->title(__('messages.payout_requests'));
    }
}
