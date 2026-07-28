<?php

declare(strict_types=1);

namespace App\DTOs;

use Carbon\CarbonImmutable;

final readonly class MobileTwoFactorChallenge
{
    public function __construct(
        public string $plainTextToken,
        public CarbonImmutable $expiresAt,
    ) {}
}
