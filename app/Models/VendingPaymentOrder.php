<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VendingPaymentOrder extends Model
{
    protected $fillable = [
        'company_id', 'vending_partner_id', 'vending_machine_id',
        'external_reference', 'mercadopago_order_id', 'amount', 'status',
        'processed_at', 'expires_at', 'payload',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'processed_at' => 'datetime',
            'expires_at' => 'datetime',
            'payload' => 'array',
        ];
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(VendingPartner::class, 'vending_partner_id');
    }

    public function machine(): BelongsTo
    {
        return $this->belongsTo(VendingMachine::class, 'vending_machine_id');
    }
}
