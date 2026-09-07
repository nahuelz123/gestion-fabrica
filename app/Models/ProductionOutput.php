<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductionOutput extends Model
{
    protected $fillable = [
        'production_order_id',
        'lot_code',
        'expiration_date',
        'quantity_produced',
    ];

    protected function casts(): array
    {
        return [
            'expiration_date' => 'date',
            'quantity_produced' => 'decimal:2',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(ProductionOrder::class, 'production_order_id');
    }
}
