<?php

declare(strict_types=1);

namespace App\Actions\MobileWallet;

use App\Exceptions\MobileApiException;
use App\Models\TopupRequest;
use App\Models\User;
use App\Support\Api\V1\MobileTopupFactory;
use App\Support\TopupRequestPublicRef;

final class GetMobileTopupDetail
{
    /**
     * @return array{data: array<string, mixed>}
     */
    public function handle(User $user, string $publicRef): array
    {
        $normalized = TopupRequestPublicRef::normalize($publicRef);

        if (! TopupRequestPublicRef::isValidFormat($normalized)) {
            throw new MobileApiException(
                'messages.mobile_api.topup_not_found',
                'topup_not_found',
                404,
            );
        }

        $request = TopupRequest::query()
            ->where('user_id', $user->id)
            ->where('public_ref', $normalized)
            ->first();

        if ($request === null) {
            throw new MobileApiException(
                'messages.mobile_api.topup_not_found',
                'topup_not_found',
                404,
            );
        }

        return [
            'data' => MobileTopupFactory::forUser($user)->fromOwnedRequest($request, $user),
        ];
    }
}
