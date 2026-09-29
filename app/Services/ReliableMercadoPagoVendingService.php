<?php

namespace App\Services;

use App\Models\VendingSale;

class ReliableMercadoPagoVendingService extends MercadoPagoVendingService
{
    public function processVerifiedWebhook(array $payload): ?VendingSale
    {
        $sale = parent::processVerifiedWebhook($payload);
        if ($sale) return $sale;

        $resourceId = (string) data_get($payload, 'data.id', '');
        if ($resourceId === '') return null;

        return VendingSale::with(['machine', 'partner', 'product'])
            ->where('mercadopago_order_id', $resourceId)
            ->first();
    }
}
