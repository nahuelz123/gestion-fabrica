<?php

namespace App\Models;

use App\Enums\ProductStatus;
use App\Enums\ProductType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    protected $fillable = [
        'company_id',
        'category_id',
        'type',
        'name',
        'presentation',
        'internal_code',
        'barcode',
        'base_unit_id',
        'requires_lot',
        'requires_expiration',
        'shelf_life_days',
        'cost',
        'price',
        'min_stock',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'type' => ProductType::class,
            'requires_lot' => 'boolean',
            'requires_expiration' => 'boolean',
            'shelf_life_days' => 'integer',
            'cost' => 'decimal:2',
            'price' => 'decimal:2',
            'min_stock' => 'decimal:2',
            'status' => ProductStatus::class,
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Product $product) {
            if (empty($product->internal_code)) {
                $prefix = 'PRD-';
                // Find highest existing number for this company
                $latest = Product::where('company_id', $product->company_id)
                    ->where('internal_code', 'like', "{$prefix}%")
                    ->lockForUpdate()
                    ->orderByRaw('CAST(SUBSTRING(internal_code, 5) AS UNSIGNED) DESC')
                    ->first();
                
                $nextNumber = 1;
                if ($latest) {
                    $number = (int) substr($latest->internal_code, 4);
                    $nextNumber = $number + 1;
                }
                
                do {
                    $code = $prefix . str_pad($nextNumber, 4, '0', STR_PAD_LEFT);
                    $exists = Product::where('company_id', $product->company_id)
                        ->where('internal_code', $code)->exists();
                    if ($exists) {
                        $nextNumber++;
                    }
                } while ($exists);

                $product->internal_code = $code;
            }
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'category_id');
    }

    public function baseUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'base_unit_id');
    }

    public function presentations(): HasMany
    {
        return $this->hasMany(ProductPresentation::class);
    }

    public function recipe(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Recipe::class);
    }

    public function stocks(): HasMany
    {
        return $this->hasMany(Stock::class);
    }

    public function isActive(): bool
    {
        return $this->status === ProductStatus::Active;
    }
}
