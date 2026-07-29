<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Actions\MobileCatalog\ListMobilePackages;
use App\Models\Category;
use Illuminate\Validation\Validator;

class ListPackagesRequest extends ApiRequest
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
            'category_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'q' => ['sometimes', 'nullable', 'string', 'min:'.ListMobilePackages::MIN_QUERY_LENGTH, 'max:'.ListMobilePackages::MAX_QUERY_LENGTH],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.ListMobilePackages::MAX_PER_PAGE],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $categoryId = $this->input('category_id');

            if ($categoryId === null || $categoryId === '') {
                return;
            }

            $exists = Category::query()
                ->whereKey((int) $categoryId)
                ->where('is_active', true)
                ->exists();

            if (! $exists) {
                $validator->errors()->add('category_id', __('validation.exists', ['attribute' => 'category_id']));
            }
        });
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

    public function categoryId(): ?int
    {
        $value = $this->validated('category_id');

        return $value !== null ? (int) $value : null;
    }

    public function searchQuery(): ?string
    {
        $value = $this->validated('q');

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function pageNumber(): int
    {
        return (int) ($this->validated('page') ?? 1);
    }

    public function perPage(): int
    {
        return (int) ($this->validated('per_page') ?? ListMobilePackages::DEFAULT_PER_PAGE);
    }
}
