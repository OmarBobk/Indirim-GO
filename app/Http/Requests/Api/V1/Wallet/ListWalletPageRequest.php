<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Wallet;

use App\Actions\MobileWallet\ListMobileTopups;
use App\Http\Requests\Api\V1\ApiRequest;

class ListWalletPageRequest extends ApiRequest
{
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
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.ListMobileTopups::MAX_PER_PAGE],
        ];
    }

    public function pageNumber(): int
    {
        return (int) ($this->validated('page') ?? 1);
    }

    public function perPage(): int
    {
        return (int) ($this->validated('per_page') ?? ListMobileTopups::DEFAULT_PER_PAGE);
    }
}
