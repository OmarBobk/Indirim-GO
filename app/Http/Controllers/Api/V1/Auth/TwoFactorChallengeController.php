<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\MobileAuth\CompleteMobileTwoFactorChallenge;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\TwoFactorChallengeRequest;
use App\Http\Resources\Api\V1\MobileAuthenticationResource;

class TwoFactorChallengeController extends Controller
{
    public function __construct(
        private readonly CompleteMobileTwoFactorChallenge $completeTwoFactorChallenge,
    ) {}

    public function __invoke(TwoFactorChallengeRequest $request): MobileAuthenticationResource
    {
        $validated = $request->validated();

        return new MobileAuthenticationResource(
            $this->completeTwoFactorChallenge->execute(
                $validated['challenge_token'],
                $validated['code'] ?? null,
                $validated['recovery_code'] ?? null,
            )
        );
    }
}
