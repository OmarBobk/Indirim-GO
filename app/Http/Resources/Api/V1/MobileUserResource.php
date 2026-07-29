<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * @mixin User
 */
class MobileUserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'name' => $this->name,
            'username' => $this->username,
            'email' => $this->email,
            'phone' => $this->phone,
            'country_code' => $this->country_code,
            'locale' => $this->preferredLocale(),
            'preferred_currency' => $this->preferred_currency,
            'timezone' => $this->timezone?->value,
            'profile_photo_url' => $this->profilePhotoUrl(),
            'email_verified_at' => $this->email_verified_at?->toIso8601String(),
        ];
    }

    private function profilePhotoUrl(): ?string
    {
        $path = trim((string) $this->profile_photo);

        if ($path === ''
            || str_contains($path, "\0")
            || str_contains($path, '..')
            || Str::startsWith($path, ['//', '\\\\'])
            || parse_url($path, PHP_URL_SCHEME) !== null) {
            return null;
        }

        $storageUrl = Storage::disk('public')->url(ltrim($path, '/'));
        $absoluteUrl = Str::startsWith($storageUrl, ['http://', 'https://'])
            ? $storageUrl
            : url($storageUrl);
        $scheme = parse_url($absoluteUrl, PHP_URL_SCHEME);

        return in_array($scheme, ['http', 'https'], true) ? $absoluteUrl : null;
    }
}
