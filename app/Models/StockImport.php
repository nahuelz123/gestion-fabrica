<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockImport extends Model
{
    protected $fillable = [
        'company_id', 'warehouse_id', 'user_id', 'filename', 'sha256',
        'status', 'row_count', 'rows', 'applied_at',
    ];

    protected function casts(): array
    {
        return [
            'rows' => 'array',
            'applied_at' => 'datetime',
        ];
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
