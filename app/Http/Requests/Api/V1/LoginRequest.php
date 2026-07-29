<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Support\Str;
use Laravel\Fortify\Fortify;

class LoginRequest extends ApiRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'username' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
            'device_name' => [
                'nullable',
                'string',
                'max:'.(int) config('mobile_api.token.device_name_max_length', 80),
            ],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (config('fortify.lowercase_usernames') && $this->has(Fortify::username())) {
            $this->merge([
                Fortify::username() => Str::lower((string) $this->input(Fortify::username())),
            ]);
        }
    }
}
