<?php

namespace App\Actions\Products;

use App\Models\Product;

class GetProductDetails
{
    public function handle(?int $productId): ?Product
    {
        if ($productId === null) {
            return null;
        }

        return Product::query()
            ->select([
                'id',
                'package_id',
                'serial',
                'name',
                'slug',
                'product_api',
                'entry_price',
                'retail_price',
                'wholesale_price',
                'is_active',
                'order',
                'amount_mode',
                'amount_unit_label',
                'custom_amount_min',
                'custom_amount_max',
                'custom_amount_step',
            ])
            ->with('package:id,name,slug')
            ->find($productId);
    }
}
