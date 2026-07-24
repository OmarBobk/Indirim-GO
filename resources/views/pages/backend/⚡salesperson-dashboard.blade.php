<?php

declare(strict_types=1);

use App\Actions\Commissions\RequestSalespersonPayout;
use App\Models\User;
use App\Services\SalespersonDashboardService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Masmerise\Toaster\Toastable;

new #[Layout('layouts.app')] class extends Component
{
    use Toastable;

    /** When set (admins with manage_users only), dashboard data loads for this user. String id for URL + select binding. */
    #[Url]
    public ?string $as = null;

    public string $chartFilter = 'this_month';
    public ?string $chartStartDate = null;
    public ?string $chartEndDate = null;

    public array $kpis = [];
    public array $chartSeries = [];
    /** @var array<string, array<string, mixed>> */
    public array $chartPresets = [];
    public array $customers = [];

    /** @var array<int, array<string, mixed>> */
    public array $referredUsers = [];

    public array $orders = [];
    public array $earningsSummary = [];
    public array $payoutHistory = [];
    public array $payout = [];

    public string $dashboardWelcomeName = '';

    public string $dashboardReferralLink = '';

    public function mount(): void
    {
        abort_unless(auth()->user()?->can('view_referrals'), 403);
        if (! $this->canSelectSalespersonDashboard()) {
            $this->as = null;
        } else {
            $this->as = $this->normalizedViewAsParam($this->as);
        }
        $this->hydrateDashboardData();
    }

    public function updatedAs(): void
    {
        if (! $this->canSelectSalespersonDashboard()) {
            $this->as = null;
        } else {
            $this->as = $this->normalizedViewAsParam($this->as);
        }
        $this->hydrateDashboardData();
    }

    /** Admins may preview any account that has referral dashboard access. */
    public function canSelectSalespersonDashboard(): bool
    {
        return auth()->user()?->can('manage_users') ?? false;
    }

    public function isViewingAnotherSalesperson(): bool
    {
        $id = $this->parseOptionalPositiveInt($this->as);

        return $this->canSelectSalespersonDashboard()
            && $id !== null
            && $id !== (int) auth()->id();
    }

    /** @return \Illuminate\Support\Collection<int, User> */
    #[Computed]
    public function selectableSalespeople()
    {
        if (! $this->canSelectSalespersonDashboard()) {
            return collect();
        }

        return User::query()
            ->permission('view_referrals')
            ->orderBy('name')
            ->orderBy('username')
            ->get(['id', 'name', 'username', 'referral_code']);
    }

    public function updatedChartFilter(): void
    {
        if ($this->chartFilter !== 'custom') {
            $this->chartStartDate = null;
            $this->chartEndDate = null;

            return;
        }

        $this->hydrateChartData();
    }

    public function setChartFilter(string $filter): void
    {
        $allowed = ['today', '7d', 'this_month', 'ytd', 'custom'];
        $next = in_array($filter, $allowed, true) ? $filter : 'this_month';

        if ($next !== 'custom') {
            $this->chartStartDate = null;
            $this->chartEndDate = null;
            $this->chartSeries = $this->chartPresets[$next] ?? $this->chartSeries;
            $this->applyKpiSparksFromChart($this->kpiSparkSource());
            $this->chartFilter = $next;

            return;
        }

        $this->chartStartDate ??= now()->subDays(29)->toDateString();
        $this->chartEndDate ??= now()->toDateString();
        $this->chartFilter = 'custom';
    }

    public function updatedChartStartDate(): void
    {
        if ($this->chartFilter === 'custom') {
            $this->hydrateChartData();
        }
    }

    public function updatedChartEndDate(): void
    {
        if ($this->chartFilter === 'custom') {
            $this->hydrateChartData();
        }
    }

    public function requestPayout(): void
    {
        if ($this->isViewingAnotherSalesperson()) {
            $this->info(__('messages.salesperson_dashboard_payout_preview_mode'));

            return;
        }

        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        $result = app(RequestSalespersonPayout::class)->handle($user);

        if ($result === 'below_min') {
            $this->error(__('messages.payout_request_below_minimum', [
                'min' => '$'.number_format(RequestSalespersonPayout::MIN_ELIGIBLE_EXCLUSIVE, 0),
            ]));

            return;
        }

        if ($result === 'already_pending') {
            $this->info(__('messages.payout_request_already_pending'));

            return;
        }

        $this->success(__('messages.payout_request_sent_to_team'));
    }

    public function render(): View
    {
        return $this->view()->title(__('messages.salesperson_dashboard'));
    }

    private function hydrateDashboardData(): void
    {
        $salespersonId = $this->effectiveSalespersonId();
        $this->fillDashboardSubjectDisplay($salespersonId);
        $service = app(SalespersonDashboardService::class);
        $data = $service->build(
            $salespersonId,
            '30d',
            $this->chartFilter,
            $this->chartStartDate,
            $this->chartEndDate
        );

        $this->orders = $data['orders'];
        $this->customers = $data['customers'];
        $this->referredUsers = $this->attachReferredUserShowUrls($data['referredUsers']);
        $this->kpis = $data['kpis'];
        $this->earningsSummary = $data['earningsSummary'];
        $this->payoutHistory = $data['payoutHistory'];
        $this->payout = $data['payout'];
        $this->chartPresets = $data['chartPresets'];
        $this->chartSeries = $data['chartSeries'];
        $this->applyKpiSparksFromChart($this->kpiSparkSource());
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function attachReferredUserShowUrls(array $rows): array
    {
        $viewer = auth()->user();
        if ($viewer === null || $rows === []) {
            return array_map(static fn (array $r): array => array_merge($r, ['show_url' => '']), $rows);
        }

        $ids = array_values(array_unique(array_filter(array_map(
            static fn (array $r): int => (int) ($r['id'] ?? 0),
            $rows
        ))));
        $models = User::query()->whereIn('id', $ids)->get()->keyBy('id');

        foreach ($rows as $i => $row) {
            $id = (int) ($row['id'] ?? 0);
            $model = $models->get($id);
            $rows[$i]['show_url'] = $model !== null && $viewer->can('view', $model)
                ? route('salesperson.users.show', $model)
                : '';
        }

        return $rows;
    }

    private function hydrateChartData(): void
    {
        $service = app(SalespersonDashboardService::class);
        $chartSeries = $service->buildChartSeries(
            $this->effectiveSalespersonId(),
            $this->chartFilter,
            $this->chartStartDate,
            $this->chartEndDate
        );
        $this->chartSeries = $chartSeries;
        $this->applyKpiSparksFromChart($this->kpiSparkSource());
    }

    /** Same chart payload used for the hero chart (preset or custom) — KPI mini-sparks follow that range. */
    private function kpiSparkSource(): array
    {
        if ($this->chartFilter === 'custom') {
            return $this->chartSeries;
        }

        return $this->chartPresets[$this->chartFilter] ?? $this->chartSeries;
    }

    private function applyKpiSparksFromChart(array $chartSeries): void
    {
        $this->kpis = collect($this->kpis)->map(function (array $kpi) use ($chartSeries): array {
            $kpi['spark'] = match ($kpi['key']) {
                'orders' => $chartSeries['sparkline']['orders'],
                'pending' => $chartSeries['sparkline']['pending'],
                default => $chartSeries['sparkline']['earnings'],
            };

            return $kpi;
        })->values()->all();
    }

    private function effectiveSalespersonId(): int
    {
        if (! $this->canSelectSalespersonDashboard()) {
            return (int) auth()->id();
        }

        $id = $this->parseOptionalPositiveInt($this->as);
        if ($id === null) {
            return (int) auth()->id();
        }

        $validated = $this->validatedViewAsId($id);

        return $validated ?? (int) auth()->id();
    }

    private function parseOptionalPositiveInt(?string $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! ctype_digit($value)) {
            return null;
        }

        $id = (int) $value;

        return $id > 0 ? $id : null;
    }

    /** Returns canonical string id for URL, or null for “self”. */
    private function normalizedViewAsParam(?string $value): ?string
    {
        $id = $this->parseOptionalPositiveInt($value);
        if ($id === null) {
            return null;
        }

        $validated = $this->validatedViewAsId($id);

        return $validated !== null ? (string) $validated : null;
    }

    private function validatedViewAsId(int $id): ?int
    {
        if ($id <= 0) {
            return null;
        }

        $user = User::query()->find($id);
        if ($user === null || ! $user->can('view_referrals')) {
            return null;
        }

        return $user->id;
    }

    private function fillDashboardSubjectDisplay(int $salespersonId): void
    {
        if ($salespersonId === (int) auth()->id()) {
            $user = auth()->user();
            abort_unless($user instanceof User, 403);
            $this->dashboardWelcomeName = (string) ($user->name ?? '');
            $code = (string) ($user->referral_code ?? '');
        } else {
            $user = User::query()->find($salespersonId);
            if ($user === null) {
                $this->dashboardWelcomeName = '';
                $this->dashboardReferralLink = route('home');

                return;
            }
            $this->dashboardWelcomeName = (string) ($user->name ?? '');
            $code = (string) ($user->referral_code ?? '');
        }

        $this->dashboardReferralLink = $code !== ''
            ? route('home').'?ref='.rawurlencode($code)
            : route('home');
    }
};
?>

@include('pages.backend.salesperson-dashboard')
