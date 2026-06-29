<?php

declare(strict_types=1);

namespace App\Livewire\Users;

use App\Actions\UserPricingRules\DeleteUserPricingRule;
use App\Actions\UserPricingRules\UpsertUserPricingRule;
use App\Models\User;
use App\Models\UserPricingRule;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Masmerise\Toaster\Toastable;

class UserPricingRules extends Component
{
    use Toastable;

    public User $user;

    public bool $showModal = false;

    public ?int $editingRuleId = null;

    public string $minPrice = '0';

    public string $maxPrice = '999999.99';

    public string $retailPercentage = '';

    public string $wholesalePercentage = '';

    public int $priority = 0;

    public bool $isActive = true;

    public ?string $note = null;

    public function mount(User $user): void
    {
        $this->authorize('view', $user);
    }

    public function openCreate(): void
    {
        $this->authorize('manage_user_prices');
        $this->resetForm();
        $this->showModal = true;
    }

    public function openEdit(int $ruleId): void
    {
        $this->authorize('manage_user_prices');
        $rule = UserPricingRule::query()
            ->where('user_id', $this->user->id)
            ->whereKey($ruleId)
            ->firstOrFail();

        $this->editingRuleId = $rule->id;
        $this->minPrice = (string) $rule->min_price;
        $this->maxPrice = (string) $rule->max_price;
        $this->retailPercentage = (string) $rule->retail_percentage;
        $this->wholesalePercentage = (string) $rule->wholesale_percentage;
        $this->priority = $rule->priority;
        $this->isActive = $rule->is_active;
        $this->note = $rule->note;
        $this->showModal = true;
    }

    public function closeModal(): void
    {
        $this->showModal = false;
        $this->resetForm();
    }

    public function save(UpsertUserPricingRule $upsert): void
    {
        $this->authorize('manage_user_prices');
        $admin = auth()->user();
        if ($admin === null) {
            abort(403);
        }

        $validated = $this->validate([
            'minPrice' => ['required', 'numeric', 'min:0'],
            'maxPrice' => ['required', 'numeric', 'min:0', 'gt:minPrice'],
            'retailPercentage' => ['required', 'numeric', 'min:-100'],
            'wholesalePercentage' => ['required', 'numeric', 'min:-100'],
            'priority' => ['required', 'integer', 'min:0'],
            'isActive' => ['boolean'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $upsert->handle($this->user, $this->editingRuleId, [
            'min_price' => $validated['minPrice'],
            'max_price' => $validated['maxPrice'],
            'retail_percentage' => $validated['retailPercentage'],
            'wholesale_percentage' => $validated['wholesalePercentage'],
            'priority' => $validated['priority'],
            'is_active' => $validated['isActive'],
            'note' => $validated['note'] ?? null,
        ], $admin);

        $this->success($this->editingRuleId === null
            ? __('messages.user_pricing_rule_saved')
            : __('messages.user_pricing_rule_updated'));

        $this->closeModal();
    }

    public function deleteRule(int $ruleId, DeleteUserPricingRule $delete): void
    {
        $this->authorize('manage_user_prices');
        $admin = auth()->user();
        if ($admin === null) {
            abort(403);
        }

        $delete->handle($this->user, $ruleId, $admin);
        $this->success(__('messages.user_pricing_rule_deleted'));
    }

    private function resetForm(): void
    {
        $this->reset([
            'editingRuleId',
            'minPrice',
            'maxPrice',
            'retailPercentage',
            'wholesalePercentage',
            'priority',
            'isActive',
            'note',
        ]);
        $this->minPrice = '0';
        $this->maxPrice = '999999.99';
        $this->priority = 0;
        $this->isActive = true;
    }

    public function render(): View
    {
        $rules = UserPricingRule::query()
            ->where('user_id', $this->user->id)
            ->with('creator:id,name')
            ->orderBy('priority')
            ->orderBy('id')
            ->get();

        return view('livewire.users.user-pricing-rules', [
            'rules' => $rules,
        ]);
    }
}
