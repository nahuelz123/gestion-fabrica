<?php

namespace App\Services;

use App\Jobs\RefreshVendingMachineOrderJob;
use App\Models\VendingMachine;
use App\Models\VendingPartner;
use App\Models\VendingPaymentOrder;
use App\Models\VendingSale;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class MercadoPagoVendingService
{
    private const API = 'https://api.mercadopago.com';

    public function authorizationUrl(string $state): string
    {
        $clientId = config('services.mercadopago.client_id');
        $redirect = config('services.mercadopago.redirect_uri');

        if (!$clientId || !$redirect) {
            throw new RuntimeException('Mercado Pago OAuth no está configurado.');
        }

        return 'https://auth.mercadopago.com.ar/authorization?' . http_build_query([
            'client_id' => $clientId,
            'response_type' => 'code',
            'platform_id' => 'mp',
            'state' => $state,
            'redirect_uri' => $redirect,
        ]);
    }

    public function exchangeAuthorizationCode(string $code): array
    {
        $response = Http::asForm()->timeout(20)->post(self::API . '/oauth/token', [
            'client_secret' => config('services.mercadopago.client_secret'),
            'client_id' => config('services.mercadopago.client_id'),
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => config('services.mercadopago.redirect_uri'),
        ]);

        if (!$response->successful()) {
            throw new RuntimeException('Mercado Pago rechazó la vinculación OAuth.');
        }

        return $response->json();
    }

    public function createStaticOrder(VendingMachine $machine): VendingPaymentOrder
    {
        $machine->loadMissing('partner', 'product');
        $partner = $machine->partner;

        if (!$partner || !$partner->hasMercadoPagoConnection()) {
            throw new RuntimeException('El comercio no tiene Mercado Pago vinculado.');
        }

        if (!$machine->mercadopago_external_pos_id) {
            throw new RuntimeException('La máquina no tiene una caja de Mercado Pago configurada.');
        }

        $existing = $machine->paymentOrders()
            ->whereIn('status', ['created', 'pending'])
            ->latest('id')
            ->first();

        if ($existing) {
            return $existing;
        }

        $reference = 'VM' . $machine->id . '_' . Str::lower(Str::ulid()->toBase32());
        $amount = number_format((float) $machine->sale_price, 2, '.', '');

        $payload = [
            'type' => 'qr',
            'total_amount' => $amount,
            'description' => mb_substr($machine->product?->name ?? $machine->name, 0, 150),
            'external_reference' => $reference,
            'expiration_time' => 'P30D',
            'config' => [
                'qr' => [
                    'external_pos_id' => $machine->mercadopago_external_pos_id,
                    'mode' => 'static',
                ],
            ],
            'transactions' => [
                'payments' => [[
                    'amount' => $amount,
                ]],
            ],
        ];

        $response = Http::withToken($partner->mercadopago_access_token)
            ->withHeaders(['X-Idempotency-Key' => (string) Str::uuid()])
            ->acceptJson()
            ->timeout(20)
            ->post(self::API . '/v1/orders', $payload);

        if (!$response->successful()) {
            throw new RuntimeException('No se pudo crear la orden QR en Mercado Pago.');
        }

        $data = $response->json();

        return VendingPaymentOrder::create([
            'company_id' => $machine->company_id,
            'vending_partner_id' => $machine->vending_partner_id,
            'vending_machine_id' => $machine->id,
            'external_reference' => $reference,
            'mercadopago_order_id' => $data['id'] ?? null,
            'amount' => $machine->sale_price,
            'status' => $data['status'] ?? 'created',
            'expires_at' => now()->addDays(30),
            'payload' => $data,
        ]);
    }

    public function fetchOrder(VendingPartner $partner, string $orderId): array
    {
        $response = Http::withToken($partner->mercadopago_access_token)
            ->acceptJson()
            ->timeout(20)
            ->get(self::API . '/v1/orders/' . urlencode($orderId));

        if (!$response->successful()) {
            throw new RuntimeException('No se pudo consultar la orden en Mercado Pago.');
        }

        return $response->json();
    }

    public function processVerifiedWebhook(array $payload): void
    {
        $resourceId = (string) data_get($payload, 'data.id', '');
        $mpUserId = (string) ($payload['user_id'] ?? '');

        if ($resourceId === '' || $mpUserId === '') {
            return;
        }

        $partner = VendingPartner::where('mercadopago_user_id', $mpUserId)->first();
        if (!$partner || !$partner->hasMercadoPagoConnection()) {
            return;
        }

        $order = $this->fetchOrder($partner, $resourceId);
        $externalReference = (string) ($order['external_reference'] ?? '');
        if ($externalReference === '') {
            return;
        }

        $paymentOrder = VendingPaymentOrder::where('external_reference', $externalReference)
            ->where('vending_partner_id', $partner->id)
            ->first();

        if (!$paymentOrder) {
            return;
        }

        DB::transaction(function () use ($order, $paymentOrder, $partner) {
            $paymentOrder = VendingPaymentOrder::whereKey($paymentOrder->id)
                ->lockForUpdate()
                ->firstOrFail();

            $status = (string) ($order['status'] ?? 'unknown');
            $paymentOrder->update([
                'status' => $status,
                'processed_at' => $status === 'processed' ? now() : $paymentOrder->processed_at,
                'payload' => $order,
            ]);

            if ($status !== 'processed') {
                return;
            }

            if (VendingSale::where('mercadopago_order_id', $order['id'] ?? '')->exists()) {
                return;
            }

            $machine = VendingMachine::whereKey($paymentOrder->vending_machine_id)
                ->lockForUpdate()
                ->firstOrFail();

            $gross = (float) ($order['total_paid_amount'] ?? $order['total_amount'] ?? $paymentOrder->amount);
            $commissionPercent = (float) $partner->commission_percent;
            $commissionAmount = round($gross * ($commissionPercent / 100), 2);
            $factoryAmount = round($gross - $commissionAmount, 2);
            $paymentId = data_get($order, 'transactions.payments.0.id');

            VendingSale::create([
                'company_id' => $paymentOrder->company_id,
                'vending_partner_id' => $partner->id,
                'vending_machine_id' => $machine->id,
                'product_id' => $machine->product_id,
                'vending_payment_order_id' => $paymentOrder->id,
                'mercadopago_order_id' => (string) ($order['id'] ?? $paymentOrder->mercadopago_order_id),
                'mercadopago_payment_id' => $paymentId ? (string) $paymentId : null,
                'external_reference' => $paymentOrder->external_reference,
                'gross_amount' => $gross,
                'commission_percent' => $commissionPercent,
                'commission_amount' => $commissionAmount,
                'factory_amount' => $factoryAmount,
                'status' => 'approved',
                'sold_at' => now(),
                'payload' => $order,
            ]);

            if ($machine->loaded_units > 0) {
                $machine->decrement('loaded_units');
            }
        });

        $status = (string) ($order['status'] ?? '');
        if (in_array($status, ['processed', 'expired', 'canceled'], true)) {
            RefreshVendingMachineOrderJob::dispatch($paymentOrder->vending_machine_id)->delay(now()->addSeconds(2));
        }
    }
}
