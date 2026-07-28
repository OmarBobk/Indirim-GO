<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

class TwoFactorChallengeRequest extends ApiRequest
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
            'challenge_token' => ['required', 'string', 'min:43', 'max:255'],
            'code' => [
                'bail',
                'nullable',
                'required_without:recovery_code',
                'prohibited_with:recovery_code',
                'string',
                'regex:/^\d{6}$/',
            ],
            'recovery_code' => [
                'bail',
                'nullable',
                'required_without:code',
                'prohibited_with:code',
                'string',
                'max:255',
            ],
        ];
    }
}
