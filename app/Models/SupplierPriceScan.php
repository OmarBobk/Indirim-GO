<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SupplierPriceScanStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupplierPriceScan extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'uuid',
        'supplier_key',
        'status',
        'products_total',
        'products_ok',
        'products_failed',
        'triggered_by',
        'started_at',
        'finished_at',
        'meta',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SupplierPriceScanStatus::class,
            'products_total' => 'integer',
            'products_ok' => 'integer',
            'products_failed' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(SupplierPriceScanItem::class);
    }
}
