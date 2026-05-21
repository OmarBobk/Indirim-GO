<?php

use App\Actions\Topups\ApproveTopupRequest;
use App\Actions\Topups\GetTopupRequests;
use App\Actions\Topups\RejectTopupRequest;
use App\Enums\TopupRequestStatus;
use App\Models\TopupProof;
use App\Models\TopupRequest;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\View\View;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;
use Masmerise\Toaster\Toastable;

new class extends Component
{
    use Toastable;
    use WithPagination;

    public string $statusFilter = 'all';
    public int $perPage = 10;

    public bool $showProofModal = false;
    public ?int $selectedTopupRequestId = null;
    public ?int $selectedProofId = null;

    public bool $showRejectModal = false;
    public ?int $selectedTopupRequestIdForReject = null;
    public string $rejectReason = '';

    public ?string $noticeMessage = null;
    public ?string $noticeVariant = null;

    public function mount(): void
    {
        abort_unless(auth()->user()?->can('manage_topups'), 403);
    }

    public function applyFilters(): void
    {
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset(['statusFilter', 'perPage']);
        $this->resetPage();
    }

    public function openProofModal(int $topupRequestId, int $proofId): void
    {
        $this->selectedTopupRequestId = $topupRequestId;
        $this->selectedProofId = $proofId;
        $this->showProofModal = true;
    }

    public function closeProofModal(): void
    {
        $this->reset(['showProofModal', 'selectedTopupRequestId', 'selectedProofId']);
    }

    public function approveFromModal(): void
    {
        if ($this->selectedTopupRequestId === null) {
            return;
        }

        $this->approveTopup($this->selectedTopupRequestId);
        $this->closeProofModal();
    }

    public function approveTopup(int $topupRequestId): void
    {
        $this->reset('noticeMessage', 'noticeVariant');

        $topupRequest = TopupRequest::query()->findOrFail($topupRequestId);

        if ($topupRequest->status !== TopupRequestStatus::Pending) {
            return;
        }

        app(ApproveTopupRequest::class)->handle($topupRequest, auth()->id());

        $this->noticeVariant = 'success';
        $this->noticeMessage = __('messages.topup_approved');
        $this->success(__('messages.topup_approved'));
    }

    public function openRejectModal(int $topupRequestId): void
    {
        $this->selectedTopupRequestIdForReject = $topupRequestId;
        $this->rejectReason = '';
        $this->showRejectModal = true;
    }

    public function closeRejectModal(): void
    {
        $this->reset(['showRejectModal', 'selectedTopupRequestIdForReject', 'rejectReason']);
    }

    public function confirmReject(): void
    {
        if ($this->selectedTopupRequestIdForReject === null) {
            return;
        }

        $this->reset('noticeMessage', 'noticeVariant');

        $topupRequest = TopupRequest::query()->findOrFail($this->selectedTopupRequestIdForReject);

        if ($topupRequest->status !== TopupRequestStatus::Pending) {
            $this->closeRejectModal();

            return;
        }

        app(RejectTopupRequest::class)->handle(
            $topupRequest,
            auth()->id(),
            $this->rejectReason !== '' ? $this->rejectReason : null
        );

        $this->closeRejectModal();
        $this->noticeVariant = 'danger';
        $this->noticeMessage = __('messages.topup_rejected');
        $this->error(__('messages.topup_rejected'));
    }

    #[On('topup-list-updated')]
    public function refreshTopupRequests(): void
    {
        $this->resetPage();
        $this->dispatch('$refresh');
    }

    public function getTopupRequestsProperty(): LengthAwarePaginator
    {
        return app(GetTopupRequests::class)->handle($this->statusFilter, $this->perPage);
    }

    public function getViewingProofProperty(): ?TopupProof
    {
        if ($this->selectedProofId === null) {
            return null;
        }

        return TopupProof::query()->find($this->selectedProofId);
    }

    public function render(): View
    {
        return $this->view()->title(__('messages.topup_requests'));
    }
};
?>

