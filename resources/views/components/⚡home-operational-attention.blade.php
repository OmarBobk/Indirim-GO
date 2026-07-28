<?php

use App\Actions\Activity\GetCustomerActivity;
use App\Support\CustomerActivityPresenter;
use Illuminate\Support\Facades\Route;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component
{
    public string $announcement = '';

    public int $lastVisibleCount = 0;

    /** @var list<array<string, mixed>>|null */
    private ?array $itemsCache = null;

    private ?int $totalCache = null;

    private ?bool $hasMoreCache = null;

    public function mount(): void
    {
        abort_unless(auth()->check(), 403);
        $this->hydrateOperational();
        $this->lastVisibleCount = count($this->itemsCache ?? []);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    #[On('customer-activity-invalidate')]
    public function refreshFromInvalidation(array $payload = []): void
    {
        $before = $this->lastVisibleCount;
        $this->forgetOperational();
        $this->hydrateOperational();

        $after = count($this->itemsCache ?? []);
        $this->lastVisibleCount = $after;
        $this->announcement = $this->buildAnnouncement($before, $after);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getItemsProperty(): array
    {
        $this->hydrateOperational();

        return $this->itemsCache ?? [];
    }

    public function getTotalProperty(): int
    {
        $this->hydrateOperational();

        return $this->totalCache ?? 0;
    }

    public function getHasMoreProperty(): bool
    {
        $this->hydrateOperational();

        return $this->hasMoreCache ?? false;
    }

    public function getViewAllHrefProperty(): string
    {
        if (! Route::has('activity.index')) {
            return route('notifications.index');
        }

        return route('activity.index', ['filter' => 'action_required']);
    }

    private function hydrateOperational(): void
    {
        if ($this->itemsCache !== null) {
            return;
        }

        $user = auth()->user();
        if ($user === null) {
            $this->itemsCache = [];
            $this->totalCache = 0;
            $this->hasMoreCache = false;

            return;
        }

        $result = app(GetCustomerActivity::class)->forHomeOperational($user);

        $this->itemsCache = app(CustomerActivityPresenter::class)->presentMany($result->items, $user);
        $this->totalCache = $result->actionRequiredTotal;
        $this->hasMoreCache = $result->hasMoreActionRequired;
    }

    private function forgetOperational(): void
    {
        $this->itemsCache = null;
        $this->totalCache = null;
        $this->hasMoreCache = null;
    }

    private function buildAnnouncement(int $before, int $after): string
    {
        if ($before === $after) {
            return '';
        }

        if ($before === 0 && $after > 0) {
            return __('messages.home_operational_announce_new');
        }

        if ($before > 0 && $after === 0) {
            return __('messages.home_operational_announce_cleared');
        }

        return __('messages.home_operational_announce_updated', [
            'count' => max($before, $after),
        ]);
    }
};
?>

<div
    wire:key="home-operational-attention"
    data-test="home-operational-livewire"
>
    <div class="sr-only" aria-live="polite" aria-atomic="true" data-test="home-operational-live-region">
        {{ $announcement }}
    </div>

    <x-home.operational-attention
        :items="$this->items"
        :total="$this->total"
        :has-more="$this->hasMore"
        :view-all-href="$this->viewAllHref"
    />
</div>
