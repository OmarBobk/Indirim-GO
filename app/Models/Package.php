<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Package extends Model
{
    /** @use HasFactory<\Database\Factories\PackageFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'category_id',
        'name',
        'slug',
        'description',
        'is_active',
        'fulfillment_provider',
        'package_api',
        'order',
        'icon',
        'image',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category_id' => 'integer',
            'order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        parent::boot();

        static::creating(function (self $package): void {
            $package->slug = $package->slug ?? Str::slug($package->name);
        });

        static::updating(function (self $package): void {
            if ($package->isDirty('name')) {
                $package->slug = Str::slug($package->name);
            }
        });
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function requirements(): HasMany
    {
        return $this->hasMany(PackageRequirement::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
}
