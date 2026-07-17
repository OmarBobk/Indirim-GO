<?php

declare(strict_types=1);

namespace App\Domain\Security\Contracts;

/**
 * Verifies that a challenge token proves a human (not a bot) submitted the request.
 *
 * Implementations must fail securely: any ambiguous outcome (timeout, malformed
 * response, provider error) must be treated as a failed verification.
 */
interface HumanVerifier
{
    /**
     * @param  string  $token  The challenge token supplied by the client widget.
     * @param  string|null  $clientIp  The requester's IP, when available, for provider-side risk scoring.
     */
    public function verify(string $token, ?string $clientIp = null): bool;
}
