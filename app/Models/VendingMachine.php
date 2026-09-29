<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class VendingMachine extends Model
{
    protected $fillable = [
        'company_id', 'vending_partner_id', 'product_id', 'code', 'public_token', 'name',
        'location', 'sale_price', 'capacity', 'loaded_units',
        'mercadopago_pos_id', 'mercadopago_external_pos_id',
        'mercadopago_qr_image_url', 'mercadopago_qr_template_image_url',
        'mercadopago_qr_code', 'status', 'last_sale_at', 'last_provisioned_at',
    ];

    protected function casts(): array
    {
        return [
            'sale_price' => 'decimal:2',
            'capacity' => 'integer',
            'loaded_units' => 'integer',
            'last_sale_at' => 'datetime',
            'last_provisioned_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (VendingMachine $machine) {
            if (empty($machine->public_token)) {
                $machine->public_token = Str::random(48);
            }
        });
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

    public function tabletUrl(): string
    {
        return route('vending.tablet', ['token' => $this->public_token]);
    }

    public function isReady(): bool
    {
        return $this->status === 'active'
            && filled($this->mercadopago_external_pos_id)
            && filled($this->mercadopago_qr_image_url);
    }
}
