<?php

declare(strict_types=1);

namespace App\Actions\Wallets;

use App\Enums\CreditFacilityStatus;
use App\Enums\CustomerFinancialInvalidationReason;
use App\Enums\WalletType;
use App\Models\User;
use App\Models\Wallet;
use App\Services\SystemEventService;
use App\Support\CustomerFinancialBroadcaster;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class UpdateCreditFacility
{
    public function __construct(
        private readonly SystemEventService $systemEvents,
    ) {}

    /**
     * @param  array{
     *     credit_enabled: bool,
     *     credit_limit: string|int|float,
     *     payment_terms_days: int|string|null,
     *     credit_status: string|CreditFacilityStatus|null
     * }  $input
     */
    public function handle(User $actor, User $targetUser, array $input, ?string $ipAddress = null): Wallet
    {
        if (! $actor->can('manage_wallet_credit')) {
            throw new AuthorizationException(__('messages.credit_facility_unauthorized'));
        }

        $validated = $this->validateInput($input);
        $normalizedLimit = bcadd((string) $validated['credit_limit'], '0', 2);
        $enabled = (bool) $validated['credit_enabled'];
        $terms = $validated['payment_terms_days'];

        // Disabled facilities have no operational status; enabled requires Active|Suspended.
        $status = $enabled
            ? CreditFacilityStatus::from((string) $validated['credit_status'])
            : null;

        return DB::transaction(function () use (
            $actor,
            $targetUser,
            $enabled,
            $normalizedLimit,
            $terms,
            $status,
            $ipAddress,
        ): Wallet {
            $wallet = Wallet::query()
                ->where('user_id', $targetUser->id)
                ->where('type', WalletType::Customer)
                ->lockForUpdate()
                ->first();

            if ($wallet === null) {
                $wallet = Wallet::forUser($targetUser);
                $wallet = Wallet::query()->whereKey($wallet->id)->lockForUpdate()->firstOrFail();
            }

            if ($wallet->type !== WalletType::Customer) {
                throw ValidationException::withMessages([
                    'wallet' => __('messages.credit_facility_customer_only'),
                ]);
            }

            $outstandingDebt = $wallet->outstandingDebt();

            if (
                bccomp($outstandingDebt, '0', 2) === 1
                && bccomp($normalizedLimit, $outstandingDebt, 2) === -1
            ) {
                throw ValidationException::withMessages([
                    'credit_limit' => __('messages.credit_facility_limit_below_debt', [
                        'debt' => $outstandingDebt,
                        'currency' => $wallet->currency,
                    ]),
                ]);
            }

            $previous = [
                'previous_limit' => bcadd((string) $wallet->credit_limit, '0', 2),
                'previous_terms' => $wallet->payment_terms_days,
                'previous_enabled' => (bool) $wallet->credit_enabled,
                'previous_status' => $wallet->credit_status?->value,
            ];

            $wallet->fill([
                'credit_enabled' => $enabled,
                'credit_limit' => $normalizedLimit,
                'payment_terms_days' => $terms,
                'credit_status' => $status,
            ]);
            $wallet->save();

            $new = [
                'new_limit' => $normalizedLimit,
                'new_terms' => $terms,
                'new_enabled' => $enabled,
                'new_status' => $status?->value,
            ];
            $hasChanged = $previous['previous_enabled'] !== $new['new_enabled']
                || bccomp($previous['previous_limit'], $new['new_limit'], 2) !== 0
                || $previous['previous_terms'] !== $new['new_terms']
                || $previous['previous_status'] !== $new['new_status'];

            $this->systemEvents->record(
                'wallet.credit_facility.updated',
                $wallet,
                $actor,
                [
                    'wallet_id' => $wallet->id,
                    'target_user_id' => $targetUser->id,
                    'currency' => $wallet->currency,
                    'outstanding_debt' => $outstandingDebt,
                    'available_credit_after' => $wallet->fresh()->availableCredit(),
                    ...$previous,
                    ...$new,
                    'ip_address' => $ipAddress,
                ],
                'info',
                true,
            );

            if (Schema::hasTable('activity_log')) {
                $description = $this->buildAuditDescription(
                    actor: $actor,
                    targetUser: $targetUser,
                    previous: $previous,
                    new: $new,
                );

                activity()
                    ->inLog('payments')
                    ->event('wallet.credit_facility.updated')
                    ->performedOn($wallet)
                    ->causedBy($actor)
                    ->withProperties([
                        'wallet_id' => $wallet->id,
                        'target_user_id' => $targetUser->id,
                        'actor_id' => $actor->id,
                        'currency' => $wallet->currency,
                        'outstanding_debt' => $outstandingDebt,
                        'available_credit_after' => $wallet->availableCredit(),
                        ...$previous,
                        ...$new,
                        'ip_address' => $ipAddress,
                    ])
                    ->log($description);
            }

            if ($hasChanged) {
                CustomerFinancialBroadcaster::dispatch(
                    (int) $targetUser->id,
                    CustomerFinancialInvalidationReason::CreditFacilityChanged,
                );
            }

            return $wallet->fresh();
        });
    }

    /**
     * Projected available credit if the given facility settings were applied.
     */
    public function projectedAvailableCredit(
        Wallet $wallet,
        bool $enabled,
        string $creditLimit,
        ?CreditFacilityStatus $status,
    ): string {
        if ($wallet->type !== WalletType::Customer || ! $enabled || $status !== CreditFacilityStatus::Active) {
            $available = bcadd((string) $wallet->balance, '0', 2);
        } else {
            $limit = bcadd($creditLimit, '0', 2);
            if (bccomp($limit, '0', 2) === -1) {
                $limit = '0.00';
            }
            $available = bcadd((string) $wallet->balance, $limit, 2);
        }

        if (bccomp($available, '0', 2) === -1) {
            return '0.00';
        }

        return $available;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{
     *     credit_enabled: bool,
     *     credit_limit: string,
     *     payment_terms_days: int|null,
     *     credit_status: string|null
     * }
     */
    private function validateInput(array $input): array
    {
        $allowedTerms = array_map('intval', config('billing.wallet_payment_terms_days', [15, 30, 45, 60, 90]));
        $maxLimit = (string) config('billing.wallet_credit_limit_max', '100000.00');
        $enabled = (bool) ($input['credit_enabled'] ?? false);

        // Empty string from forms → treat as null for disabled facilities.
        if (array_key_exists('credit_status', $input) && ($input['credit_status'] === '' || $input['credit_status'] === null)) {
            $input['credit_status'] = null;
        }

        $validator = validator($input, [
            'credit_enabled' => ['required', 'boolean'],
            'credit_limit' => ['required', 'numeric', 'gte:0', 'lte:'.$maxLimit],
            'payment_terms_days' => [
                Rule::requiredIf(fn (): bool => $enabled),
                'nullable',
                'integer',
                Rule::in($allowedTerms),
            ],
            'credit_status' => [
                Rule::requiredIf(fn (): bool => $enabled),
                'nullable',
                Rule::in(CreditFacilityStatus::values()),
                Rule::prohibitedIf(fn (): bool => ! $enabled && filled($input['credit_status'] ?? null)),
            ],
        ], [
            'credit_limit.required' => __('messages.credit_facility_limit_invalid'),
            'credit_limit.numeric' => __('messages.credit_facility_limit_invalid'),
            'credit_limit.gte' => __('messages.credit_facility_limit_invalid'),
            'credit_limit.lte' => __('messages.credit_facility_limit_max', ['max' => $maxLimit]),
            'payment_terms_days.required' => __('messages.credit_facility_terms_required'),
            'payment_terms_days.in' => __('messages.credit_facility_terms_invalid'),
            'credit_status.required' => __('messages.credit_facility_status_required_when_enabled'),
            'credit_status.in' => __('messages.credit_facility_status_invalid'),
            'credit_status.prohibited' => __('messages.credit_facility_status_must_be_null_when_disabled'),
        ]);

        if ($validator->fails()) {
            throw ValidationException::withMessages($validator->errors()->toArray());
        }

        /** @var array{credit_enabled: bool, credit_limit: string|int|float, payment_terms_days: int|string|null, credit_status: string|null} $validated */
        $validated = $validator->validated();

        $terms = $validated['payment_terms_days'] ?? null;
        if ($terms !== null && $terms !== '') {
            $terms = (int) $terms;
        } else {
            $terms = null;
        }

        $status = $enabled ? (string) $validated['credit_status'] : null;

        return [
            'credit_enabled' => $enabled,
            'credit_limit' => bcadd((string) $validated['credit_limit'], '0', 2),
            'payment_terms_days' => $terms,
            'credit_status' => $status,
        ];
    }

    /**
     * @param  array{previous_limit: string, previous_terms: int|null, previous_enabled: bool, previous_status: string|null}  $previous
     * @param  array{new_limit: string, new_terms: int|null, new_enabled: bool, new_status: string|null}  $new
     */
    private function buildAuditDescription(User $actor, User $targetUser, array $previous, array $new): string
    {
        $changes = [];

        if ($previous['previous_enabled'] !== $new['new_enabled']) {
            $changes[] = sprintf(
                'enabled %s→%s',
                $previous['previous_enabled'] ? 'on' : 'off',
                $new['new_enabled'] ? 'on' : 'off',
            );
        }

        if (bccomp($previous['previous_limit'], $new['new_limit'], 2) !== 0) {
            $changes[] = sprintf('limit %s→%s', $previous['previous_limit'], $new['new_limit']);
        }

        if ($previous['previous_terms'] !== $new['new_terms']) {
            $changes[] = sprintf(
                'terms %s→%s',
                $previous['previous_terms'] === null ? 'none' : 'Net '.$previous['previous_terms'],
                $new['new_terms'] === null ? 'none' : 'Net '.$new['new_terms'],
            );
        }

        if ($previous['previous_status'] !== $new['new_status']) {
            $changes[] = sprintf(
                'status %s→%s',
                $previous['previous_status'] ?? 'none',
                $new['new_status'] ?? 'none',
            );
        }

        if ($changes === []) {
            $changes[] = 'no field changes';
        }

        return sprintf(
            'Admin %s updated credit facility for %s (%s).',
            $actor->name,
            $targetUser->name,
            implode(', ', $changes),
        );
    }
}
