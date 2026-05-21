<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;

/**
 * How the account was first created (from activity log + optional referral link).
 */
final readonly class UserRegistrationSource
{
    public function __construct(
        public string $kind,
        public ?User $actor,
    ) {}

    public static function unknown(): self
    {
        return new self('unknown', null);
    }

    /**
     * Human-readable line for admin UI (uses current app locale).
     */
    public function describe(User $user): string
    {
        $actorLabel = $this->actorDisplay();

        return match ($this->kind) {
            'self_registered' => $user->referred_by_user_id !== null && $user->relationLoaded('referredBy') && $user->referredBy !== null
                ? __('messages.user_registration_self_with_referrer', [
                    'referrer' => $user->referredBy->username,
                ])
                : __('messages.user_registration_self'),
            'admin_created' => __('messages.user_registration_by_admin', ['name' => $actorLabel]),
            'salesperson_created' => __('messages.user_registration_by_salesperson', ['name' => $actorLabel]),
            default => __('messages.user_registration_unknown'),
        };
    }

    /**
     * @return array<int, string>
     */
    public function exportCells(User $user): array
    {
        return match ($this->kind) {
            'self_registered' => [
                __('messages.user_registration_self'),
                $user->referred_by_user_id !== null && $user->relationLoaded('referredBy') && $user->referredBy !== null
                    ? $user->referredBy->username
                    : '',
            ],
            'admin_created' => [
                __('messages.user_registration_by_admin_export'),
                $this->actor?->username ?? $this->actor?->name ?? '',
            ],
            'salesperson_created' => [
                __('messages.user_registration_by_salesperson_export'),
                $this->actor?->username ?? $this->actor?->name ?? '',
            ],
            default => [__('messages.user_registration_unknown'), ''],
        };
    }

    private function actorDisplay(): string
    {
        if ($this->actor === null) {
            return '—';
        }

        return $this->actor->username ?: $this->actor->name ?: (string) $this->actor->id;
    }
}
