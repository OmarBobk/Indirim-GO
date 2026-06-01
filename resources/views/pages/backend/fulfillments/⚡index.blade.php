<?php

use App\Actions\Fulfillments\CancelFulfillmentAutomationRun;
use App\Actions\Fulfillments\CompleteFulfillment;
use App\Actions\Fulfillments\ClaimFulfillment;
use App\Actions\Fulfillments\FailFulfillment;
use App\Actions\Fulfillments\GetFulfillments;
use App\Actions\Fulfillments\RetryFulfillment;
use App\Actions\Fulfillments\StartFulfillment;
use App\Jobs\DispatchFulfillmentAutomationJob;
use App\Models\FulfillmentAutomationRun;
use App\Services\FulfillmentAutomationService;
use App\Actions\Orders\RefundOrderItem;
use App\Actions\Refunds\ApproveRefundRequest;
use App\Enums\FulfillmentStatus;
use App\Enums\ProductAmountMode;
use App\Enums\WalletTransactionType;
use App\Models\Fulfillment;
use App\Models\Order;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;
use Masmerise\Toaster\Toastable;

new class extends Component
{
    use Toastable;
    use WithPagination;

    #[Url]
    public string $search = '';
    #[Url]
    public string $customerSearch = '';
    #[Url]
    public string $orderSearch = '';
    public string $statusFilter = 'all';
    public int $perPage = 10;
    #[Url]
    public string $scope = 'unclaimed';

    #[Url]
    public ?int $fulfillment = null;

    public ?int $selectedFulfillmentId = null;
    public bool $showDetailsModal = false;
    public bool $showCompleteModal = false;
    public bool $showFailModal = false;

    public ?string $deliveredPayloadInput = null;
    public ?string $failureReason = null;
    public bool $refundAfterFail = false;
    public bool $autoDonePayload = false;
    public ?int $adminTableSupervisorId = null;
    public ?string $adminTableStatusFilter = null;
    public int|string|null $userFilterId = null;
    public array $hiddenQueueFulfillmentIds = [];
    public array $hiddenMyFulfillmentIds = [];
    public array $prependedQueueFulfillmentIds = [];
    public array $prependedMyFulfillmentIds = [];

    public function mount(): void
    {
        $this->authorize('viewAny', Fulfillment::class);
        $this->openDetailsFromFulfillmentQuery();
    }

    public function updatedFulfillment(?int $value): void
    {
        if ($value !== null) {
            $this->openDetailsFromFulfillmentQuery();
        }
    }

    public function applyFilters(): void
    {
        $this->hiddenQueueFulfillmentIds = [];
        $this->hiddenMyFulfillmentIds = [];
        $this->prependedQueueFulfillmentIds = [];
        $this->prependedMyFulfillmentIds = [];
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset(['search', 'customerSearch', 'orderSearch', 'statusFilter', 'perPage']);
        $this->adminTableSupervisorId = null;
        $this->adminTableStatusFilter = null;
        $this->userFilterId = null;
        $this->hiddenQueueFulfillmentIds = [];
        $this->hiddenMyFulfillmentIds = [];
        $this->prependedQueueFulfillmentIds = [];
        $this->prependedMyFulfillmentIds = [];
        $this->resetPage();
    }

    public function openAdminTableForSupervisorStatus(int $supervisorId, string $status): void
    {
        if (! $this->isAdmin) {
            return;
        }

        $resolvedStatus = FulfillmentStatus::tryFrom($status);

        if ($resolvedStatus === null || ! in_array($resolvedStatus, [FulfillmentStatus::Completed, FulfillmentStatus::Failed], true)) {
            return;
        }

        $this->adminTableSupervisorId = $supervisorId;
        $this->adminTableStatusFilter = $resolvedStatus->value;
        $this->userFilterId = $supervisorId;
        $this->resetPage();
        $this->dispatch('open-admin-table-tab');
    }

    public function updatedUserFilterId(mixed $value): void
    {
        $this->userFilterId = filled($value) ? (int) $value : null;
        $this->resetPage();
    }

    public function updatedCustomerSearch(): void
    {
        $this->resetPage();
    }

    public function updatedOrderSearch(): void
    {
        $this->resetPage();
    }

    /**
     * Order number plus fulfillment id so multiple fulfillments for the same order stay distinct in supervisor UI.
     */
    public function fulfillmentOrderReference(Fulfillment $fulfillment): string
    {
        $base = $fulfillment->order?->order_number ?? ('#'.$fulfillment->order_id);

        return $base.'F'.$fulfillment->id;
    }

    public function openDetails(int $fulfillmentId): void
    {
        $fulfillment = Fulfillment::query()->findOrFail($fulfillmentId);
        $this->authorize('view', $fulfillment);
        $this->selectedFulfillmentId = $fulfillmentId;
        $this->showDetailsModal = true;
    }

    public function closeDetails(): void
    {
        $this->fulfillment = null;
        $this->reset(['showDetailsModal', 'selectedFulfillmentId']);
    }

    private function openDetailsFromFulfillmentQuery(): void
    {
        if ($this->fulfillment === null) {
            return;
        }

        $fulfillment = Fulfillment::query()->find($this->fulfillment);

        if ($fulfillment === null) {
            $this->fulfillment = null;

            return;
        }

        try {
            $this->authorize('view', $fulfillment);
        } catch (\Illuminate\Auth\Access\AuthorizationException) {
            $this->fulfillment = null;

            return;
        }

        $this->selectedFulfillmentId = $fulfillment->id;
        $this->showDetailsModal = true;
    }

    public function markProcessing(int $fulfillmentId): void
    {
        $fulfillment = Fulfillment::query()->findOrFail($fulfillmentId);
        $this->authorize('update', $fulfillment);
        app(StartFulfillment::class)->handle($fulfillment, 'admin', auth()->id(), ['source' => 'admin']);

        $this->success(__('messages.fulfillment_marked_processing'));
    }

    public function claimFulfillment(int $fulfillmentId): void
    {
        $fulfillment = Fulfillment::query()->findOrFail($fulfillmentId);
        $this->authorize('claim', $fulfillment);

        try {
            app(ClaimFulfillment::class)->handle($fulfillment, (int) auth()->id());
            $this->hiddenQueueFulfillmentIds[$fulfillmentId] = $fulfillmentId;
            $this->prependedMyFulfillmentIds[$fulfillmentId] = $fulfillmentId;
            $this->success(__('messages.fulfillment_claimed'));
        } catch (ValidationException $exception) {
            $this->error($exception->validator->errors()->first() ?: __('messages.fulfillment_claim_failed'));
        }
    }

    public function openCompleteModal(int $fulfillmentId): void
    {
        $this->reset('deliveredPayloadInput', 'autoDonePayload');
        $this->selectedFulfillmentId = $fulfillmentId;
        $this->showCompleteModal = true;
    }

    public function openForceCompleteModal(int $fulfillmentId): void
    {
        $this->openCompleteModal($fulfillmentId);
    }

    public function completeFulfillment(): void
    {
        $fulfillment = $this->selectedFulfillment;

        if ($fulfillment === null) {
            return;
        }

        $this->authorize('update', $fulfillment);

        if ($this->autoDonePayload && blank($this->deliveredPayloadInput)) {
            $this->deliveredPayloadInput = 'DONE';
        }

        app(CompleteFulfillment::class)->handle(
            $fulfillment,
            $this->parseDeliveredPayload(),
            'admin',
            auth()->id()
        );

        $this->reset('showCompleteModal', 'deliveredPayloadInput', 'autoDonePayload');
        $this->success(__('messages.fulfillment_marked_completed'));
    }

    public function updatedAutoDonePayload(bool $value): void
    {
        if ($value) {
            $this->deliveredPayloadInput = 'DONE';
            return;
        }

        if (trim((string) $this->deliveredPayloadInput) === 'DONE') {
            $this->deliveredPayloadInput = null;
        }
    }

    public function openFailModal(int $fulfillmentId): void
    {
        $this->reset('failureReason', 'refundAfterFail');
        $this->selectedFulfillmentId = $fulfillmentId;
        $this->showFailModal = true;
    }

    public function openForceFailModal(int $fulfillmentId): void
    {
        $this->openFailModal($fulfillmentId);
    }

    public function failFulfillment(): void
    {
        $this->validate([
            'failureReason' => ['required', 'string', 'max:500'],
        ]);

        $fulfillment = $this->selectedFulfillment;

        if ($fulfillment === null) {
            return;
        }

        $this->authorize('update', $fulfillment);

        $requestedWalletRefund = $this->refundAfterFail;

        if ($requestedWalletRefund) {
            $this->authorize('process_refunds');
        }

        if ($requestedWalletRefund) {
            DB::transaction(function () use ($fulfillment): void {
                $updated = app(FailFulfillment::class)->handle(
                    $fulfillment,
                    $this->failureReason ?? '',
                    'admin',
                    auth()->id()
                );

                $updated->loadMissing('orderItem');

                if ($updated->orderItem === null) {
                    throw ValidationException::withMessages([
                        'refundAfterFail' => __('messages.fulfillment_refund_requires_order_item'),
                    ]);
                }

                $actorId = auth()->id();
                if ($actorId === null) {
                    throw ValidationException::withMessages([
                        'refundAfterFail' => __('messages.refund_not_allowed'),
                    ]);
                }

                $transaction = app(RefundOrderItem::class)->handle($updated, (int) $actorId);
                app(ApproveRefundRequest::class)->handle($transaction->id, (int) $actorId);
            });
        } else {
            app(FailFulfillment::class)->handle(
                $fulfillment,
                $this->failureReason ?? '',
                'admin',
                auth()->id()
            );
        }

        $this->reset('showFailModal', 'failureReason', 'refundAfterFail');

        if ($requestedWalletRefund) {
            $this->success(__('messages.fulfillment_failed_refunded'));

            return;
        }

        $this->success(__('messages.fulfillment_marked_failed'));
    }

    public function retryFulfillment(int $fulfillmentId): void
    {
        $fulfillment = Fulfillment::query()->findOrFail($fulfillmentId);
        $this->authorize('update', $fulfillment);
        app(RetryFulfillment::class)->handle($fulfillment, 'admin', auth()->id());

        $this->success(__('messages.fulfillment_marked_queued'));
    }

    public function retryAutomation(int $fulfillmentId): void
    {
        $fulfillment = Fulfillment::query()->findOrFail($fulfillmentId);

        if (! $fulfillment->isBrowserAutomated()) {
            return;
        }

        $this->authorize('update', $fulfillment);

        if ($fulfillment->status === FulfillmentStatus::Failed) {
            app(RetryFulfillment::class)->handle($fulfillment, 'admin', auth()->id());
            $fulfillment = $fulfillment->refresh();
        } else {
            app(CancelFulfillmentAutomationRun::class)->handle($fulfillment, 'manual_retry');
        }

        if (app(FulfillmentAutomationService::class)->isEligible($fulfillment->refresh())) {
            DispatchFulfillmentAutomationJob::dispatch($fulfillment->id);
        }

        $this->success(__('messages.fulfillment_automation_retry_queued'));
    }

    #[On('fulfillment-list-updated')]
    public function refreshFulfillments(array $payload = []): void
    {
        if ($this->getPage() !== 1) {
            return;
        }

        $fulfillmentId = (int) ($payload['fulfillment_id'] ?? 0);
        $type = (string) ($payload['type'] ?? '');

        if ($fulfillmentId <= 0 || ! in_array($type, ['created', 'claimed', 'processing', 'completed', 'failed'], true)) {
            return;
        }

        if ($type === 'created') {
            $this->prependedQueueFulfillmentIds[$fulfillmentId] = $fulfillmentId;
            return;
        }

        if ($type === 'claimed') {
            $this->hiddenQueueFulfillmentIds[$fulfillmentId] = $fulfillmentId;
            unset($this->prependedQueueFulfillmentIds[$fulfillmentId]);
            $claimedFulfillment = Fulfillment::query()
                ->select(['id', 'claimed_by'])
                ->whereKey($fulfillmentId)
                ->first();
            if ($this->isAdmin || $claimedFulfillment?->claimed_by === auth()->id()) {
                $this->prependedMyFulfillmentIds[$fulfillmentId] = $fulfillmentId;
            }
            return;
        }

        if (in_array($type, ['completed', 'failed'], true)) {
            $this->hiddenMyFulfillmentIds[$fulfillmentId] = $fulfillmentId;
            unset($this->prependedMyFulfillmentIds[$fulfillmentId]);
        }
    }

    public function getQueuePageProperty(): LengthAwarePaginator
    {
        return app(GetFulfillments::class)->handle(
            $this->search,
            $this->statusFilter,
            $this->perPage,
            'unclaimed',
            auth()->id(),
            $this->isAdmin
        );
    }

    public function getMyPageProperty(): LengthAwarePaginator
    {
        if ($this->isAdmin) {
            return app(GetFulfillments::class)->handle(
                $this->search,
                FulfillmentStatus::Processing->value,
                $this->perPage,
                'all',
                auth()->id(),
                true
            );
        }

        return app(GetFulfillments::class)->handle(
            $this->search,
            $this->statusFilter,
            $this->perPage,
            'mine',
            auth()->id(),
            $this->isAdmin
        );
    }

    public function getAllPageProperty(): LengthAwarePaginator
    {
        return app(GetFulfillments::class)->handle(
            $this->search,
            $this->adminTableStatusFilter ?? $this->statusFilter,
            $this->perPage,
            'all',
            auth()->id(),
            $this->isAdmin,
            $this->selectedUserFilterId(),
            null,
            $this->customerSearch,
            $this->orderSearch
        );
    }

    public function getSupervisorFilterUsersProperty(): Collection
    {
        return User::query()
            ->where(function ($query): void {
                $query
                    ->permission('manage_fulfillments')
                    ->orWhereHas('roles', fn ($roleQuery) => $roleQuery->where('name', 'admin'));
            })
            ->select(['id', 'name', 'username'])
            ->orderBy('name')
            ->get();
    }

    public function getQueueCardsProperty(): Collection
    {
        $collection = $this->queuePage->getCollection()
            ->reject(fn ($fulfillment) => in_array($fulfillment->id, $this->hiddenQueueFulfillmentIds, true))
            ->values();

        if ($this->prependedQueueFulfillmentIds === []) {
            return $collection;
        }

        $alreadyPresent = $collection->pluck('id')->all();
        $prependIds = collect($this->prependedQueueFulfillmentIds)
            ->diff($alreadyPresent)
            ->values()
            ->all();

        if ($prependIds === []) {
            return $collection;
        }

        $prepended = Fulfillment::query()
            ->whereIn('id', $prependIds)
            ->where('status', FulfillmentStatus::Queued)
            ->whereNull('claimed_by')
            ->with([
                'order:id,user_id,order_number,total,currency',
                'order.user:id,name,email,username',
                'orderItem:id,order_id,product_id,name,requirements_payload',
                'orderItem.product:id,name',
            ])
            ->orderByDesc('created_at')
            ->get();

        return $prepended->concat($collection)->unique('id')->values();
    }

    public function getMyCardsProperty(): Collection
    {
        $collection = $this->myPage->getCollection()
            ->reject(fn ($fulfillment) => in_array($fulfillment->id, $this->hiddenMyFulfillmentIds, true))
            ->values();

        if ($this->prependedMyFulfillmentIds === []) {
            return $collection;
        }

        $alreadyPresent = $collection->pluck('id')->all();
        $prependIds = collect($this->prependedMyFulfillmentIds)
            ->diff($alreadyPresent)
            ->values()
            ->all();

        if ($prependIds === []) {
            return $collection;
        }

        $prepended = Fulfillment::query()
            ->whereIn('id', $prependIds)
            ->where('claimed_by', auth()->id())
            ->with([
                'order:id,user_id,order_number,total,currency',
                'order.user:id,name,email,username',
                'orderItem:id,order_id,product_id,package_id,name,quantity,requirements_payload',
                'orderItem.product:id,name,slug',
                'claimer:id,username,name',
            ])
            ->orderByDesc('created_at')
            ->get();

        return $prepended->concat($collection)->unique('id')->values();
    }

    public function getActiveClaimedCountProperty(): int
    {
        if ($this->isAdmin) {
            return Fulfillment::query()
                ->where('status', FulfillmentStatus::Processing)
                ->count();
        }

        return Fulfillment::query()
            ->where('claimed_by', auth()->id())
            ->where('status', FulfillmentStatus::Processing)
            ->count();
    }

    public function getIsAdminProperty(): bool
    {
        return auth()->user()?->hasRole('admin') === true;
    }

    public function getSystemOverviewProperty(): array
    {
        $queued = Fulfillment::query()
            ->where('status', FulfillmentStatus::Queued)
            ->count();
        $processing = Fulfillment::query()
            ->where('status', FulfillmentStatus::Processing)
            ->count();
        $completed = Fulfillment::query()
            ->where('status', FulfillmentStatus::Completed)
            ->count();
        $activeSupervisors = Fulfillment::query()
            ->where('status', FulfillmentStatus::Processing)
            ->whereNotNull('claimed_by')
            ->distinct('claimed_by')
            ->count('claimed_by');

        $load = $processing >= 20 ? 'high' : ($processing >= 8 ? 'medium' : 'normal');

        return [
            'queued' => $queued,
            'processing' => $processing,
            'completed' => $completed,
            'active_supervisors' => $activeSupervisors,
            'load' => $load,
        ];
    }

    public function getSupervisorHealthProperty(): Collection
    {
        $supervisors = User::query()
            ->where(function ($query): void {
                $query
                    ->permission('manage_fulfillments')
                    ->orWhereHas('roles', fn ($roleQuery) => $roleQuery->where('name', 'admin'));
            })
            ->select(['id', 'name', 'username'])
            ->get();

        if ($supervisors->isEmpty()) {
            return collect();
        }

        $activeCounts = Fulfillment::query()
            ->selectRaw('claimed_by, COUNT(*) as active_tasks')
            ->where('status', FulfillmentStatus::Processing)
            ->whereIn('claimed_by', $supervisors->pluck('id')->all())
            ->groupBy('claimed_by')
            ->pluck('active_tasks', 'claimed_by');

        $completedCounts = Fulfillment::query()
            ->selectRaw('claimed_by, COUNT(*) as completed_tasks')
            ->where('status', FulfillmentStatus::Completed)
            ->whereIn('claimed_by', $supervisors->pluck('id')->all())
            ->groupBy('claimed_by')
            ->pluck('completed_tasks', 'claimed_by');

        $failedCounts = Fulfillment::query()
            ->selectRaw('claimed_by, COUNT(*) as failed_tasks')
            ->where('status', FulfillmentStatus::Failed)
            ->whereIn('claimed_by', $supervisors->pluck('id')->all())
            ->groupBy('claimed_by')
            ->pluck('failed_tasks', 'claimed_by');

        $lastActivity = Fulfillment::query()
            ->selectRaw('claimed_by, MAX(updated_at) as last_activity_at')
            ->whereIn('claimed_by', $supervisors->pluck('id')->all())
            ->groupBy('claimed_by')
            ->pluck('last_activity_at', 'claimed_by');

        return $supervisors
            ->map(function (User $supervisor) use ($activeCounts, $completedCounts, $failedCounts, $lastActivity): array {
                $activeTasks = (int) ($activeCounts[$supervisor->id] ?? 0);
                $status = $activeTasks >= 5 ? 'overloaded' : ($activeTasks >= 4 ? 'busy' : ($activeTasks > 0 ? 'active' : 'idle'));

                return [
                    'id' => $supervisor->id,
                    'name' => $supervisor->name ?: $supervisor->username ?: ('#'.$supervisor->id),
                    'active_tasks' => $activeTasks,
                    'completed_tasks' => (int) ($completedCounts[$supervisor->id] ?? 0),
                    'failed_tasks' => (int) ($failedCounts[$supervisor->id] ?? 0),
                    'status' => $status,
                    'last_activity_at' => $lastActivity[$supervisor->id] ?? null,
                ];
            })
            ->sortByDesc('active_tasks')
            ->values();
    }

    public function getDistributionBucketsProperty(): Collection
    {
        return Fulfillment::query()
            ->where('status', FulfillmentStatus::Processing)
            ->with([
                'claimer:id,name,username',
                'order:user_id,id,order_number,total,currency',
                'orderItem:id,order_id,name',
            ])
            ->latest('updated_at')
            ->get()
            ->groupBy('claimed_by')
            ->map(function (Collection $items): array {
                $first = $items->first();
                $claimerName = $first?->claimer?->name ?: $first?->claimer?->username ?: __('messages.unknown_user');

                return [
                    'claimer_name' => $claimerName,
                    'count' => $items->count(),
                    'items' => $items->take(8),
                ];
            })
            ->sortByDesc('count')
            ->values();
    }

    public function getAdminAlertsProperty(): Collection
    {
        if (! $this->isAdmin) {
            return collect();
        }

        $alerts = collect();
        $queued = (int) $this->systemOverview['queued'];
        $processing = (int) $this->systemOverview['processing'];
        $activeSupervisors = (int) $this->systemOverview['active_supervisors'];
        $overloadedSupervisors = $this->supervisorHealth
            ->where('status', 'overloaded')
            ->count();

        if ($queued >= 25 && $processing <= 5) {
            $alerts->push([
                'level' => 'amber',
                'title' => 'Queue backlog rising',
                'message' => 'Queue is growing faster than processing throughput. Check assignments and blockers.',
            ]);
        }

        if ($overloadedSupervisors > 0) {
            $alerts->push([
                'level' => 'red',
                'title' => 'Supervisor overload detected',
                'message' => $overloadedSupervisors.' supervisor(s) are at 5/5 active tasks.',
            ]);
        }

        if ($activeSupervisors === 0 && $queued > 0) {
            $alerts->push([
                'level' => 'zinc',
                'title' => 'No active supervisors',
                'message' => 'Tasks are queued but no supervisor is currently processing.',
            ]);
        }

        return $alerts->take(3);
    }

    public function getQueueCountProperty(): int
    {
        return Fulfillment::query()
            ->where('status', FulfillmentStatus::Queued)
            ->whereNull('claimed_by')
            ->count();
    }

    public function getAverageProcessingMinutesProperty(): ?int
    {
        $completed = Fulfillment::query()
            ->select(['processed_at', 'completed_at'])
            ->whereNotNull('processed_at')
            ->whereNotNull('completed_at')
            ->latest('completed_at')
            ->limit(50)
            ->get();

        if ($completed->isEmpty()) {
            return null;
        }

        $seconds = $completed
            ->map(fn (Fulfillment $fulfillment): int => max(
                0,
                (int) $fulfillment->completed_at?->diffInSeconds($fulfillment->processed_at, false)
            ))
            ->sum();

        return (int) round(($seconds / max(1, $completed->count())) / 60);
    }

    public function getCanClaimMoreProperty(): bool
    {
        if (auth()->user()?->hasRole('admin')) {
            return true;
        }

        return $this->activeClaimedCount < 5;
    }

    public function getSelectedFulfillmentProperty(): ?Fulfillment
    {
        if ($this->selectedFulfillmentId === null) {
            return null;
        }

        return Fulfillment::query()
            ->with([
                'order.user:id,username',
                'orderItem.product:id,name,slug',
                'logs' => fn ($query) => $query->latest('created_at'),
                'automationRuns' => fn ($query) => $query->latest('id')->limit(3),
            ])
            ->find($this->selectedFulfillmentId);
    }

    public function getLatestAutomationRunProperty(): ?FulfillmentAutomationRun
    {
        return $this->selectedFulfillment?->automationRuns?->first();
    }

    public function getPaymentTransactionProperty(): ?WalletTransaction
    {
        $order = $this->selectedFulfillment?->order;

        if ($order === null) {
            return null;
        }

        return WalletTransaction::query()
            ->where('reference_type', Order::class)
            ->where('reference_id', $order->id)
            ->where('type', WalletTransactionType::Purchase->value)
            ->latest('created_at')
            ->first();
    }

    /**
     * @return array<string, string>
     */
    public function getStatusOptionsProperty(): array
    {
        return [
            'all' => __('messages.all'),
            FulfillmentStatus::Queued->value => __('messages.fulfillment_status_queued'),
            FulfillmentStatus::Processing->value => __('messages.fulfillment_status_processing'),
            FulfillmentStatus::Completed->value => __('messages.fulfillment_status_completed'),
            FulfillmentStatus::Failed->value => __('messages.fulfillment_status_failed'),
        ];
    }

    public function render(): View
    {
        return $this->view()->title(__('messages.fulfillments'));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function parseDeliveredPayload(): ?array
    {
        $input = trim((string) $this->deliveredPayloadInput);

        if ($input === '') {
            return null;
        }

        $decoded = json_decode($input, true);

        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $decoded;
        }

        return ['code' => $input];
    }

    private function requirementsToken(mixed $payload): ?string
    {
        if (blank($payload)) {
            return null;
        }

        if (is_array($payload)) {
            $id = data_get($payload, 'id');

            if (filled($id)) {
                return 'id: '.$id;
            }

            $firstScalar = collect($payload)->first(fn (mixed $value): bool => is_scalar($value) && filled($value));

            return filled($firstScalar) ? (string) $firstScalar : null;
        }

        if (is_string($payload)) {
            $decoded = json_decode($payload, true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $decodedId = data_get($decoded, 'id');

                if (filled($decodedId)) {
                    return 'id: '.$decodedId;
                }
            }

            return filled(trim($payload)) ? Str::limit(trim($payload), 36) : null;
        }

        return null;
    }

    private function selectedUserFilterId(): ?int
    {
        if (filled($this->userFilterId)) {
            return (int) $this->userFilterId;
        }

        return $this->adminTableSupervisorId;
    }

    protected function statusBadgeColor(FulfillmentStatus $status): string
    {
        return match ($status) {
            FulfillmentStatus::Queued => 'gray',
            FulfillmentStatus::Processing => 'cyan',
            FulfillmentStatus::Completed => 'green',
            FulfillmentStatus::Failed => 'red',
            default => 'gray',
        };
    }
};
?>

