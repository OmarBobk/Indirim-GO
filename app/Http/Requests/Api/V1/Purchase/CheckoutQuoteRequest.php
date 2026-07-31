<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Purchase;

use App\Http\Requests\Api\V1\ApiRequest;

class CheckoutQuoteRequest extends ApiRequest
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
            'items' => ['required', 'array', 'min:1', 'max:1'],
            'items.0' => ['required', 'array'],
            'items.0.product_id' => ['required', 'integer', 'min:1'],
            'items.0.package_id' => ['nullable', 'integer', 'min:1'],
            'items.0.quantity' => ['nullable', 'integer'],
            'items.0.requested_amount' => ['nullable', 'integer'],
            'items.0.requirements' => ['nullable', 'array'],
            'items.0.unit_price' => ['prohibited'],
            'items.0.line_total' => ['prohibited'],
            'items.0.total' => ['prohibited'],
            'items.0.price' => ['prohibited'],
            'total' => ['prohibited'],
            'subtotal' => ['prohibited'],
        ];
    }

    /**
     * @return array{
     *     product_id: int,
     *     package_id: int|null,
     *     quantity: int|null,
     *     requested_amount: int|null,
     *     requirements: array<string, mixed>
     * }
     */
    public function item(): array
    {
        /** @var array<string, mixed> $raw */
        $raw = $this->validated('items')[0];

        return [
            'product_id' => (int) $raw['product_id'],
            'package_id' => isset($raw['package_id']) ? (int) $raw['package_id'] : null,
            'quantity' => array_key_exists('quantity', $raw) && $raw['quantity'] !== null
                ? (int) $raw['quantity']
                : null,
            'requested_amount' => array_key_exists('requested_amount', $raw) && $raw['requested_amount'] !== null
                ? (int) $raw['requested_amount']
                : null,
            'requirements' => is_array($raw['requirements'] ?? null) ? $raw['requirements'] : [],
        ];
    }
}
