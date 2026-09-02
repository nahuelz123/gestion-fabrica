<?php

namespace App\Models;

use App\Enums\ProductStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    protected $fillable = [
        'company_id',
        'category_id',
        'name',
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
            'requires_lot' => 'boolean',
            'requires_expiration' => 'boolean',
            'shelf_life_days' => 'integer',
            'cost' => 'decimal:2',
            'price' => 'decimal:2',
            'min_stock' => 'decimal:2',
            'status' => ProductStatus::class,
        ];
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

    public function isActive(): bool
    {
        return $this->status === ProductStatus::Active;
    }
}
