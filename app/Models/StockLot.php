<?php

namespace App\Models;

use App\Enums\StockLotStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockLot extends Model
{
    protected $fillable = [
        'company_id',
        'product_id',
        'lot_code',
        'production_date',
        'entry_date',
        'expiration_date',
        'initial_quantity',
        'supplier_id',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'production_date' => 'date',
            'entry_date' => 'date',
            'expiration_date' => 'date',
            'initial_quantity' => 'decimal:2',
            'status' => StockLotStatus::class,
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
    
    // supplier() relationship will be added in Prompt 4
}
