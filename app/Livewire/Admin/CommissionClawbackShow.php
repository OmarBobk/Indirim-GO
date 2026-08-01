<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Actions\Commissions\CorrectCommissionClawback;
use App\Actions\Commissions\GetAdminCommissionClawbackDetail;
use App\Actions\Commissions\OpenCommissionClawbackDispute;
use App\Actions\Commissions\ResolveCommissionClawbackDispute;
use App\Actions\Commissions\RetryCommissionClawback;
use App\Actions\Commissions\WaiveCommissionClawback;
use App\Enums\CommissionClawbackCorrectionReason;
use App\Enums\CommissionClawbackDisputeReason;
use App\Enums\CommissionClawbackDisputeResolution;
use App\Enums\CommissionClawbackWaiverReason;
use App\Models\CommissionClawback;
use App\Support\AdminCommissionClawbackPresenter;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Masmerise\Toaster\Toastable;

#[Layout('layouts.app')]
final class CommissionClawbackShow extends Component
{
    use Toastable;

    public CommissionClawback $clawback;

    public string $waiverReason = '';

    public string $waiverAmount = '';

    public string $waiverNote = '';

    public string $waiverIdempotencyToken = '';

    public bool $showWaiverForm = false;

    public string $disputeReason = '';

    public string $disputeNote = '';

    public string $disputeOpenIdempotencyToken = '';

    public bool $showDisputeOpenForm = false;

    public string $disputeResolution = '';

    public string $disputeResolutionSummary = '';

    public string $disputeResolveNote = '';

    public string $disputeFinancialReason = '';

    public string $disputeFinancialAmount = '';

    public string $disputeResolveIdempotencyToken = '';

    public bool $showDisputeResolveForm = false;

    public string $correctionReason = '';

    public string $correctionAmount = '';

    public string $correctionNote = '';

    public string $correctionIdempotencyToken = '';

    public bool $showCorrectionForm = false;

    public function mount(CommissionClawback $clawback): void
    {
        abort_unless(auth()->user()?->can('view_commission_clawbacks'), 404);
        $this->clawback = $clawback;
        $this->waiverIdempotencyToken = (string) Str::uuid();
        $this->disputeOpenIdempotencyToken = (string) Str::uuid();
        $this->disputeResolveIdempotencyToken = (string) Str::uuid();
        $this->correctionIdempotencyToken = (string) Str::uuid();
    }

    public function retry(): void
    {
        $user = auth()->user();
        abort_unless($user !== null && $user->can('process_commission_clawbacks'), 404);

        $result = app(RetryCommissionClawback::class)->handle($user, $this->clawback);
        $this->clawback = $result['clawback']->fresh() ?? $this->clawback;
        $this->success(__($result['message_key']));
    }

    public function openWaiverForm(): void
    {
        abort_unless(auth()->user()?->can('waive_commission_clawbacks'), 404);
        $this->showWaiverForm = true;
        $this->waiverIdempotencyToken = (string) Str::uuid();
    }

    public function waive(): void
    {
        $user = auth()->user();
        abort_unless($user !== null && $user->can('waive_commission_clawbacks'), 404);

        $this->validate([
            'waiverReason' => ['required', 'in:'.implode(',', CommissionClawbackWaiverReason::values())],
            'waiverAmount' => ['nullable', 'regex:/^\d+(\.\d{1,2})?$/'],
            'waiverNote' => ['nullable', 'string', 'max:500'],
        ], [
            'waiverReason.required' => __('messages.clawback_waiver_reason_required'),
            'waiverReason.in' => __('messages.clawback_waiver_invalid_reason'),
        ]);

        $result = app(WaiveCommissionClawback::class)->handle(
            $user,
            $this->clawback,
            $this->waiverReason,
            $this->waiverAmount !== '' ? $this->waiverAmount : null,
            $this->waiverNote !== '' ? $this->waiverNote : null,
            $this->waiverIdempotencyToken,
        );

        $this->clawback = $result['clawback']->fresh() ?? $this->clawback;
        $this->showWaiverForm = false;
        $this->waiverAmount = '';
        $this->waiverNote = '';
        $this->waiverIdempotencyToken = (string) Str::uuid();

        if (in_array($result['outcome'], ['waived', 'replayed', 'already_waived'], true)) {
            $this->success(__($result['message_key']));
        } else {
            $this->error(__($result['message_key']));
        }
    }

    public function openDisputeForm(): void
    {
        abort_unless(auth()->user()?->can('manage_commission_clawback_disputes'), 404);
        $this->showDisputeOpenForm = true;
        $this->disputeOpenIdempotencyToken = (string) Str::uuid();
    }

    public function openDispute(): void
    {
        $user = auth()->user();
        abort_unless($user !== null && $user->can('manage_commission_clawback_disputes'), 404);

        $this->validate([
            'disputeReason' => ['required', 'in:'.implode(',', CommissionClawbackDisputeReason::values())],
            'disputeNote' => ['nullable', 'string', 'max:500'],
        ], [
            'disputeReason.required' => __('messages.clawback_dispute_reason_required'),
            'disputeReason.in' => __('messages.clawback_dispute_invalid_reason'),
        ]);

        $result = app(OpenCommissionClawbackDispute::class)->handle(
            $user,
            $this->clawback,
            $this->disputeReason,
            $this->disputeNote !== '' ? $this->disputeNote : null,
            $this->disputeOpenIdempotencyToken,
        );

        $this->clawback = $result['clawback']->fresh() ?? $this->clawback;
        $this->showDisputeOpenForm = false;
        $this->disputeNote = '';
        $this->disputeOpenIdempotencyToken = (string) Str::uuid();

        if (in_array($result['outcome'], ['opened', 'replayed'], true)) {
            $this->success(__($result['message_key']));
        } else {
            $this->error(__($result['message_key']));
        }
    }

