<?php

declare(strict_types=1);

namespace App\Support\Api\V1;

use App\Models\PackageRequirement;
use Illuminate\Support\Collection;

/**
 * Builds customer-safe package requirement form metadata.
 * Never exposes raw Laravel validation rule strings, regex, or supplier internals.
 */
final class MobileRequirementSchemaBuilder
{
    private const SAFE_OPTION_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9 _.\-]{0,63}$/';

    private const MAX_LENGTH_CEILING = 255;

    /**
     * @param  Collection<int, PackageRequirement>  $requirements
     * @return list<array{
     *     key: string,
     *     label: string,
     *     input_type: string,
     *     required: bool,
     *     max_length: int|null,
     *     options: list<string>|null
     * }>
     */
    public function forRequirements(Collection $requirements): array
    {
        return $requirements
            ->sortBy('order')
            ->values()
            ->map(fn (PackageRequirement $requirement): array => $this->forRequirement($requirement))
            ->all();
    }

    /**
     * @return array{
     *     key: string,
     *     label: string,
     *     input_type: string,
     *     required: bool,
     *     max_length: int|null,
     *     options: list<string>|null
     * }
     */
    public function forRequirement(PackageRequirement $requirement): array
    {
        $inputType = match ($requirement->type) {
            'number' => 'number',
            'select' => 'select',
            default => 'text',
        };

        $extraRules = $this->parseExtraRules($requirement->validation_rules);
        $maxLength = $this->extractSafeMaxLength($extraRules);
        $options = $inputType === 'select'
            ? $this->extractSafeOptions($extraRules)
            : null;

        // Fail closed: select without safe options becomes required text input.
        if ($inputType === 'select' && ($options === null || $options === [])) {
            $inputType = 'text';
            $options = null;
        }

        return [
            'key' => (string) $requirement->key,
            'label' => (string) $requirement->label,
            'input_type' => $inputType,
            'required' => (bool) $requirement->is_required,
            'max_length' => $maxLength,
            'options' => $options,
        ];
    }

    /**
     * @return list<string>
     */
    private function parseExtraRules(?string $rules): array
    {
        if ($rules === null || trim($rules) === '') {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (string $rule): string => trim($rule),
            explode('|', $rules)
        ), static fn (string $rule): bool => $rule !== ''));
    }

    /**
     * @param  list<string>  $rules
     */
    private function extractSafeMaxLength(array $rules): ?int
    {
        foreach ($rules as $rule) {
            if (! preg_match('/^max:(\d+)$/', $rule, $matches)) {
                continue;
            }

            $value = (int) $matches[1];
            if ($value < 1 || $value > self::MAX_LENGTH_CEILING) {
                return null;
            }

            return $value;
        }

        return null;
    }

    /**
     * @param  list<string>  $rules
     * @return list<string>|null
     */
    private function extractSafeOptions(array $rules): ?array
    {
        foreach ($rules as $rule) {
            if (! str_starts_with($rule, 'in:')) {
                continue;
            }

            $raw = substr($rule, 3);
            if ($raw === '' || str_contains($raw, "\0")) {
                return null;
            }

            $values = array_values(array_filter(array_map('trim', explode(',', $raw)), static fn (string $v): bool => $v !== ''));
            if ($values === []) {
                return null;
            }

            foreach ($values as $value) {
                if (preg_match(self::SAFE_OPTION_PATTERN, $value) !== 1) {
                    return null;
                }
            }

            return $values;
        }

        return null;
    }
}
