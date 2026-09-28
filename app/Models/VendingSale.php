<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VendingSale extends Model
{
    protected $fillable = [
        'company_id', 'vending_partner_id', 'vending_machine_id', 'product_id',
        'vending_payment_order_id', 'mercadopago_order_id', 'mercadopago_payment_id',
        'external_reference', 'gross_amount', 'commission_percent',
        'commission_amount', 'factory_amount', 'status', 'sold_at', 'payload',
    ];

    protected function casts(): array
    {
        return [
            'gross_amount' => 'decimal:2',
            'commission_percent' => 'decimal:2',
            'commission_amount' => 'decimal:2',
            'factory_amount' => 'decimal:2',
            'sold_at' => 'datetime',
            'payload' => 'array',
        ];
    }

    public function machine(): BelongsTo
    {
        return $this->belongsTo(VendingMachine::class, 'vending_machine_id');
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(VendingPartner::class, 'vending_partner_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