    public function openDisputeResolveForm(): void
    {
        abort_unless(auth()->user()?->can('manage_commission_clawback_disputes'), 404);
        $this->showDisputeResolveForm = true;
        $this->disputeResolveIdempotencyToken = (string) Str::uuid();
    }

    public function resolveDispute(): void
    {
        $user = auth()->user();
        abort_unless($user !== null && $user->can('manage_commission_clawback_disputes'), 404);

        $rules = [
            'disputeResolution' => ['required', 'in:'.implode(',', CommissionClawbackDisputeResolution::values())],
            'disputeResolveNote' => ['nullable', 'string', 'max:500'],
            'disputeResolutionSummary' => ['nullable', 'string', 'max:200'],
        ];

        if (in_array($this->disputeResolution, [
            CommissionClawbackDisputeResolution::AcceptedAsWaiver->value,
            CommissionClawbackDisputeResolution::AcceptedAsCorrection->value,
        ], true)) {
            $allowedReasons = $this->disputeResolution === CommissionClawbackDisputeResolution::AcceptedAsWaiver->value
                ? CommissionClawbackWaiverReason::values()
                : CommissionClawbackCorrectionReason::values();
            $rules['disputeFinancialReason'] = ['required', 'in:'.implode(',', $allowedReasons)];
            $rules['disputeFinancialAmount'] = ['nullable', 'regex:/^\d+(\.\d{1,2})?$/'];
        }

        $this->validate($rules, [
            'disputeResolution.required' => __('messages.clawback_dispute_resolution_required'),
            'disputeResolution.in' => __('messages.clawback_dispute_invalid_resolution'),
            'disputeFinancialReason.required' => __('messages.clawback_dispute_financial_reason_required'),
        ]);

        $result = app(ResolveCommissionClawbackDispute::class)->handle(
            $user,
            $this->clawback,
            $this->disputeResolution,
            $this->disputeResolveNote !== '' ? $this->disputeResolveNote : null,
            $this->disputeResolutionSummary !== '' ? $this->disputeResolutionSummary : null,
            $this->disputeFinancialReason !== '' ? $this->disputeFinancialReason : null,
            $this->disputeFinancialAmount !== '' ? $this->disputeFinancialAmount : null,
            $this->disputeResolveIdempotencyToken,
        );

        $this->clawback = $result['clawback']->fresh() ?? $this->clawback;
        $this->showDisputeResolveForm = false;
        $this->disputeResolveNote = '';
        $this->disputeResolutionSummary = '';
        $this->disputeFinancialReason = '';
        $this->disputeFinancialAmount = '';
        $this->disputeResolveIdempotencyToken = (string) Str::uuid();

        if (in_array($result['outcome'], ['resolved', 'replayed'], true)) {
            $this->success(__($result['message_key']));
        } else {
            $this->error(__($result['message_key']));
        }
    }

    public function openCorrectionForm(): void
    {
        abort_unless(auth()->user()?->can('correct_commission_clawbacks'), 404);
        $this->showCorrectionForm = true;
        $this->correctionIdempotencyToken = (string) Str::uuid();
    }

    public function correct(): void
    {
        $user = auth()->user();
        abort_unless($user !== null && $user->can('correct_commission_clawbacks'), 404);

        $this->validate([
            'correctionReason' => ['required', 'in:'.implode(',', CommissionClawbackCorrectionReason::values())],
            'correctionAmount' => ['required', 'regex:/^\d+(\.\d{1,2})?$/'],
            'correctionNote' => ['nullable', 'string', 'max:500'],
        ], [
            'correctionReason.required' => __('messages.clawback_correction_reason_required'),
            'correctionReason.in' => __('messages.clawback_correction_invalid_reason'),
            'correctionAmount.required' => __('messages.clawback_correction_amount_required'),
        ]);

        $result = app(CorrectCommissionClawback::class)->handle(
            $user,
            $this->clawback,
            $this->correctionReason,
            $this->correctionAmount,
            $this->correctionNote !== '' ? $this->correctionNote : null,
            $this->correctionIdempotencyToken,
        );

        $this->clawback = $result['clawback']->fresh() ?? $this->clawback;
        $this->showCorrectionForm = false;
        $this->correctionAmount = '';
        $this->correctionNote = '';
        $this->correctionIdempotencyToken = (string) Str::uuid();

        if (in_array($result['outcome'], ['corrected', 'replayed'], true)) {
            $this->success(__($result['message_key']));
        } else {
            $this->error(__($result['message_key']));
        }
    }

    public function render(): View
    {
        $user = auth()->user();
        abort_unless($user !== null, 404);

        $detail = app(GetAdminCommissionClawbackDetail::class)->handle($user, (string) $this->clawback->public_ref);
        $presented = app(AdminCommissionClawbackPresenter::class)->presentDetail($detail);

        return view('livewire.admin.commission-clawback-show', [
            'detail' => $presented,
        ])->title(__('messages.commission_clawback').' '.$this->clawback->public_ref);
    }
}