<div
    class="flex h-full w-full flex-1 flex-col gap-4"
    x-data="{
        adminTab: 'queue',
        setAdminTab(tab) { this.adminTab = tab; },
        adminTabs: ['queue', 'distribution', 'table'],
        tabButtonClass(tab) {
            return this.adminTab === tab
                ? '!bg-zinc-900 !text-white dark:!bg-zinc-200 dark:!text-zinc-900'
                : '';
        },
        moveTab(step) {
            const currentIndex = this.adminTabs.indexOf(this.adminTab);
            const nextIndex = (currentIndex + step + this.adminTabs.length) % this.adminTabs.length;
            this.adminTab = this.adminTabs[nextIndex];
        },
        firstTab() { this.adminTab = this.adminTabs[0]; },
        lastTab() { this.adminTab = this.adminTabs[this.adminTabs.length - 1]; },
        nowTs: Date.now(),
        queueCollapsed: false,
        processingCollapsed: false,
        init() {
            window.addEventListener('open-admin-table-tab', () => {
                this.adminTab = 'table';
            });
        },
        startTicker() {
            setInterval(() => { this.nowTs = Date.now(); }, 60000);
        },
        relativeTime(epochMs) {
            const diff = Math.max(0, Math.floor((this.nowTs - epochMs) / 1000));
            if (diff < 60) return 'just now';
            const minutes = Math.floor(diff / 60);
            if (minutes < 60) return `${minutes} min ago`;
            const hours = Math.floor(minutes / 60);
            if (hours < 24) return `${hours} hr ago`;
            const days = Math.floor(hours / 24);
            return `${days} day ago`;
        }
    }"
    x-init="init(); startTicker()"
