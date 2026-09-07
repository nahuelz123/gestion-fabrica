<?php

namespace App\Models;

use App\Enums\Channel;
use App\Enums\MovementType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class StockMovement extends Model
{
    const UPDATED_AT = null; // Immutable ledger

    protected $fillable = [
        'company_id',
        'product_id',
        'warehouse_id',
        'lot_id',
        'type',
        'quantity_base',
        'presentation_id',
        'presentation_quantity',
        'reference_type',
        'reference_id',
        'user_id',
        'channel',
        'reason',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => MovementType::class,
            'quantity_base' => 'decimal:2',
            'presentation_quantity' => 'decimal:2',
            'channel' => Channel::class,
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

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(StockLot::class, 'lot_id');
    }

    public function presentation(): BelongsTo
    {
        return $this->belongsTo(ProductPresentation::class, 'presentation_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reference(): MorphTo
    {
        return $this->morphTo();
    }
}
