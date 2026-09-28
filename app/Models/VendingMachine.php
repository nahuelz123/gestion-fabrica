<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VendingMachine extends Model
{
    protected $fillable = [
        'company_id', 'vending_partner_id', 'product_id', 'code', 'name',
        'location', 'sale_price', 'capacity', 'loaded_units',
        'mercadopago_pos_id', 'mercadopago_external_pos_id',
        'mercadopago_qr_image_url', 'mercadopago_qr_code', 'status',
    ];

    protected function casts(): array
    {
        return [
            'sale_price' => 'decimal:2',
            'capacity' => 'integer',
            'loaded_units' => 'integer',
        ];
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(VendingPartner::class, 'vending_partner_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function paymentOrders(): HasMany
    {
        return $this->hasMany(VendingPaymentOrder::class);
    }

    public function sales(): HasMany
    {
        return $this->hasMany(VendingSale::class);
    }
}
