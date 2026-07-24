<?php

namespace App\Actions\Products;

use App\Enums\ProductAmountMode;
use App\Models\Product;

class UpsertProduct
{
    /**
     * @param  array{
     *     package_id:int,
     *     serial:?string,
     *     name:string,
     *     entry_price:float|string|null,
     *     is_active:bool,
     *     order:int,
     *     amount_mode?:string,
     *     amount_unit_label?:?string,
     *     custom_amount_min?:?int,
     *     custom_amount_max?:?int,
     *     custom_amount_step?:?int,
     *     product_api?:?string
     * }  $data
     */
    public function handle(?int $productId, array $data): Product
    {
        $product = $productId !== null
            ? Product::query()->findOrFail($productId)
            : new Product;

        $serial = $data['serial'] !== null && trim($data['serial']) !== '' ? trim($data['serial']) : null;

        $mode = ProductAmountMode::tryFrom((string) ($data['amount_mode'] ?? ProductAmountMode::Fixed->value))
            ?? ProductAmountMode::Fixed;
        $isCustom = $mode === ProductAmountMode::Custom;

        $unitLabel = isset($data['amount_unit_label']) && trim((string) $data['amount_unit_label']) !== ''
            ? trim((string) $data['amount_unit_label'])
            : null;

        $product->fill([
            'package_id' => $data['package_id'],
            'serial' => $serial,
            'name' => trim($data['name']),
            'entry_price' => $data['entry_price'] ?? null,
            'retail_price' => 0,
            'wholesale_price' => 0,
            'is_active' => $data['is_active'],
            'order' => $data['order'],
            'amount_mode' => $mode,
            'amount_unit_label' => $isCustom ? $unitLabel : null,
            'custom_amount_min' => $isCustom ? ($data['custom_amount_min'] ?? null) : null,
            'custom_amount_max' => $isCustom ? ($data['custom_amount_max'] ?? null) : null,
            'custom_amount_step' => $isCustom ? max(1, (int) ($data['custom_amount_step'] ?? 1)) : null,
            'product_api' => self::normalizeProductApi($data['product_api'] ?? null),
        ]);

        $product->save();

        return $product;
    }

    public static function normalizeProductApi(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return trim($value);
    }
}
