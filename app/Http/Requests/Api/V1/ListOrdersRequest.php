<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Actions\MobileOrders\ListMobileOrders;
use App\Support\CustomerOrderFulfillmentClassifier;

class ListOrdersRequest extends ApiRequest
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
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.ListMobileOrders::MAX_PER_PAGE],
            'q' => [
                'sometimes',
                'nullable',
                'string',
                'min:'.ListMobileOrders::MIN_QUERY_LENGTH,
                'max:'.ListMobileOrders::MAX_QUERY_LENGTH,
            ],
            'customer_state' => [
                // Classifier::normalizeFilter maps unknown values (including `other`) to `all`.
                // Reject those here so the HTTP contract never silently widens the result set.
                'sometimes',
                'string',
                'in:'.implode(',', [
                    CustomerOrderFulfillmentClassifier::NEEDS_ATTENTION,
                    CustomerOrderFulfillmentClassifier::IN_PROGRESS,
                    CustomerOrderFulfillmentClassifier::DELIVERED,
                    CustomerOrderFulfillmentClassifier::REFUNDED,
                ]),
            ],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('q') && is_string($this->input('q'))) {
            $this->merge(['q' => trim($this->input('q'))]);
        }

        if ($this->input('q') === '') {
            $this->merge(['q' => null]);
        }
    }

    public function pageNumber(): int
    {
        return (int) ($this->validated('page') ?? 1);
    }

    public function perPage(): int
    {
        return (int) ($this->validated('per_page') ?? ListMobileOrders::DEFAULT_PER_PAGE);
    }

    public function searchQuery(): ?string
    {
        $value = $this->validated('q');

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function customerState(): ?string
    {
        $value = $this->validated('customer_state');

        return is_string($value) && $value !== '' ? $value : null;
    }
}
