<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Auth\AuthenticateUserCredentials;
use App\Actions\MobileAuth\CreateMobileTwoFactorChallenge;
use App\Actions\MobileAuth\IssueMobileAccessToken;
use App\Exceptions\AccountLoginDenied;
use App\Exceptions\MobileApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\LoginRequest;
use App\Http\Resources\Api\V1\MobileAuthenticationResource;
use Illuminate\Http\JsonResponse;

class LoginController extends Controller
{
    public function __construct(
        private readonly AuthenticateUserCredentials $authenticateUserCredentials,
        private readonly CreateMobileTwoFactorChallenge $createTwoFactorChallenge,
        private readonly IssueMobileAccessToken $issueMobileAccessToken,
    ) {}

    public function __invoke(LoginRequest $request): MobileAuthenticationResource|JsonResponse
    {
        $validated = $request->validated();

        try {
            $user = $this->authenticateUserCredentials->execute(
                $validated['username'],
                $validated['password'],
            );
        } catch (AccountLoginDenied $exception) {
            throw new MobileApiException(
                'messages.mobile_api.'.$exception->machineCode,
                $exception->machineCode,
                422,
            );
        }

        if ($user === null) {
            throw new MobileApiException(
                'messages.mobile_api.invalid_credentials',
                'invalid_credentials',
                422,
            );
        }

        if (! $user->hasRole('customer')) {
            throw new MobileApiException(
                'messages.mobile_api.customer_role_required',
                'customer_role_required',
                403,
            );
        }

        $deviceName = $validated['device_name'] ?? null;

        if ($user->hasEnabledTwoFactorAuthentication()) {
            $challenge = $this->createTwoFactorChallenge->execute($user, $deviceName);

            return response()->json([
                'data' => [
                    'two_factor_required' => true,
                    'challenge_token' => $challenge->plainTextToken,
                    'expires_at' => $challenge->expiresAt->toIso8601String(),
                ],
            ], 202);
        }

        return new MobileAuthenticationResource(
            $this->issueMobileAccessToken->execute($user, $deviceName)
        );
    }
}
