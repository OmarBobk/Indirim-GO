<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Actions\Commissions\GetAdminCommissionClawbacks;
use App\Actions\Commissions\RetryCommissionClawback;
use App\Support\AdminCommissionClawbackPresenter;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Masmerise\Toaster\Toastable;

#[Layout('layouts.app')]
final class CommissionClawbacksIndex extends Component
{
    use Toastable;
    use WithPagination;

    #[Url]
    public string $filter = 'all';

    #[Url]
    public string $search = '';

    public function mount(): void
    {
        abort_unless(auth()->user()?->can('view_commission_clawbacks'), 404);
    }

    public function updatedFilter(): void
    {
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function retry(string $publicRef): void
    {
        $user = auth()->user();
        abort_unless($user !== null && $user->can('process_commission_clawbacks'), 404);

        $result = app(RetryCommissionClawback::class)->handle($user, $publicRef);
        $this->success(__($result['message_key']));
        $this->resetPage();
    }

    public function render(): View
    {
        $user = auth()->user();
        abort_unless($user !== null, 404);

        $page = app(GetAdminCommissionClawbacks::class)->handle($user, [
            'filter' => $this->filter,
            'search' => $this->search,
            'page' => $this->getPage(),
        ]);

        $rows = app(AdminCommissionClawbackPresenter::class)->presentList($page['items']);

        return view('livewire.admin.commission-clawbacks-index', [
            'rows' => $rows,
            'total' => $page['total'],
            'currentPage' => $page['current_page'],
            'lastPage' => $page['last_page'],
            'canProcess' => $user->can('process_commission_clawbacks'),
        ])->title(__('messages.commission_clawbacks'));
    }
}
