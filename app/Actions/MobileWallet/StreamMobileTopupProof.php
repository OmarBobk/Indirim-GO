<?php

declare(strict_types=1);

namespace App\Actions\MobileWallet;

use App\Exceptions\MobileApiException;
use App\Models\TopupProof;
use App\Models\TopupRequest;
use App\Models\User;
use App\Support\TopupRequestPublicRef;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

final class StreamMobileTopupProof
{
    public function handle(User $user, string $publicRef): Response
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

        $proof = TopupProof::query()
            ->where('topup_request_id', $request->id)
            ->orderBy('id')
            ->first();

        if ($proof === null || ! Storage::disk('local')->exists($proof->file_path)) {
            throw new MobileApiException(
                'messages.mobile_api.proof_not_found',
                'proof_not_found',
                404,
            );
        }

        $headers = [
            'Cache-Control' => 'private, no-store',
        ];
        if (is_string($proof->mime_type) && $proof->mime_type !== '') {
            $headers['Content-Type'] = $proof->mime_type;
        }

        return Storage::disk('local')->response(
            $proof->file_path,
            $proof->file_original_name ?? 'proof',
            $headers,
        );
    }
}
