<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SupplierPriceScanItemStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierPriceScanItem extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'supplier_price_scan_id',
        'product_id',
        'product_api',
        'amount_mode',
        'reference_quantity',
        'status',
        'scanned_price',
        'displayed_raw',
        'error_code',
        'error_message',
        'scanned_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SupplierPriceScanItemStatus::class,
            'reference_quantity' => 'integer',
            'scanned_price' => 'decimal:8',
            'scanned_at' => 'datetime',
        ];
    }

    public function scan(): BelongsTo
    {
        return $this->belongsTo(SupplierPriceScan::class, 'supplier_price_scan_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
