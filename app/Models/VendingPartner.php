<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VendingPartner extends Model
{
    protected $fillable = [
        'company_id', 'name', 'contact_name', 'phone', 'address',
        'street_name', 'street_number', 'city_name', 'state_name',
        'location_reference', 'latitude', 'longitude',
        'commission_percent', 'mercadopago_user_id', 'mercadopago_access_token',
        'mercadopago_refresh_token', 'mercadopago_token_expires_at',
        'mercadopago_store_id', 'mercadopago_external_store_id', 'status',
    ];

    protected $hidden = [
        'mercadopago_access_token',
        'mercadopago_refresh_token',
    ];

    protected function casts(): array
    {
        return [
            'commission_percent' => 'decimal:2',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'mercadopago_access_token' => 'encrypted',
            'mercadopago_refresh_token' => 'encrypted',
            'mercadopago_token_expires_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function machines(): HasMany
    {
        return $this->hasMany(VendingMachine::class);
    }

    public function sales(): HasMany
    {
        return $this->hasMany(VendingSale::class);
    }

    public function hasMercadoPagoConnection(): bool
    {
        return !empty($this->mercadopago_user_id) && !empty($this->mercadopago_access_token);
    }

    public function hasCompleteLocation(): bool
    {
        return filled($this->street_name)
            && filled($this->street_number)
            && filled($this->city_name)
            && filled($this->state_name)
            && $this->latitude !== null
            && $this->longitude !== null;
    }
}
