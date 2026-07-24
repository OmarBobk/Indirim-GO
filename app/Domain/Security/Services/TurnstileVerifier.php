<?php

declare(strict_types=1);

namespace App\Domain\Security\Services;

use App\Domain\Security\Contracts\HumanVerifier;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Server-side verifier for Cloudflare Turnstile challenge tokens.
 *
 * This class only talks to Cloudflare's siteverify endpoint. It never decides
 * whether Turnstile is enabled or bypassed for a given environment — callers
 * (validation rules, actions) own that decision.
 */
final class TurnstileVerifier implements HumanVerifier
{
    private const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    public function verify(string $token, ?string $clientIp = null): bool
    {
        if (trim($token) === '') {
            return false;
        }

        $secret = (string) config('services.turnstile.secret_key');

        if ($secret === '') {
            Log::warning('Turnstile verification skipped: secret key is not configured.');

            return false;
        }

        try {
            $response = Http::asForm()
                ->timeout((int) config('services.turnstile.timeout', 5))
                ->post((string) config('services.turnstile.verify_url', self::VERIFY_URL), array_filter([
                    'secret' => $secret,
                    'response' => $token,
                    'remoteip' => $clientIp,
                ]));
        } catch (Throwable $exception) {
            Log::warning('Turnstile verification request failed.', [
                'exception' => $exception->getMessage(),
            ]);

            return false;
        }

        if ($response->failed()) {
            Log::warning('Turnstile verification returned a non-successful HTTP status.', [
                'status' => $response->status(),
            ]);

            return false;
        }

        return $response->json('success') === true;
    }
}