>
    <section class="sticky top-0 z-20 rounded-2xl border border-zinc-200/80 bg-white/95 p-4 shadow-sm backdrop-blur-md dark:border-zinc-700 dark:bg-zinc-900/95">
        <div class="pointer-events-none absolute inset-x-0 top-0 h-24 bg-linear-to-r from-emerald-500/10 via-cyan-500/10 to-blue-500/10 dark:from-emerald-400/5 dark:via-cyan-400/5 dark:to-blue-400/5"></div>
        <div class="min-w-0 flex flex-wrap items-start justify-between gap-4">
            <div class="space-y-1">
                <flux:heading size="lg" class="text-zinc-900 dark:text-zinc-100">
                    {{ __('messages.fulfillments') }}
                </flux:heading>
                <flux:text class="text-sm text-zinc-600 dark:text-zinc-400">
                    {{ __('messages.fulfillments_intro') }}
                </flux:text>
            </div>
        </div>

        @if ($this->isAdmin)
            <div class="mt-4 grid gap-3 md:grid-cols-5">
                <div class="rounded-2xl border border-zinc-200 bg-white px-4 py-3 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                    <div class="text-[11px] uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Total queued</div>
                    <div class="mt-1 text-2xl font-semibold text-zinc-900 dark:text-zinc-100">{{ number_format($this->systemOverview['queued']) }}</div>
                </div>
                <div class="rounded-2xl border border-cyan-200 bg-cyan-50/80 px-4 py-3 shadow-sm dark:border-cyan-500/40 dark:bg-cyan-500/10">
                    <div class="text-[11px] uppercase tracking-wide text-cyan-700 dark:text-cyan-300">Total processing</div>
                    <div class="mt-1 text-2xl font-semibold text-cyan-800 dark:text-cyan-100">{{ number_format($this->systemOverview['processing']) }}</div>
                </div>
                <div class="rounded-2xl border border-emerald-200 bg-emerald-50/80 px-4 py-3 shadow-sm dark:border-emerald-500/40 dark:bg-emerald-500/10">
                    <div class="text-[11px] uppercase tracking-wide text-emerald-700 dark:text-emerald-300">Total completed</div>
                    <div class="mt-1 text-2xl font-semibold text-emerald-900 dark:text-emerald-100">{{ number_format($this->systemOverview['completed']) }}</div>
                </div>
                <div class="rounded-2xl border border-amber-200 bg-amber-50/80 px-4 py-3 shadow-sm dark:border-amber-500/40 dark:bg-amber-500/10">
                    <div class="text-[11px] uppercase tracking-wide text-amber-700 dark:text-amber-300">Active supervisors</div>
                    <div class="mt-1 text-2xl font-semibold text-amber-900 dark:text-amber-100">{{ number_format($this->systemOverview['active_supervisors']) }}</div>
                </div>
                <div class="rounded-2xl border border-violet-200 bg-violet-50/80 px-4 py-3 shadow-sm dark:border-violet-500/40 dark:bg-violet-500/10">
                    <div class="text-[11px] uppercase tracking-wide text-violet-700 dark:text-violet-300">System load</div>
                    <div class="mt-1 text-sm font-semibold uppercase tracking-wide text-violet-900 dark:text-violet-100">{{ $this->systemOverview['load'] }}</div>
                </div>
            </div>
        @else
            <div class="mt-4 grid gap-3 md:grid-cols-4">
                <div class="rounded-2xl border border-zinc-200 bg-white px-4 py-3 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                    <div class="text-[11px] uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{{ __('messages.unclaimed_tasks') }}</div>
                    <div class="mt-1 text-2xl font-semibold text-zinc-900 dark:text-zinc-100">{{ number_format($this->queueCount) }}</div>
                </div>
                <div class="rounded-2xl border border-cyan-200 bg-cyan-50/80 px-4 py-3 shadow-sm dark:border-cyan-500/40 dark:bg-cyan-500/10">
                    <div class="text-[11px] uppercase tracking-wide text-cyan-700 dark:text-cyan-300">{{ __('messages.my_active_tasks') }}</div>
                    <div class="mt-1 text-2xl font-semibold text-cyan-800 dark:text-cyan-100">{{ $this->activeClaimedCount }}/5</div>
                </div>
                <div class="rounded-2xl border border-emerald-200 bg-emerald-50/80 px-4 py-3 shadow-sm dark:border-emerald-500/40 dark:bg-emerald-500/10">
                    <div class="text-[11px] uppercase tracking-wide text-emerald-700 dark:text-emerald-300">{{ __('messages.status') }}</div>
                    <div class="mt-1 inline-flex items-center gap-2 text-sm font-semibold text-emerald-800 dark:text-emerald-200">
                        <span class="inline-block h-2.5 w-2.5 rounded-full bg-emerald-500"></span>
                        {{ __('messages.online') }}
                    </div>
                </div>
                <div class="rounded-2xl border border-violet-200 bg-violet-50/80 px-4 py-3 shadow-sm dark:border-violet-500/40 dark:bg-violet-500/10">
                    <div class="text-[11px] uppercase tracking-wide text-violet-700 dark:text-violet-300">{{ __('messages.avg_time') }}</div>
                    <div class="mt-1 text-2xl font-semibold text-violet-900 dark:text-violet-100">{{ $this->averageProcessingMinutes !== null ? $this->averageProcessingMinutes.'m' : '—' }}</div>
                </div>
            </div>
        @endif

        @if ($this->isAdmin)
            <div class="mt-4 rounded-2xl border border-zinc-200 bg-zinc-50/70 p-3 dark:border-zinc-700 dark:bg-zinc-950/50">
                <div
                    class="flex flex-wrap items-center gap-2 overflow-x-auto"
                    role="tablist"
                    aria-label="Admin operations views"
                    x-on:keydown.right.prevent="moveTab(1)"
                    x-on:keydown.left.prevent="moveTab(-1)"
                    x-on:keydown.home.prevent="firstTab()"
                    x-on:keydown.end.prevent="lastTab()"
                >
                    <flux:button id="tab-queue" type="button" variant="ghost" role="tab" aria-controls="panel-queue" x-on:click="setAdminTab('queue')" x-bind:aria-selected="adminTab === 'queue'" x-bind:tabindex="adminTab === 'queue' ? 0 : -1" x-bind:class="tabButtonClass('queue')" class="focus-visible:!ring-2 focus-visible:!ring-cyan-500/70">Queue View</flux:button>
                    <flux:button id="tab-distribution" type="button" variant="ghost" role="tab" aria-controls="panel-distribution" x-on:click="setAdminTab('distribution')" x-bind:aria-selected="adminTab === 'distribution'" x-bind:tabindex="adminTab === 'distribution' ? 0 : -1" x-bind:class="tabButtonClass('distribution')" class="focus-visible:!ring-2 focus-visible:!ring-cyan-500/70">Supervisor Distribution</flux:button>
                    <flux:button id="tab-table" type="button" variant="ghost" role="tab" aria-controls="panel-table" x-on:click="setAdminTab('table')" x-bind:aria-selected="adminTab === 'table'" x-bind:tabindex="adminTab === 'table' ? 0 : -1" x-bind:class="tabButtonClass('table')" class="focus-visible:!ring-2 focus-visible:!ring-cyan-500/70">Global Task Table</flux:button>
                </div>
                <p class="mt-2 text-xs text-zinc-500 dark:text-zinc-400">
                    Monitor health, inspect load distribution, and intervene only when necessary.
                </p>
            </div>

            @if ($this->adminAlerts->isNotEmpty())
                <section class="mt-3 grid gap-2 md:grid-cols-3">
                    @foreach ($this->adminAlerts as $alert)
                        <flux:callout variant="subtle" icon="exclamation-triangle" class="{{ $alert['level'] === 'red' ? 'border-red-300 bg-red-50/70 dark:border-red-900/50 dark:bg-red-950/40' : ($alert['level'] === 'amber' ? 'border-amber-300 bg-amber-50/70 dark:border-amber-900/50 dark:bg-amber-950/40' : 'border-zinc-300 bg-zinc-50/70 dark:border-zinc-700 dark:bg-zinc-900/40') }}">
                            <div class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">{{ $alert['title'] }}</div>
                            <div class="mt-1 text-xs text-zinc-600 dark:text-zinc-300">{{ $alert['message'] }}</div>
                        </flux:callout>
                    @endforeach
                </section>
            @endif

            <div class="relative">
                <div class="pointer-events-none absolute inset-0 z-10 hidden items-center justify-center rounded-2xl bg-white/55 backdrop-blur-sm dark:bg-zinc-900/55"
                    wire:loading.flex
                    wire:target="claimFulfillment,openForceCompleteModal,openForceFailModal,completeFulfillment,failFulfillment,retryFulfillment">
                    <flux:badge color="cyan">Updating operations view...</flux:badge>
                </div>
            </div>

            <section class="mt-4 rounded-2xl border border-zinc-200 bg-zinc-50/70 p-4 dark:border-zinc-700 dark:bg-zinc-950/50">
                <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                    <flux:heading size="sm">Supervisor health</flux:heading>
                    <flux:badge color="gray">{{ $this->supervisorHealth->count() }} supervisors</flux:badge>
                </div>
                <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                    @forelse ($this->supervisorHealth as $supervisor)
                        @php
                            $statusColor = match ($supervisor['status']) {
                                'overloaded' => 'red',
                                'busy' => 'amber',
                                'active' => 'green',
                                default => 'zinc',
                            };
                        @endphp
                        <article class="rounded-xl border border-zinc-200 bg-white p-3 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                            <div class="flex items-start justify-between gap-2">
                                <div class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">{{ $supervisor['name'] }}</div>
                                <flux:badge color="{{ $statusColor }}">
                                    <span class="inline-flex items-center gap-1">
                                        <span class="h-1.5 w-1.5 rounded-full {{ $statusColor === 'red' ? 'bg-red-500' : ($statusColor === 'amber' ? 'bg-amber-500' : ($statusColor === 'green' ? 'bg-emerald-500' : 'bg-zinc-400')) }}"></span>
                                        {{ $supervisor['status'] }}
                                    </span>
                                </flux:badge>
                            </div>
                            <div class="mt-2 text-xs text-zinc-500 dark:text-zinc-400">Active tasks</div>
                            <div class="text-lg font-semibold text-zinc-900 dark:text-zinc-100">{{ $supervisor['active_tasks'] }}/5</div>
                            <div class="mt-2 flex flex-wrap items-center gap-1.5">
                                <button
                                    type="button"
                                    class="inline-flex cursor-pointer items-center gap-1 rounded-md border border-emerald-200 bg-emerald-50 px-2 py-0.5 text-[11px] font-medium text-emerald-800 transition hover:bg-emerald-100 hover:ring-1 hover:ring-emerald-300/60 dark:border-emerald-900/40 dark:bg-emerald-950/30 dark:text-emerald-300 dark:hover:bg-emerald-950/50"
                                    wire:click="openAdminTableForSupervisorStatus({{ $supervisor['id'] }}, 'completed')"
                                >
                                    Completed
                                    <span>{{ $supervisor['completed_tasks'] }}</span>
                                </button>
                                <button
                                    type="button"
                                    class="inline-flex cursor-pointer items-center gap-1 rounded-md border border-red-200 bg-red-50 px-2 py-0.5 text-[11px] font-medium text-red-800 transition hover:bg-red-100 hover:ring-1 hover:ring-red-300/60 dark:border-red-900/40 dark:bg-red-950/30 dark:text-red-300 dark:hover:bg-red-950/50"
                                    wire:click="openAdminTableForSupervisorStatus({{ $supervisor['id'] }}, 'failed')"
                                >
                                    Failed
                                    <span>{{ $supervisor['failed_tasks'] }}</span>
                                </button>
                            </div>
                            <div class="mt-2 text-xs text-zinc-500 dark:text-zinc-400">
                                Last activity:
                                @if ($supervisor['last_activity_at'])
                                    <span x-text="relativeTime({{ \Illuminate\Support\Carbon::parse($supervisor['last_activity_at'])->getTimestamp() * 1000 }})">{{ \Illuminate\Support\Carbon::parse($supervisor['last_activity_at'])->diffForHumans() }}</span>
                                @else
                                    never
                                @endif
                            </div>
                        </article>
                    @empty
                        <div class="col-span-full rounded-xl border border-dashed border-zinc-300 bg-white/70 px-4 py-8 text-center text-sm text-zinc-500 dark:border-zinc-700 dark:bg-zinc-900/40 dark:text-zinc-400">
                            No supervisors with fulfillment permissions found.
                        </div>
                    @endforelse
                </div>
            </section>
        @endif

        <div id="panel-queue" role="tabpanel" aria-labelledby="tab-queue" class="mt-4 grid min-w-0 gap-4 xl:grid-cols-2" x-show="!{{ $this->isAdmin ? 'true' : 'false' }} || adminTab === 'queue'">
            <section class="min-w-0 rounded-2xl border border-zinc-200 bg-zinc-50/70 p-4 dark:border-zinc-700 dark:bg-zinc-950/50">
                <div class="mb-3 flex items-center justify-between">
                    <flux:heading size="sm">{{ __('messages.unclaimed_tasks') }}</flux:heading>
                    <div class="flex items-center gap-2">
                        <flux:badge color="gray">{{ $this->queueCards->count() }}</flux:badge>
                        <flux:button
                            type="button"
                            variant="ghost"
                            size="sm"
                            x-on:click="queueCollapsed = !queueCollapsed"
                            x-bind:aria-expanded="(!queueCollapsed).toString()"
                            aria-controls="unclaimed-tasks-list"
                        >
                            <span x-text="queueCollapsed ? 'Expand' : 'Collapse'"></span>
                        </flux:button>
                    </div>
                </div>
                <div id="unclaimed-tasks-list" class="space-y-3" x-show="!queueCollapsed" x-transition.opacity.duration.150ms>
                    @forelse ($this->queueCards as $fulfillment)
                        @php
                            $minutes = (int) now()->diffInMinutes($fulfillment->created_at ?? now());
                            $priority = $minutes >= 30 ? 'high' : ($minutes >= 10 ? 'medium' : 'normal');
                            $priorityDot = $priority === 'high' ? 'bg-red-500' : ($priority === 'medium' ? 'bg-amber-500' : 'bg-emerald-500');
                            $requirementsToken = $this->requirementsToken($fulfillment->orderItem?->requirements_payload);
                        @endphp
                        <article
                            wire:key="queue-card-{{ $fulfillment->id }}"
                            x-transition:enter="transition ease-out duration-200"
                            x-transition:enter-start="opacity-0 translate-y-2"
                            x-transition:enter-end="opacity-100 translate-y-0"
                            x-transition:leave="transition ease-in duration-150"
                            x-transition:leave-start="opacity-100 translate-y-0"
                            x-transition:leave-end="opacity-0 -translate-y-1"
                            class="min-w-0 rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm transition hover:-translate-y-0.5 hover:shadow-md dark:border-zinc-700/80 dark:bg-zinc-900/90"
                        >
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0 space-y-1">
                                    <div class="truncate text-sm font-semibold text-zinc-900 dark:text-zinc-100">{{ $fulfillment->orderItem?->name ?? __('messages.unknown_item') }}</div>
                                    <div class="truncate text-xs text-zinc-500 dark:text-zinc-400">Username: {{ $fulfillment->order?->user?->username ?? __('messages.unknown_user') }}</div>

                                    @if ($this->isAdmin)
                                        <div class="text-xs text-zinc-500 dark:text-zinc-400">
                                            {{ __('messages.claimed_by') }}:
                                            <span class="font-medium text-zinc-700 dark:text-zinc-200">
                                                {{ $fulfillment->claimer?->name ?? $fulfillment->claimer?->username ?? ($fulfillment->claimed_by ? '#'.$fulfillment->claimed_by : __('messages.unassigned')) }}
                                            </span>
                                        </div>
                                    @endif
                                    <div class="mt-1 inline-flex flex-wrap items-center gap-1.5 text-[11px] text-zinc-500 dark:text-zinc-400">
                                        <span class="rounded-md border border-zinc-200 bg-zinc-50 px-1.5 py-0.5 font-mono text-[11px] text-zinc-700 dark:border-zinc-700 dark:bg-zinc-800/80 dark:text-zinc-300">
                                            {{ $this->fulfillmentOrderReference($fulfillment) }}
                                        </span>
                                        @if ($requirementsToken)
                                            <span class="inline-block max-w-[11rem] truncate rounded-md border border-cyan-200 bg-cyan-50 px-1.5 py-0.5 font-mono text-[11px] text-cyan-800 dark:border-cyan-900/50 dark:bg-cyan-950/40 dark:text-cyan-200 sm:max-w-none" title="{{ $requirementsToken }}">{{ $requirementsToken }}</span>
                                        @else
                                            <span class="rounded-md border border-zinc-200 bg-zinc-50 px-1.5 py-0.5 text-[11px] text-zinc-500 dark:border-zinc-700 dark:bg-zinc-800/80 dark:text-zinc-400">—</span>
                                        @endif
                                    </div>
                                </div>
                                <span class="inline-flex items-center gap-1 text-[11px] font-medium text-zinc-600 dark:text-zinc-300">
                                    <span class="h-2.5 w-2.5 rounded-full {{ $priorityDot }}"></span>
                                    {{ ucfirst($priority) }}
                                </span>
                            </div>

                            <div class="mt-3 flex flex-wrap items-center gap-2">
                                @php
                                    $cardItem = $fulfillment->orderItem;
                                    $cardIsCustomAmount = ($cardItem?->amount_mode ?? ProductAmountMode::Fixed) === ProductAmountMode::Custom;
                                    $cardSubtotal = $cardIsCustomAmount
                                        ? (float) ($cardItem?->line_total ?? 0)
                                        : (float) ($cardItem?->unit_price ?? 0);
                                @endphp
                                <flux:badge color="amber">
                                    <span x-text="relativeTime({{ (int) optional($fulfillment->created_at)->getTimestamp() * 1000 }})">{{ optional($fulfillment->created_at)->diffForHumans() }}</span>
                                </flux:badge>
                                <flux:badge color="cyan">{{ number_format($cardSubtotal, 2) }} {{ $fulfillment->order?->currency ?? 'USD' }}</flux:badge>
                                @if (($fulfillment->orderItem?->amount_mode ?? ProductAmountMode::Fixed) === ProductAmountMode::Custom && $fulfillment->orderItem?->requested_amount !== null)
                                    <flux:badge color="zinc">
                                        {{ number_format((float) $fulfillment->orderItem->requested_amount) }}{{ $fulfillment->orderItem?->amount_unit_label ? ' '.$fulfillment->orderItem->amount_unit_label : '' }}
                                    </flux:badge>
                                @endif
                            </div>

                            <div class="mt-4 flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center">
                                <flux:button
                                    variant="primary"
                                    wire:click="claimFulfillment({{ $fulfillment->id }})"
                                    :disabled="! $this->canClaimMore"
                                    title="{{ $this->canClaimMore ? '' : __('messages.fulfillment_claim_limit_reached') }}"
                                    wire:target="claimFulfillment({{ $fulfillment->id }})"
                                    wire:loading.attr="disabled"
                                    class="w-full sm:w-auto"
                                >
                                    {{ __('messages.claim_task') }}
                                </flux:button>

                                @if ($this->isAdmin)
                                    <flux:dropdown position="bottom start">
                                        <flux:button variant="ghost" icon-trailing="chevron-down" class="w-full sm:w-auto">
                                            Intervene
                                        </flux:button>
                                        <flux:menu>
                                            <flux:menu.item wire:click="openDetails({{ $fulfillment->id }})">Override / Force Assign</flux:menu.item>
                                            <flux:menu.item wire:click="openForceCompleteModal({{ $fulfillment->id }})">Force complete</flux:menu.item>
                                            <flux:menu.item wire:click="openForceFailModal({{ $fulfillment->id }})">Force fail</flux:menu.item>
                                        </flux:menu>
                                    </flux:dropdown>
                                @endif

                                <flux:button variant="ghost" wire:click="openDetails({{ $fulfillment->id }})" class="w-full sm:w-auto">
                                    {{ __('messages.details') }}
                                </flux:button>
                            </div>
                        </article>
                    @empty
                        <div class="rounded-2xl border border-dashed border-zinc-300 bg-white/70 px-4 py-10 text-center text-sm text-zinc-500 dark:border-zinc-700 dark:bg-zinc-900/40 dark:text-zinc-400">
                            {{ __('messages.no_fulfillments_hint') }}
                        </div>
                    @endforelse
                </div>
                <div
                    x-show="queueCollapsed"
                    x-transition.opacity.duration.150ms
                    class="rounded-xl border border-dashed border-zinc-300 bg-white/70 px-3 py-2 text-sm text-zinc-600 dark:border-zinc-700 dark:bg-zinc-900/40 dark:text-zinc-300"
                >
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <span>{{ __('messages.unclaimed_tasks') }} hidden</span>
                        <flux:button type="button" variant="ghost" size="sm" x-on:click="queueCollapsed = false">Show tasks</flux:button>
                    </div>
                </div>
            </section>

            <section class="min-w-0 rounded-2xl border border-zinc-200 bg-zinc-50/70 p-4 dark:border-zinc-700 dark:bg-zinc-950/50">
                <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                    <flux:heading size="sm">{{ $this->isAdmin ? 'Processing tasks' : __('messages.my_tasks') }}</flux:heading>
                    <div class="flex items-center gap-2">
                        <flux:badge color="cyan">{{ $this->isAdmin ? $this->activeClaimedCount : $this->activeClaimedCount.'/5' }}</flux:badge>
                        <flux:button
                            type="button"
                            variant="ghost"
                            size="sm"
                            x-on:click="processingCollapsed = !processingCollapsed"
                            x-bind:aria-expanded="(!processingCollapsed).toString()"
                            aria-controls="processing-tasks-list"
                        >
                            <span x-text="processingCollapsed ? 'Expand' : 'Collapse'"></span>
                        </flux:button>
                    </div>
                </div>
                <div id="processing-tasks-list" class="space-y-3" x-show="!processingCollapsed" x-transition.opacity.duration.150ms>
                    @forelse ($this->myCards as $fulfillment)
                        @php
                            $minutes = (int) now()->diffInMinutes($fulfillment->created_at ?? now());
                            $priority = $minutes >= 30 ? 'high' : ($minutes >= 10 ? 'medium' : 'normal');
                            $priorityDot = $priority === 'high' ? 'bg-red-500' : ($priority === 'medium' ? 'bg-amber-500' : 'bg-emerald-500');
                            $requirementsToken = $this->requirementsToken($fulfillment->orderItem?->requirements_payload);
                            $adminOwnsTask = $this->isAdmin && $fulfillment->claimed_by === auth()->id();
                        @endphp
                        <article
                            wire:key="my-card-{{ $fulfillment->id }}"
                            x-transition:enter="transition ease-out duration-200"
                            x-transition:enter-start="opacity-0 translate-y-2"
                            x-transition:enter-end="opacity-100 translate-y-0"
                            x-transition:leave="transition ease-in duration-150"
                            x-transition:leave-start="opacity-100 translate-y-0"
                            x-transition:leave-end="opacity-0 -translate-y-1"
                            class="min-w-0 rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm transition hover:-translate-y-0.5 hover:shadow-md dark:border-zinc-700/80 dark:bg-zinc-900/90"
                        >
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0 space-y-1">
                                    <div class="truncate text-sm font-semibold text-zinc-900 dark:text-zinc-100">{{ $fulfillment->orderItem?->name ?? __('messages.unknown_item') }}</div>
                                    <div class="truncate text-xs text-zinc-500 dark:text-zinc-400">Username: {{ $fulfillment->order?->user?->username ?? __('messages.unknown_user') }}</div>
                                    @if ($this->isAdmin)
                                        <div class="text-xs text-zinc-500 dark:text-zinc-400">
                                            {{ __('messages.claimed_by') }}:
                                            <span class="font-medium text-zinc-700 dark:text-zinc-200">
                                                {{ $fulfillment->claimer?->name ?? $fulfillment->claimer?->username ?? ($fulfillment->claimed_by ? '#'.$fulfillment->claimed_by : __('messages.unassigned')) }}
                                            </span>
                                        </div>
                                    @endif
                                    <div class="mt-1 inline-flex flex-wrap items-center gap-1.5 text-[11px] text-zinc-500 dark:text-zinc-400">
                                        <span class="rounded-md border border-zinc-200 bg-zinc-50 px-1.5 py-0.5 font-mono text-[11px] text-zinc-700 dark:border-zinc-700 dark:bg-zinc-800/80 dark:text-zinc-300">
                                            {{ $this->fulfillmentOrderReference($fulfillment) }}
                                        </span>
                                        @if ($requirementsToken)
                                            <span class="inline-block max-w-[11rem] truncate rounded-md border border-cyan-200 bg-cyan-50 px-1.5 py-0.5 font-mono text-[11px] text-cyan-800 dark:border-cyan-900/50 dark:bg-cyan-950/40 dark:text-cyan-200 sm:max-w-none" title="{{ $requirementsToken }}">{{ $requirementsToken }}</span>
                                        @else
                                            <span class="rounded-md border border-zinc-200 bg-zinc-50 px-1.5 py-0.5 text-[11px] text-zinc-500 dark:border-zinc-700 dark:bg-zinc-800/80 dark:text-zinc-400">—</span>
                                        @endif
                                    </div>
                                </div>
                                <span class="inline-flex items-center gap-1 text-[11px] font-medium text-zinc-600 dark:text-zinc-300">
                                    <span class="h-2.5 w-2.5 rounded-full {{ $priorityDot }}"></span>
                                    {{ ucfirst($priority) }}
                                </span>
                            </div>

                            <div class="mt-3 flex flex-wrap items-center gap-2">
                                @php
                                    $cardItem = $fulfillment->orderItem;
                                    $cardIsCustomAmount = ($cardItem?->amount_mode ?? ProductAmountMode::Fixed) === ProductAmountMode::Custom;
                                    $cardSubtotal = $cardIsCustomAmount
                                        ? (float) ($cardItem?->line_total ?? 0)
                                        : (float) ($cardItem?->unit_price ?? 0);
                                @endphp
                                <flux:badge color="amber">
                                    <span x-text="relativeTime({{ (int) optional($fulfillment->created_at)->getTimestamp() * 1000 }})">{{ optional($fulfillment->created_at)->diffForHumans() }}</span>
                                </flux:badge>
                                <flux:badge color="cyan">{{ number_format($cardSubtotal, 2) }} {{ $fulfillment->order?->currency ?? 'USD' }}</flux:badge>
                                @if (($fulfillment->orderItem?->amount_mode ?? ProductAmountMode::Fixed) === ProductAmountMode::Custom && $fulfillment->orderItem?->requested_amount !== null)
                                    <flux:badge color="zinc">
                                        {{ number_format((float) $fulfillment->orderItem->requested_amount) }}{{ $fulfillment->orderItem?->amount_unit_label ? ' '.$fulfillment->orderItem->amount_unit_label : '' }}
                                    </flux:badge>
                                @endif
                                <flux:badge color="{{ $this->statusBadgeColor($fulfillment->status) }}">{{ __('messages.fulfillment_status_'.$fulfillment->status->value) }}</flux:badge>
                            </div>

                            <div class="mt-4 flex flex-wrap items-center gap-2">
                                @if ($this->isAdmin && ! $adminOwnsTask)
                                    <flux:dropdown position="bottom start">
                                        <flux:button variant="ghost" icon-trailing="chevron-down" class="w-full sm:w-auto">Intervene</flux:button>
                                        <flux:menu>
                                            @if (! in_array($fulfillment->status, [FulfillmentStatus::Completed, FulfillmentStatus::Failed], true))
                                                <flux:menu.item wire:click="openForceCompleteModal({{ $fulfillment->id }})">Force complete</flux:menu.item>
                                            @endif
                                            @if (! in_array($fulfillment->status, [FulfillmentStatus::Completed, FulfillmentStatus::Failed], true))
                                                <flux:menu.item wire:click="openForceFailModal({{ $fulfillment->id }})">Force fail</flux:menu.item>
                                            @endif
                                        </flux:menu>
                                    </flux:dropdown>
                                @else
                                    @if (! in_array($fulfillment->status, [FulfillmentStatus::Completed, FulfillmentStatus::Failed], true))
                                        <flux:button variant="primary" wire:click="openCompleteModal({{ $fulfillment->id }})" wire:loading.attr="disabled" class="w-full sm:w-auto">
                                            {{ __('messages.mark_completed') }}
                                        </flux:button>
                                    @endif
                                    @if (! in_array($fulfillment->status, [FulfillmentStatus::Completed, FulfillmentStatus::Failed], true))
                                        <flux:button variant="danger" wire:click="openFailModal({{ $fulfillment->id }})" wire:loading.attr="disabled" class="w-full sm:w-auto">
                                            {{ __('messages.mark_failed') }}
                                        </flux:button>
                                    @endif
                                @endif
                                @if ($fulfillment->status === FulfillmentStatus::Failed && ! $this->isAdmin)
                                    <flux:button variant="ghost" wire:click="retryFulfillment({{ $fulfillment->id }})" class="w-full sm:w-auto">
                                        {{ __('messages.retry') }}
                                    </flux:button>
                                @endif
                                <flux:button variant="ghost" wire:click="openDetails({{ $fulfillment->id }})" class="w-full sm:w-auto">
                                    {{ __('messages.details') }}
                                </flux:button>
                            </div>
                        </article>
                    @empty
                        <div class="rounded-2xl border border-dashed border-zinc-300 bg-white/70 px-4 py-10 text-center text-sm text-zinc-500 dark:border-zinc-700 dark:bg-zinc-900/40 dark:text-zinc-400">
                            {{ __('messages.no_fulfillments_yet') }}
                        </div>
                    @endforelse
                </div>
                <div
                    x-show="processingCollapsed"
                    x-transition.opacity.duration.150ms
                    class="rounded-xl border border-dashed border-zinc-300 bg-white/70 px-3 py-2 text-sm text-zinc-600 dark:border-zinc-700 dark:bg-zinc-900/40 dark:text-zinc-300"
                >
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <span>{{ $this->isAdmin ? 'Processing tasks' : __('messages.my_tasks') }} hidden</span>
                        <flux:button type="button" variant="ghost" size="sm" x-on:click="processingCollapsed = false">Show tasks</flux:button>
                    </div>
                </div>
            </section>
        </div>

        @if ($this->isAdmin)
            <section id="panel-distribution" role="tabpanel" aria-labelledby="tab-distribution" class="mt-4 rounded-2xl border border-zinc-200 bg-zinc-50/70 p-4 dark:border-zinc-700 dark:bg-zinc-950/50" x-show="adminTab === 'distribution'" x-cloak>
                <div class="mb-3 flex items-center justify-between">
                    <flux:heading size="sm">Supervisor distribution</flux:heading>
                    <flux:badge color="gray">{{ $this->distributionBuckets->count() }} groups</flux:badge>
                </div>
                <div class="grid gap-3 lg:grid-cols-3">
                    @forelse ($this->distributionBuckets as $bucket)
                        <article class="rounded-xl border border-zinc-200 bg-white p-3 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                            <div class="mb-2 flex items-center justify-between">
                                <div class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">{{ $bucket['claimer_name'] }}</div>
                                <flux:badge color="cyan">{{ $bucket['count'] }} tasks</flux:badge>
                            </div>
                            <div class="space-y-2">
                                @foreach ($bucket['items'] as $item)
                                    <div class="rounded-lg border border-zinc-200 bg-zinc-50 px-2 py-1.5 text-xs text-zinc-700 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200">
                                        {{ $item->orderItem?->name ?? __('messages.unknown_item') }}
                                    </div>
                                @endforeach
                            </div>
                        </article>
                    @empty
                        <div class="col-span-full rounded-xl border border-dashed border-zinc-300 bg-white/70 px-4 py-8 text-center text-sm text-zinc-500 dark:border-zinc-700 dark:bg-zinc-900/40 dark:text-zinc-400">
                            No processing tasks assigned yet.
                        </div>
                    @endforelse
                </div>
            </section>

            <section id="panel-table" role="tabpanel" aria-labelledby="tab-table" class="mt-4 rounded-2xl border border-zinc-200 bg-zinc-50/70 p-4 dark:border-zinc-700 dark:bg-zinc-950/50" x-show="adminTab === 'table'" x-cloak>
                <div class="mb-3 flex items-center justify-between">
                    <flux:heading size="sm">Global task table</flux:heading>
                    <flux:badge color="gray">{{ $this->allPage->total() }} total</flux:badge>
                </div>
                <div class="mb-3 grid gap-3 md:grid-cols-3">
                    <div>
                        <label for="supervisor-filter" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                            Assignee
                        </label>
                        <select
                            id="supervisor-filter"
                            wire:model.live="userFilterId"
                            class="w-full rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-700 shadow-sm outline-none transition focus:border-cyan-500 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-200"
                        >
                            <option value="">All users</option>
                            @foreach ($this->supervisorFilterUsers as $supervisor)
                                <option value="{{ $supervisor->id }}">
                                    {{ $supervisor->name ?: $supervisor->username ?: ('#'.$supervisor->id) }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="customer-search" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                            Customer
                        </label>
                        <input
                            id="customer-search"
                            type="text"
                            wire:model.live.debounce.350ms="customerSearch"
                            placeholder="Name / email / username / ID"
                            class="w-full rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-700 shadow-sm outline-none transition focus:border-cyan-500 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-200"
                        />
                    </div>
                    <div>
                        <label for="order-search" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                            Order
                        </label>
                        <input
                            id="order-search"
                            type="text"
                            wire:model.live.debounce.350ms="orderSearch"
                            placeholder="Order # / order ID"
                            class="w-full rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-700 shadow-sm outline-none transition focus:border-cyan-500 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-200"
                        />
                    </div>
                </div>
                @if ($this->adminTableSupervisorId !== null)
                    <div class="mb-3 flex flex-wrap items-center gap-2">
                        <flux:badge color="zinc">Supervisor filter #{{ $this->adminTableSupervisorId }}</flux:badge>
                        <flux:badge color="{{ ($this->adminTableStatusFilter ?? $this->statusFilter) === \App\Enums\FulfillmentStatus::Completed->value ? 'green' : (($this->adminTableStatusFilter ?? $this->statusFilter) === \App\Enums\FulfillmentStatus::Failed->value ? 'red' : 'zinc') }}">
                            Status: {{ $this->adminTableStatusFilter ?? $this->statusFilter }}
                        </flux:badge>
                        <flux:button
                            variant="ghost"
                            size="sm"
                            wire:click="$set('adminTableSupervisorId', null); $set('adminTableStatusFilter', null); $set('userFilterId', null)"
                        >
                            Clear supervisor filter
                        </flux:button>
                    </div>
                @endif
                <div class="overflow-x-auto rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
                    <table class="min-w-full divide-y divide-zinc-200 text-sm dark:divide-zinc-700">
                        <thead class="bg-zinc-50 dark:bg-zinc-800/60">
                            <tr class="text-left text-xs uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                                <th class="px-4 py-2">ID</th>
                                <th class="px-4 py-2">Order ID</th>
                                <th class="px-4 py-2">Username</th>
                                <th class="px-4 py-2">Item</th>
                                <th class="px-4 py-2 align-bottom">
                                    <span class="block">{{ __('messages.subtotal') }}</span>
                                    <span class="block text-[10px] font-semibold leading-tight text-zinc-400 dark:text-zinc-500">{{ __('messages.amount') }}</span>
                                </th>
                                <th class="px-4 py-2">Supervisor</th>
                                <th class="px-4 py-2">Status</th>
                                <th class="px-4 py-2">Updated</th>
                                <th class="px-4 py-2">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                            @forelse ($this->allPage as $fulfillment)
                                <tr>
                                    <td class="px-4 py-2 text-zinc-700 dark:text-zinc-200">#{{ $fulfillment->id }}</td>
                                    <td class="px-4 py-2 font-mono text-zinc-700 dark:text-zinc-200">{{ $this->fulfillmentOrderReference($fulfillment) }}</td>
                                    <td class="px-4 py-2 text-zinc-700 dark:text-zinc-200">{{ $fulfillment->order?->user?->username ?? '—' }}</td>
                                    <td class="px-4 py-2 text-zinc-700 dark:text-zinc-200">{{ $fulfillment->orderItem?->name ?? __('messages.unknown_item') }}</td>
                                    <td class="px-4 py-2 text-zinc-700 dark:text-zinc-200">
                                        @php
                                            $tableItem = $fulfillment->orderItem;
                                            $tableCurrency = $fulfillment->order?->currency ?? 'USD';
                                            $tableIsCustomAmount = ($tableItem?->amount_mode ?? ProductAmountMode::Fixed) === ProductAmountMode::Custom;
                                        @endphp
                                        <div class="flex flex-col gap-0.5">
                                            <span class="font-mono text-sm tabular-nums text-zinc-900 dark:text-zinc-100" dir="ltr">
                                                @if ($tableItem?->line_total !== null)
                                                    {{ number_format((float) $tableItem->line_total, 2) }} {{ $tableCurrency }}
                                                @else
                                                    —
                                                @endif
                                            </span>
                                            <span class="text-xs tabular-nums text-zinc-500 dark:text-zinc-400" dir="ltr">
                                                @if ($tableItem === null)
                                                    —
                                                @elseif ($tableIsCustomAmount && $tableItem->requested_amount !== null)
                                                    {{ number_format((float) $tableItem->requested_amount) }}{{ $tableItem->amount_unit_label ? ' '.$tableItem->amount_unit_label : '' }}
                                                @else
                                                    {{ __('messages.quantity') }}: {{ $tableItem->quantity }}
                                                @endif
                                            </span>
                                        </div>
                                    </td>
                                    <td class="px-4 py-2 text-zinc-700 dark:text-zinc-200">{{ $fulfillment->claimer?->name ?? $fulfillment->claimer?->username ?? '—' }}</td>
                                    <td class="px-4 py-2">
                                        <flux:badge color="{{ $this->statusBadgeColor($fulfillment->status) }}">{{ __('messages.fulfillment_status_'.$fulfillment->status->value) }}</flux:badge>
                                    </td>
                                    <td class="px-4 py-2 text-zinc-500 dark:text-zinc-400">{{ optional($fulfillment->updated_at)->diffForHumans() }}</td>
                                    <td class="px-4 py-2">
                                        <flux:dropdown position="bottom end">
                                            <flux:button variant="ghost" size="sm" icon-trailing="chevron-down">Actions</flux:button>
                                            <flux:menu>
                                                <flux:menu.item wire:click="openDetails({{ $fulfillment->id }})">Inspect</flux:menu.item>
                                                <flux:menu.item wire:click="openForceCompleteModal({{ $fulfillment->id }})">Force complete</flux:menu.item>
                                                <flux:menu.item wire:click="openForceFailModal({{ $fulfillment->id }})">Force fail</flux:menu.item>
                                            </flux:menu>
                                        </flux:dropdown>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="9" class="px-4 py-8 text-center text-sm text-zinc-500 dark:text-zinc-400">No tasks match current filters.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="mt-3">{{ $this->allPage->links() }}</div>
            </section>
        @endif
    </section>

    <flux:modal
        wire:model.self="showDetailsModal"
        variant="floating"
        class="max-w-4xl pt-14"
        @close="closeDetails"
        @cancel="closeDetails"
    >
        @if ($this->selectedFulfillment)
            <div class="space-y-6">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div class="space-y-1">
                        <flux:heading size="lg" class="text-zinc-900 dark:text-zinc-100">
                            {{ __('messages.fulfillment_details') }}
                        </flux:heading>
                        <flux:text class="text-sm text-zinc-600 dark:text-zinc-400">
                            {{ $this->fulfillmentOrderReference($this->selectedFulfillment) }}
                        </flux:text>
                    </div>
                    <flux:badge color="{{ $this->statusBadgeColor($this->selectedFulfillment->status) }}">
                        {{ __('messages.fulfillment_status_'.$this->selectedFulfillment->status->value) }}
                    </flux:badge>
                </div>

                <div class="grid gap-4 md:grid-cols-2">
                    <div class="rounded-xl border border-zinc-100 bg-zinc-50 p-4 dark:border-zinc-800 dark:bg-zinc-800/60">
                        <div class="text-xs uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                            {{ __('messages.order_details') }}
                        </div>
                        <div class="mt-3">
                            @php
                                $order = $this->selectedFulfillment->order;
                                $orderItem = $this->selectedFulfillment->orderItem;
                                $currency = $order?->currency ?? 'USD';
                                $isCustomAmount = ($orderItem?->amount_mode ?? ProductAmountMode::Fixed) === ProductAmountMode::Custom;
                                $rawEntryPrice = (float) ($orderItem?->entry_price ?? 0);
                                if ($isCustomAmount && $rawEntryPrice <= 0) {
                                    $rawEntryPrice = (float) data_get($orderItem?->pricing_meta, 'entry_price', 0);
                                }
                                $entryPriceTotal = $isCustomAmount && $orderItem?->requested_amount !== null
                                    ? ($rawEntryPrice * (float) $orderItem->requested_amount)
                                    : null;
                            @endphp
                            <dl class="grid gap-3 text-sm sm:grid-cols-2">
                                <div class="rounded-lg border border-zinc-200 bg-white/70 p-3 dark:border-zinc-700 dark:bg-zinc-900/40">
                                    <dt class="text-[11px] uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                                        {{ __('messages.order_id') }}
                                    </dt>
                                    <dd class="mt-1 text-sm font-semibold text-zinc-900 dark:text-zinc-100">
                                        {{ $order?->id ?? '—' }}
                                    </dd>
                                </div>
                                <div class="rounded-lg border border-zinc-200 bg-white/70 p-3 dark:border-zinc-700 dark:bg-zinc-900/40">
                                    <dt class="text-[11px] uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                                        {{ __('messages.username') }}
                                    </dt>
                                    <dd class="mt-1 text-sm font-semibold text-zinc-900 dark:text-zinc-100">
                                        {{ $order?->user?->username ?? '—' }}
                                    </dd>
                                </div>
                                <div class="rounded-lg border border-zinc-200 bg-white/70 p-3 dark:border-zinc-700 dark:bg-zinc-900/40 sm:col-span-2">
                                    <dt class="text-[11px] uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                                        {{ __('messages.item') }}
                                    </dt>
                                    <dd class="mt-1 text-sm text-zinc-900 dark:text-zinc-100">
                                        {{ $orderItem?->name ?? '—' }}
                                    </dd>
                                </div>
                                <div class="rounded-lg border border-zinc-200 bg-white/70 p-3 dark:border-zinc-700 dark:bg-zinc-900/40">
                                    <dt class="text-[11px] uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                                        {{ $isCustomAmount ? __('messages.entry_price') : __('messages.entry_price') }}
                                    </dt>
                                    <dd class="mt-1 text-sm text-zinc-900 dark:text-zinc-100" dir="ltr">
                                        @if ($isCustomAmount && $entryPriceTotal !== null)
                                            {{ number_format($entryPriceTotal, 2) }} {{ $currency }}
                                        @else
                                            {{ number_format($orderItem?->entry_price, 2) ?? '—' }} {{ $currency }}
                                        @endif
                                    </dd>
                                </div>
                                @if (! $isCustomAmount)
                                    <div class="rounded-lg border border-zinc-200 bg-white/70 p-3 dark:border-zinc-700 dark:bg-zinc-900/40">
                                        <dt class="text-[11px] uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                                            {{ __('messages.unit_price') }}
                                        </dt>
                                        <dd class="mt-1 text-sm text-zinc-900 dark:text-zinc-100" dir="ltr">
                                            {{ $orderItem?->unit_price ?? '—' }} {{ $currency }}
                                        </dd>
                                    </div>
                                @endif
                                @if ($isCustomAmount)
                                    <div class="rounded-lg border border-zinc-200 bg-white/70 p-3 dark:border-zinc-700 dark:bg-zinc-900/40">
                                        <dt class="text-[11px] uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                                            {{ __('messages.order_item_purchased_amount') }}
                                        </dt>
                                        <dd class="mt-1 text-sm text-zinc-900 dark:text-zinc-100" dir="ltr">
                                            @if ($orderItem?->requested_amount !== null)
                                                {{ number_format((float) $orderItem->requested_amount) }}{{ $orderItem?->amount_unit_label ? ' '.$orderItem->amount_unit_label : '' }}
                                            @else
                                                —
                                            @endif
                                        </dd>
                                    </div>
                                    <div class="rounded-lg border border-zinc-200 bg-white/70 p-3 dark:border-zinc-700 dark:bg-zinc-900/40 sm:col-span-2">
                                        <dt class="text-[11px] uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                                            {{ __('messages.line_total') }}
                                        </dt>
                                        <dd class="mt-1 text-sm text-zinc-900 dark:text-zinc-100" dir="ltr">
                                            {{ $orderItem?->line_total !== null ? number_format((float) $orderItem->line_total, 2) : '—' }} {{ $currency }}
                                        </dd>
                                    </div>
                                @endif
                            </dl>
                        </div>
                    </div>

                    <div class="rounded-xl border border-zinc-100 bg-zinc-50 p-4 dark:border-zinc-800 dark:bg-zinc-800/60">
                        <div class="text-xs uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                            {{ __('messages.payloads') }}
                        </div>
                        <div class="mt-2 text-sm text-zinc-700 dark:text-zinc-300">
                            @php
                                $requirementsPayload = $orderItem?->requirements_payload
                                    ?? data_get($this->selectedFulfillment->meta, 'requirements_payload');
                                $deliveredPayload = data_get($this->selectedFulfillment->meta, 'delivered_payload');
                                $formatPayload = static function (mixed $payload): string {
                                    if (is_string($payload)) {
                                        return $payload;
                                    }

                                    $json = json_encode(
                                        $payload,
                                        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                                    );

                                    return $json === false ? '' : $json;
                                };
                                $hasRequirementsPayload = ! blank($requirementsPayload);
                                $hasDeliveredPayload = ! blank($deliveredPayload);
                            @endphp

                            @if ($this->selectedFulfillment->isBrowserAutomated())
                                @php($automationRun = $this->latestAutomationRun)
                                <div class="mb-3 rounded-xl border border-zinc-200 bg-zinc-50 p-3 dark:border-zinc-700 dark:bg-zinc-800/60">
                                    <div class="flex flex-wrap items-center justify-between gap-2">
                                        <div class="text-xs uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                                            {{ __('messages.fulfillment_automation') }}
                                        </div>
                                        @if ($automationRun)
                                            <flux:badge color="zinc">{{ $automationRun->status->value }}</flux:badge>
                                        @endif
                                    </div>
                                    @if ($automationRun?->external_order_id)
                                        <div class="mt-2 text-sm text-zinc-700 dark:text-zinc-300">
                                            {{ __('messages.supplier_order_id') }}:
                                            <span class="font-mono">{{ $automationRun->external_order_id }}</span>
                                        </div>
                                    @endif
                                    @if (data_get($this->selectedFulfillment->meta, 'automation.requires_review'))
                                        <flux:callout variant="warning" icon="exclamation-triangle" class="mt-2">
                                            {{ __('messages.fulfillment_automation_needs_review') }}
                                        </flux:callout>
                                    @endif
                                    @if ($automationRun && $automationRun->artifactPaths() !== [])
                                        <div class="mt-2 flex flex-wrap gap-2">
                                            @foreach ($automationRun->artifactPaths() as $artifactPath)
                                                <flux:button
                                                    size="sm"
                                                    variant="ghost"
                                                    href="{{ route('admin.fulfillment-automation.artifacts.show', ['run' => $automationRun->id, 'path' => $artifactPath]) }}"
                                                    target="_blank"
                                                >
                                                    {{ basename($artifactPath) }}
                                                </flux:button>
                                            @endforeach
                                        </div>
                                    @endif
                                    @if ($this->selectedFulfillment->status === \App\Enums\FulfillmentStatus::Failed || $automationRun?->status?->value === 'needs_review')
                                        <div class="mt-3">
                                            <flux:button
                                                size="sm"
                                                variant="outline"
                                                wire:click="retryAutomation({{ $this->selectedFulfillment->id }})"
                                            >
                                                {{ __('messages.retry_automation') }}
                                            </flux:button>
                                        </div>
                                    @endif
                                </div>
                            @endif

                            @if ($this->selectedFulfillment->status === \App\Enums\FulfillmentStatus::Failed && $this->selectedFulfillment->last_error)
                                <flux:callout variant="subtle" icon="exclamation-triangle" class="mb-3">
                                    <div class="text-xs uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                                        {{ __('messages.failure_reason') }}
                                    </div>
                                    <div class="mt-1 text-sm text-zinc-700 dark:text-zinc-300">
                                        {{ $this->selectedFulfillment->last_error }}
                                    </div>
                                </flux:callout>
                            @endif

                            <div class="space-y-3">
                                <div>
                                    <div class="text-xs uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                                        {{ __('messages.requirements_payload') }}
                                    </div>
                                    @if ($hasRequirementsPayload)
                                        <pre class="mt-2 whitespace-pre-wrap break-words rounded-lg border border-zinc-200 bg-white p-3 text-xs text-zinc-700 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-200">{{ $formatPayload($requirementsPayload) }}</pre>
                                    @else
                                        <span class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('messages.no_payload') }}</span>
                                    @endif
                                </div>
                                <div>
                                    <div class="text-xs uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                                        {{ __('messages.delivery_payload') }}
                                    </div>
                                    @if ($hasDeliveredPayload)
                                        <pre class="mt-2 whitespace-pre-wrap break-words rounded-lg border border-zinc-200 bg-white p-3 text-xs text-zinc-700 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-200">{{ $formatPayload($deliveredPayload) }}</pre>
                                    @else
                                        <span class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('messages.no_payload') }}</span>
                                    @endif
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="space-y-3">
                    <flux:heading size="sm" class="text-zinc-900 dark:text-zinc-100">
                        {{ __('messages.fulfillment_logs') }}
                    </flux:heading>
                    <div class="space-y-3">
                        @forelse ($this->selectedFulfillment->logs as $log)
                            <div class="rounded-xl border border-zinc-100 p-3 text-sm text-zinc-600 dark:border-zinc-800 dark:text-zinc-300">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <div class="font-semibold text-zinc-900 dark:text-zinc-100">
                                        {{ $log->message }}
                                    </div>
                                    <div class="text-xs text-zinc-500 dark:text-zinc-400">
                                        {{ $log->created_at?->format('M d, Y H:i') }}
                                    </div>
                                </div>
                                @if ($log->context)
                                    <pre class="mt-2 whitespace-pre-wrap break-words rounded-lg border border-zinc-200 bg-zinc-50 p-3 text-xs text-zinc-600 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-300">{{ json_encode($log->context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
                                @endif
                            </div>
                        @empty
                            <div class="rounded-xl border border-dashed border-zinc-200 p-4 text-center text-sm text-zinc-500 dark:border-zinc-700 dark:text-zinc-400">
                                {{ __('messages.no_logs_yet') }}
                            </div>
                        @endforelse
                    </div>
                </div>
            </div>
        @endif
    </flux:modal>

    <flux:modal
        wire:model.self="showCompleteModal"
        variant="floating"
        class="max-w-xl"
    >
        <div class="space-y-4">
            <div class="space-y-1">
                <flux:heading size="lg" class="text-zinc-900 dark:text-zinc-100">
                    {{ __('messages.mark_completed') }}
                </flux:heading>
                <flux:text class="text-sm text-zinc-600 dark:text-zinc-400">
                    {{ __('messages.fulfillment_payload_hint') }}
                </flux:text>
            </div>

        <div
            x-data="{ autoDonePayloadUi: false, deliveredPayload: @entangle('deliveredPayloadInput').defer }"
            class="space-y-4"
        >
            <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-zinc-100 bg-zinc-50 px-4 py-3 text-sm text-zinc-600 dark:border-zinc-800 dark:bg-zinc-800/60 dark:text-zinc-300">
                <div>
                    <div class="font-semibold text-zinc-900 dark:text-zinc-100">
                        {{ __('messages.done_payload_toggle') }}
                    </div>
                    <div class="text-xs text-zinc-500 dark:text-zinc-400">
                        {{ __('messages.done_payload_hint') }}
                    </div>
                </div>
                <flux:switch
                    class="focus:!border-(--color-accent) focus:!border-1 focus:!ring-0 focus:!outline-none focus:!ring-offset-0"
                    x-model="autoDonePayloadUi"
                    x-on:change="
                        if (autoDonePayloadUi) {
                            deliveredPayload = 'DONE';
                        } else if ((deliveredPayload ?? '').toString().trim() === 'DONE') {
                            deliveredPayload = '';
                        }
                    "
                />
            </div>

            <flux:textarea
                name="deliveredPayloadInput"
                label="{{ __('messages.delivery_payload') }}"
                rows="4"
                wire:model.defer="deliveredPayloadInput"
                x-model="deliveredPayload"
            />
        </div>

            <div class="flex flex-wrap items-center gap-2">
                <flux:spacer />
                <flux:button variant="ghost" wire:click="$set('showCompleteModal', false)">
                    {{ __('messages.cancel') }}
                </flux:button>
                <flux:button variant="primary" wire:click="completeFulfillment">
                    {{ __('messages.confirm') }}
                </flux:button>
            </div>
        </div>
    </flux:modal>

    <flux:modal
        wire:model.self="showFailModal"
        variant="floating"
        class="max-w-xl"
    >
        <div class="space-y-4">
            <div class="space-y-1">
                <flux:heading size="lg" class="text-zinc-900 dark:text-zinc-100">
                    {{ __('messages.mark_failed') }}
                </flux:heading>
                <flux:text class="text-sm text-zinc-600 dark:text-zinc-400">
                    {{ __('messages.fulfillment_failure_hint') }}
                </flux:text>
            </div>

            <flux:textarea
                name="failureReason"
                label="{{ __('messages.failure_reason') }}"
                rows="3"
                wire:model.defer="failureReason"
            />
            @error('failureReason')
                <flux:text color="red">{{ $message }}</flux:text>
            @enderror
            @can('process_refunds')
            <div class="rounded-xl border border-zinc-100 bg-zinc-50 p-3 dark:border-zinc-800 dark:bg-zinc-800/60">
                <div class="flex items-center justify-between gap-3">
                    <div class="space-y-1">
                        <flux:text class="text-sm text-zinc-700 dark:text-zinc-200">
                            {{ __('messages.refund_to_wallet') }}
                        </flux:text>
                        <flux:text class="text-xs text-zinc-500 dark:text-zinc-400">
                            {{ __('messages.refund_to_wallet_hint') }}
                        </flux:text>
                    </div>
                    <flux:switch
                        class="focus:!border-(--color-accent) focus:!border-1 focus:!ring-0 focus:!outline-none focus:!ring-offset-0"
                        wire:model="refundAfterFail"
                    />
                </div>
            </div>
            @error('refundAfterFail')
                <flux:text color="red">{{ $message }}</flux:text>
            @enderror
            @error('order_item')
                <flux:text color="red">{{ $message }}</flux:text>
            @enderror
            @endcan

            <div class="flex flex-wrap items-center gap-2">
                <flux:spacer />
                <flux:button variant="ghost" wire:click="$set('showFailModal', false)">
                    {{ __('messages.cancel') }}
                </flux:button>
                <flux:button variant="danger" wire:click="failFulfillment">
                    {{ __('messages.confirm') }}
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>
