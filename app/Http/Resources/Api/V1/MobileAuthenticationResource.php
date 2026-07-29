<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\DTOs\MobileAuthenticationResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin MobileAuthenticationResult
 */
class MobileAuthenticationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'token' => [
                'access_token' => $this->plainTextToken,
                'token_type' => 'Bearer',
                'expires_at' => $this->expiresAt->toIso8601String(),
            ],
            'user' => (new MobileUserResource($this->user))->resolve($request),
        ];
    }
}
