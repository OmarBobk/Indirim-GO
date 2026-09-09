<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Wallet;

use App\Http\Requests\Api\V1\ApiRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

class SubmitMobileTopupRequest extends ApiRequest
{
    public const PROOF_MAX_KILOBYTES = 5120;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'string', 'regex:/^\d+(\.\d{1,2})?$/'],
            'currency' => ['required', 'string', Rule::in(['USD', 'TRY'])],
            'payment_method_id' => ['required', 'integer', 'min:1'],
            'proof' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:'.self::PROOF_MAX_KILOBYTES],
        ];
    }

    public function amount(): string
    {
        return (string) $this->validated('amount');
    }

    public function currency(): string
    {
        return strtoupper((string) $this->validated('currency'));
    }

    public function paymentMethodId(): int
    {
        return (int) $this->validated('payment_method_id');
    }

    public function proofFile(): ?UploadedFile
    {
        $file = $this->file('proof');

        return $file instanceof UploadedFile ? $file : null;
    }
}