<div class="flex h-full w-full flex-1 flex-col gap-6">
    <section class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="space-y-1">
                <flux:heading size="lg" class="text-zinc-900 dark:text-zinc-100">
                    {{ __('messages.topup_requests') }}
                </flux:heading>
                <flux:text class="text-sm text-zinc-600 dark:text-zinc-400">
                    {{ __('messages.topup_requests_intro') }}
                </flux:text>
            </div>
        </div>

        @if ($noticeMessage)
            <div class="mt-4">
                <flux:callout
                    variant="subtle"
                    icon="{{ $noticeVariant === 'success' ? 'check-circle' : 'exclamation-triangle' }}"
                >
                    {{ $noticeMessage }}
                </flux:callout>
            </div>
        @endif

        <div class="mt-4 rounded-xl border border-zinc-100 bg-zinc-50 p-4 dark:border-zinc-800 dark:bg-zinc-800/60">
            <div class="flex flex-wrap items-end gap-4">
                <flux:select
                    name="statusFilter"
                    label="{{ __('messages.status') }}"
                    wire:model.defer="statusFilter"
                >
                    <flux:select.option value="pending">{{ __('messages.pending') }}</flux:select.option>
                    <flux:select.option value="approved">{{ __('messages.approved') }}</flux:select.option>
                    <flux:select.option value="rejected">{{ __('messages.rejected') }}</flux:select.option>
                    <flux:select.option value="all">{{ __('messages.all') }}</flux:select.option>
                </flux:select>

                <flux:select
                    name="perPage"
                    label="{{ __('messages.per_page') }}"
                    wire:model.defer="perPage"
                >
                    <flux:select.option value="10">10</flux:select.option>
                    <flux:select.option value="25">25</flux:select.option>
                    <flux:select.option value="50">50</flux:select.option>
                </flux:select>

                <div class="flex items-center gap-2">
                    <flux:button variant="primary" wire:click="applyFilters">
                        {{ __('messages.apply') }}
                    </flux:button>
                    <flux:button variant="outline" wire:click="resetFilters">
                        {{ __('messages.reset') }}
                    </flux:button>
                </div>
            </div>
        </div>

        <div class="mt-4 overflow-hidden rounded-2xl border border-zinc-100 bg-white dark:border-zinc-800 dark:bg-zinc-900">
            <div class="overflow-x-auto">
                @if ($this->topupRequests->isEmpty())
                    <div class="flex flex-col items-center justify-center gap-2 px-6 py-16 text-center">
                        <flux:heading size="sm" class="text-zinc-900 dark:text-zinc-100">
                            {{ __('messages.no_topups_yet') }}
                        </flux:heading>
                        <flux:text class="text-zinc-600 dark:text-zinc-400">
                            {{ __('messages.no_topups_hint') }}
                        </flux:text>
                    </div>
                @else
                    <table class="min-w-full divide-y divide-zinc-100 text-sm dark:divide-zinc-800" data-test="topups-table">
                        <thead class="bg-zinc-50 text-xs uppercase tracking-wide text-zinc-500 dark:bg-zinc-800/60 dark:text-zinc-400">
                            <tr>
                                <th class="px-5 py-3 text-start font-semibold">{{ __('messages.user') }}</th>
                                <th class="px-5 py-3 text-start font-semibold">{{ __('messages.amount') }}</th>
                                <th class="px-5 py-3 text-start font-semibold">{{ __('messages.method') }}</th>
                                <th class="px-5 py-3 text-start font-semibold">{{ __('messages.created') }}</th>
                                <th class="px-5 py-3 text-start font-semibold">{{ __('messages.status') }}</th>
                                <th class="px-5 py-3 text-end font-semibold">{{ __('messages.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                            @foreach ($this->topupRequests as $topupRequest)
                                <tr class="transition hover:bg-zinc-50 dark:hover:bg-zinc-800/60" wire:key="topup-{{ $topupRequest->id }}">
                                    <td class="px-5 py-4">
                                        <div class="min-w-0">
                                            <div class="truncate font-semibold text-zinc-900 dark:text-zinc-100">
                                                {{ $topupRequest->user?->name ?? __('messages.unknown_user') }}
                                            </div>
                                            <div class="truncate text-xs text-zinc-500 dark:text-zinc-400">
                                                {{ $topupRequest->user?->email ?? '—' }}
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-5 py-4 text-zinc-700 dark:text-zinc-200">
                                        {{ $topupRequest->amount }} {{ $topupRequest->currency }}
                                    </td>
                                    <td class="px-5 py-4 text-zinc-600 dark:text-zinc-300">
                                        {{ $topupRequest->paymentMethod?->name ?? '—' }}
                                    </td>
                                    <td class="px-5 py-4 text-zinc-600 dark:text-zinc-300">
                                        {{ $topupRequest->created_at?->format('M d, Y') ?? '—' }}
                                    </td>
                                    <td class="px-5 py-4">
                                        @php
                                            $statusColor = match ($topupRequest->status) {
                                                TopupRequestStatus::Approved => 'green',
                                                TopupRequestStatus::Rejected => 'red',
                                                default => 'amber',
                                            };
                                        @endphp
                                        <flux:badge color="{{ $statusColor }}">
                                            {{ __('messages.'.$topupRequest->status->value) }}
                                        </flux:badge>
                                    </td>
                                    <td class="px-5 py-4 text-end">
                                        <div class="flex flex-wrap items-center justify-end gap-2">
                                            @if ($topupRequest->status === TopupRequestStatus::Pending)
                                                <flux:button
                                                    size="sm"
                                                    variant="primary"
                                                    wire:click="approveTopup({{ $topupRequest->id }})"
                                                >
                                                    {{ __('messages.approve') }}
                                                </flux:button>
                                                @if ($topupRequest->proofs->isNotEmpty())
                                                    <flux:button
                                                        size="sm"
                                                        variant="ghost"
                                                        wire:click="openProofModal({{ $topupRequest->id }}, {{ $topupRequest->proofs->first()->id }})"
                                                    >
                                                        {{ __('messages.view') }}
                                                    </flux:button>
                                                @endif
                                                <flux:button
                                                    size="sm"
                                                    variant="danger"
                                                    wire:click="openRejectModal({{ $topupRequest->id }})"
                                                >
                                                    {{ __('messages.reject') }}
                                                </flux:button>
                                            @elseif ($topupRequest->proofs->isNotEmpty())
                                                <flux:button
                                                    as="a"
                                                    href="{{ route('topup-proofs.show', $topupRequest->proofs->first()) }}"
                                                    variant="ghost"
                                                    size="sm"
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                >
                                                    {{ __('messages.view_proof') }}
                                                </flux:button>
                                            @else
                                                <span class="text-xs text-zinc-500 dark:text-zinc-400">—</span>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>

            <div class="border-t border-zinc-100 px-5 py-4 dark:border-zinc-800">
                {{ $this->topupRequests->links() }}
            </div>
        </div>
    </section>

    <flux:modal
        wire:model.self="showProofModal"
        variant="floating"
        class="max-w-4xl pt-14"
        @close="closeProofModal"
        @cancel="closeProofModal"
    >
        @if ($this->viewingProof)
            @php
                $proofUrl = route('topup-proofs.show', $this->viewingProof);
                $isImage = $this->viewingProof->mime_type && str_starts_with($this->viewingProof->mime_type, 'image/');
            @endphp
            <div class="space-y-4">
                <flux:heading size="lg" class="text-zinc-900 dark:text-zinc-100">
                    {{ __('messages.view_proof') }}
                </flux:heading>

                <div class="min-h-[280px] overflow-hidden rounded-xl border border-zinc-200 bg-zinc-100 dark:border-zinc-700 dark:bg-zinc-800/60">
                    @if ($isImage)
                        <div
                            class="flex size-full min-h-[280px] items-center justify-center overflow-auto p-4"
                            x-data="{ scale: 1 }"
                        >
                            <div class="flex flex-col items-center gap-3">
                                <img
                                    :style="`transform: scale(${scale}); transform-origin: center;`"
                                    src="{{ $proofUrl }}"
                                    alt="{{ __('messages.view_proof') }}"
                                    class="max-h-[60vh] w-auto max-w-full object-contain"
                                />
                                <div class="flex items-center gap-2">
                                    <flux:button
                                        size="sm"
                                        variant="outline"
                                        type="button"
                                        @click="scale = Math.max(0.25, scale - 0.25)"
                                    >
                                        −
                                    </flux:button>
                                    <span class="min-w-[4rem] text-center text-sm text-zinc-600 dark:text-zinc-400" x-text="Math.round(scale * 100) + '%'"></span>
                                    <flux:button
                                        size="sm"
                                        variant="outline"
                                        type="button"
                                        @click="scale = Math.min(3, scale + 0.25)"
                                    >
                                        +
                                    </flux:button>
                                </div>
                            </div>
                        </div>
                    @else
                        <iframe
                            src="{{ $proofUrl }}"
                            class="size-full min-h-[60vh] w-full border-0"
                            title="{{ __('messages.view_proof') }}"
                        ></iframe>
                    @endif
                </div>

                <div class="flex flex-wrap items-center justify-end gap-2">
                    <flux:button variant="ghost" wire:click="closeProofModal">
                        {{ __('messages.close') }}
                    </flux:button>
                    <flux:button variant="primary" wire:click="approveFromModal">
                        {{ __('messages.approve') }}
                    </flux:button>
                </div>
            </div>
        @endif
    </flux:modal>

    <flux:modal
        wire:model.self="showRejectModal"
        variant="floating"
        class="max-w-xl"
        @close="closeRejectModal"
        @cancel="closeRejectModal"
    >
        <div class="space-y-4">
            <flux:heading size="lg" class="text-zinc-900 dark:text-zinc-100">
                {{ __('messages.reject_topup') }}
            </flux:heading>
            <flux:text class="text-sm text-zinc-600 dark:text-zinc-400">
                {{ __('messages.reject_topup_reason_hint') }}
            </flux:text>
            <flux:textarea
                name="rejectReason"
                :label="__('messages.rejection_reason')"
                rows="3"
                wire:model.defer="rejectReason"
                :placeholder="__('messages.rejection_reason_placeholder')"
            />
            <div class="flex flex-wrap items-center justify-end gap-2">
                <flux:button variant="ghost" wire:click="closeRejectModal">
                    {{ __('messages.close') }}
                </flux:button>
                <flux:button variant="danger" wire:click="confirmReject">
                    {{ __('messages.reject') }}
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>
