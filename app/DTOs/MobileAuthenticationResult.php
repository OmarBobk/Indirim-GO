<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Models\User;
use Carbon\CarbonImmutable;

final readonly class MobileAuthenticationResult
{
    public function __construct(
        public User $user,
        public string $plainTextToken,
        public CarbonImmutable $expiresAt,
    ) {}
}
