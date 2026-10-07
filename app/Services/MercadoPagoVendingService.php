<?php

namespace App\Services;

use App\Jobs\NotifyVendingSaleJob;
use App\Jobs\RefreshVendingMachineOrderJob;
use App\Models\VendingMachine;
use App\Models\VendingPartner;
use App\Models\VendingPaymentOrder;
use App\Models\VendingSale;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class MercadoPagoVendingService
{
    private const API = 'https://api.mercadopago.com';

    public function authorizationUrl(string $state, string $codeChallenge): string
    {
        $clientId = (string) config('services.mercadopago.client_id');
        $redirect = (string) config('services.mercadopago.redirect_uri');

        if ($clientId === '' || $redirect === '') {
            throw new RuntimeException('Mercado Pago OAuth no está configurado.');
        }

        return 'https://auth.mercadopago.com/authorization?' . http_build_query([
            'client_id' => $clientId,
            'response_type' => 'code',
            'state' => $state,
            'redirect_uri' => $redirect,
            'scope' => 'read write offline_access',
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
        ]);
    }

    public function exchangeAuthorizationCode(string $code, string $codeVerifier): array
    {
        $response = Http::acceptJson()->timeout(20)->post(self::API . '/oauth/token', [
            'client_secret' => config('services.mercadopago.client_secret'),
            'client_id' => config('services.mercadopago.client_id'),
            'grant_type' => 'authorization_code',
            'code' => $code,
            'code_verifier' => $codeVerifier,
            'redirect_uri' => config('services.mercadopago.redirect_uri'),
        ]);

        if (!$response->successful()) {
            throw new RuntimeException($this->apiError('Mercado Pago rechazó la vinculación OAuth.', $response));
        }

        return $response->json();
    }

    public function refreshAccessToken(VendingPartner $partner): string
    {
        if (!$partner->mercadopago_refresh_token) {
            throw new RuntimeException('La autorización de Mercado Pago venció. Volvé a vincular la cuenta del comercio.');
        }

        $response = Http::acceptJson()->timeout(20)->post(self::API . '/oauth/token', [
            'client_secret' => config('services.mercadopago.client_secret'),
            'client_id' => config('services.mercadopago.client_id'),
            'grant_type' => 'refresh_token',
            'refresh_token' => $partner->mercadopago_refresh_token,
        ]);

        if (!$response->successful()) {
            throw new RuntimeException($this->apiError('No se pudo renovar la autorización de Mercado Pago.', $response));
        }

        $data = $response->json();
        $partner->update([
            'mercadopago_access_token' => $data['access_token'] ?? $partner->mercadopago_access_token,
            'mercadopago_refresh_token' => $data['refresh_token'] ?? $partner->mercadopago_refresh_token,
            'mercadopago_token_expires_at' => !empty($data['expires_in'])
                ? now()->addSeconds((int) $data['expires_in'])
                : null,
            'mercadopago_user_id' => isset($data['user_id']) ? (string) $data['user_id'] : $partner->mercadopago_user_id,
        ]);

        return (string) $partner->fresh()->mercadopago_access_token;
    }

    public function provisionPartnerStore(VendingPartner $partner): VendingPartner
    {
        $partner->refresh();
        if (!$partner->hasMercadoPagoConnection()) {
            throw new RuntimeException('Primero vinculá la cuenta de Mercado Pago del comercio.');
        }
        if (!$partner->hasCompleteLocation()) {
            throw new RuntimeException('Completá calle, número, ciudad, provincia, latitud y longitud antes de crear la sucursal en Mercado Pago.');
        }

        $externalId = $partner->mercadopago_external_store_id ?: $this->storeExternalId($partner);

        if (!$partner->mercadopago_store_id) {
            $search = $this->authorizedRequest($partner, 'get', '/users/' . urlencode($partner->mercadopago_user_id) . '/stores/search', [
                'external_id' => $externalId,
            ]);

            if ($search->successful()) {
                $searchData = $search->json();
                $existing = data_get($searchData, 'results.0') ?? data_get($searchData, '0.results.0');
                if (is_array($existing) && !empty($existing['id'])) {
                    $partner->update([
                        'mercadopago_store_id' => (string) $existing['id'],
                        'mercadopago_external_store_id' => $externalId,
                    ]);
                    return $partner->fresh();
                }
            }

            $response = $this->authorizedRequest($partner, 'post', '/users/' . urlencode($partner->mercadopago_user_id) . '/stores', [
                'name' => mb_substr($partner->name, 0, 60),
                'external_id' => $externalId,
                'location' => [
                    'street_name' => $partner->street_name,
                    'street_number' => $partner->street_number,
                    'city_name' => $partner->city_name,
                    'state_name' => $partner->state_name,
                    'latitude' => (float) $partner->latitude,
                    'longitude' => (float) $partner->longitude,
                    'reference' => $partner->location_reference ?: null,
                ],
            ]);

            if (!$response->successful()) {
                throw new RuntimeException($this->apiError('No se pudo crear la sucursal en Mercado Pago.', $response));
            }

            $data = $response->json();
            $partner->update([
                'mercadopago_store_id' => isset($data['id']) ? (string) $data['id'] : null,
                'mercadopago_external_store_id' => $externalId,
            ]);
        }

        return $partner->fresh();
    }

    public function provisionMachine(VendingMachine $machine, bool $forceOrder = false): VendingMachine
    {
        $machine->loadMissing('partner', 'product');

        $partner = $this->provisionPartnerStore($machine->partner);
        $externalPosId = $machine->mercadopago_external_pos_id ?: $this->posExternalId($machine);

        if (!$machine->mercadopago_pos_id) {
            $search = $this->authorizedRequest($partner, 'get', '/v2/pos', [
                'external_id' => $externalPosId,
                'limit' => 1,
            ]);
            $pos = $search->successful() ? data_get($search->json(), 'data.0') : null;

            if (!is_array($pos) || empty($pos['id'])) {
                $response = $this->authorizedRequest($partner, 'post', '/v2/pos', [
                    'name' => $this->safePosName($machine->name ?: $machine->code),
                    'store_id' => (string) $partner->mercadopago_store_id,
                    'external_id' => $externalPosId,
                    'config' => ['qr' => ['operating_mode' => 'pdv']],
                ], true);

                if (!$response->successful()) {
                    throw new RuntimeException($this->apiError('No se pudo crear la caja/QR de la máquina en Mercado Pago.', $response));
                }
                $pos = $response->json();
            }

            $machine->update([
                'mercadopago_pos_id' => isset($pos['id']) ? (string) $pos['id'] : null,
                'mercadopago_external_pos_id' => $externalPosId,
                'mercadopago_qr_image_url' => data_get($pos, 'qr_response.image'),
                'mercadopago_qr_template_image_url' => data_get($pos, 'qr_response.template_image'),
                'mercadopago_qr_code' => data_get($pos, 'qr_response.qr_code'),
                'last_provisioned_at' => now(),
            ]);
        }

        $this->createStaticOrder($machine->fresh(['partner', 'product']), $forceOrder);
        return $machine->fresh(['partner', 'product']);
    }

    public function cancelActiveOrders(VendingMachine $machine): void
    {
        $machine->loadMissing('partner');
        $partner = $machine->partner;
        if (!$partner || !$partner->hasMercadoPagoConnection()) return;

        $orders = $machine->paymentOrders()
            ->whereIn('status', ['created', 'pending'])
            ->whereNotNull('mercadopago_order_id')
            ->get();

        foreach ($orders as $order) {
            $response = $this->cancelRequest($partner, (string) $order->mercadopago_order_id);

            if ($response->successful()) {
                $order->update(['status' => 'canceled', 'payload' => $response->json()]);
                continue;
            }

            if ($response->status() === 409) {
                $current = $this->fetchOrder($partner, (string) $order->mercadopago_order_id);
                $currentStatus = (string) ($current['status'] ?? 'unknown');
                $order->update(['status' => $currentStatus, 'payload' => $current]);

                if (in_array($currentStatus, ['processed', 'refunded', 'expired', 'canceled'], true)) {
                    continue;
                }

                throw new RuntimeException('Hay un pago en curso en esta máquina. Esperá a que Mercado Pago confirme su estado antes de cambiar el cobro.');
            }

            throw new RuntimeException($this->apiError('No se pudo cancelar una orden QR anterior.', $response));
        }
    }

    public function createStaticOrder(VendingMachine $machine, bool $force = false): VendingPaymentOrder
    {
        $machine->loadMissing('partner', 'product');
        $partner = $machine->partner;

        if (!$partner || !$partner->hasMercadoPagoConnection()) {
            throw new RuntimeException('El comercio no tiene Mercado Pago vinculado.');
        }
        if (!$machine->mercadopago_external_pos_id) {
            throw new RuntimeException('La máquina no tiene una caja de Mercado Pago configurada.');
        }

        $activeQuery = $machine->paymentOrders()
            ->whereIn('status', ['created', 'pending'])
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now()->addMinute());
            });

        if (!$force && ($existing = $activeQuery->latest('id')->first())) {
            return $existing;
        }

        if ($force) {
            $this->cancelActiveOrders($machine);
            $machine->paymentOrders()->whereIn('status', ['created', 'pending'])->update(['status' => 'superseded']);
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
            'transactions' => ['payments' => [['amount' => $amount]]],
        ];

        $response = $this->authorizedRequest($partner, 'post', '/v1/orders', $payload, true);
        if (!$response->successful()) {
            throw new RuntimeException($this->apiError('No se pudo preparar el cobro QR en Mercado Pago.', $response));
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
        $response = $this->authorizedRequest($partner, 'get', '/v1/orders/' . urlencode($orderId));
        if (!$response->successful()) {
            throw new RuntimeException($this->apiError('No se pudo consultar la orden en Mercado Pago.', $response));
        }
        return $response->json();
    }

    public function processVerifiedWebhook(array $payload): ?VendingSale
    {
        $resourceId = (string) data_get($payload, 'data.id', '');
        if ($resourceId === '') return null;

        $paymentOrder = VendingPaymentOrder::with('partner')
            ->where('mercadopago_order_id', $resourceId)
            ->first();

        if (!$paymentOrder) {
            $mpUserId = (string) ($payload['user_id'] ?? '');
            if ($mpUserId === '') return null;
            $partner = VendingPartner::where('mercadopago_user_id', $mpUserId)->first();
            if (!$partner || !$partner->hasMercadoPagoConnection()) return null;
            $order = $this->fetchOrder($partner, $resourceId);
            $externalReference = (string) ($order['external_reference'] ?? '');
            $paymentOrder = VendingPaymentOrder::where('external_reference', $externalReference)
                ->where('vending_partner_id', $partner->id)
                ->first();
            if (!$paymentOrder) return null;
        } else {
            $partner = $paymentOrder->partner;
            if (!$partner || !$partner->hasMercadoPagoConnection()) return null;
            $order = $this->fetchOrder($partner, $resourceId);
        }

        $this->validateFetchedOrder($order, $paymentOrder);

        $saleToNotify = null;

        DB::transaction(function () use ($order, $paymentOrder, $partner, &$saleToNotify) {
            $paymentOrder = VendingPaymentOrder::whereKey($paymentOrder->id)->lockForUpdate()->firstOrFail();
            $status = (string) ($order['status'] ?? 'unknown');
            $statusDetail = (string) ($order['status_detail'] ?? '');

            $paymentOrder->update([
                'status' => $status,
                'processed_at' => in_array($status, ['processed', 'refunded'], true) ? now() : $paymentOrder->processed_at,
                'payload' => $order,
            ]);

            $orderId = (string) ($order['id'] ?? $paymentOrder->mercadopago_order_id);
            $existing = VendingSale::where('mercadopago_order_id', $orderId)->lockForUpdate()->first();

            $saleStatus = match (true) {
                $status === 'refunded' || $statusDetail === 'refunded' => 'refunded',
                $status === 'processed' && $statusDetail === 'partially_refunded' => 'partially_refunded',
                $status === 'processed' => 'approved',
                default => null,
            };

            if ($saleStatus === null) return;

            $gross = $existing
                ? (float) $existing->gross_amount
                : (float) ($order['total_paid_amount'] ?? $order['total_amount'] ?? $paymentOrder->amount);

            $refunded = match ($saleStatus) {
                'refunded' => $gross,
                'partially_refunded' => min($gross, $this->refundedAmount($order)),
                default => 0.0,
            };
            $net = max(0, $gross - $refunded);
            $commissionPercent = 0.0;
            $commissionAmount = 0.0;
            $factoryAmount = round($net, 2);

            if ($existing) {
                $changed = $existing->status !== $saleStatus
                    || abs((float) $existing->refunded_amount - $refunded) > 0.009
                    || abs((float) $existing->factory_amount - $factoryAmount) > 0.009;

                $existing->update([
                    'refunded_amount' => $refunded,
                    'commission_percent' => 0,
                    'commission_amount' => 0,
                    'factory_amount' => $factoryAmount,
                    'status' => $saleStatus,
                    'payload' => $order,
                ]);

                if ($changed) $saleToNotify = $existing->fresh(['machine', 'partner', 'product']);
                return;
            }

            $machine = VendingMachine::whereKey($paymentOrder->vending_machine_id)->lockForUpdate()->firstOrFail();
            $paymentId = data_get($order, 'transactions.payments.0.id');

            $sale = VendingSale::create([
                'company_id' => $paymentOrder->company_id,
                'vending_partner_id' => $partner->id,
                'vending_machine_id' => $machine->id,
                'product_id' => $machine->product_id,
                'vending_payment_order_id' => $paymentOrder->id,
                'mercadopago_order_id' => $orderId,
                'mercadopago_payment_id' => $paymentId ? (string) $paymentId : null,
                'external_reference' => $paymentOrder->external_reference,
                'gross_amount' => $gross,
                'refunded_amount' => $refunded,
                'commission_percent' => $commissionPercent,
                'commission_amount' => $commissionAmount,
                'factory_amount' => $factoryAmount,
                'status' => $saleStatus,
                'sold_at' => now(),
                'payload' => $order,
            ]);

            $sale->update([
                'receipt_number' => 'VM-' . now()->format('Ymd') . '-' . str_pad((string) $sale->id, 7, '0', STR_PAD_LEFT),
            ]);

            $machine->update(['last_sale_at' => now()]);
            $saleToNotify = $sale->fresh(['machine', 'partner', 'product']);
        });

        $status = (string) ($order['status'] ?? '');
        if (in_array($status, ['processed', 'refunded', 'expired', 'canceled'], true)) {
            RefreshVendingMachineOrderJob::dispatch($paymentOrder->vending_machine_id)->delay(now()->addSeconds(2));
        }

        if ($saleToNotify) NotifyVendingSaleJob::dispatch($saleToNotify->id);
        return $saleToNotify;
    }

    private function validateFetchedOrder(array $order, VendingPaymentOrder $paymentOrder): void
    {
        $providerOrderId = (string) ($order['id'] ?? '');
        $expectedOrderId = (string) ($paymentOrder->mercadopago_order_id ?? '');

        if ($providerOrderId === '' || ($expectedOrderId !== '' && !hash_equals($expectedOrderId, $providerOrderId))) {
            throw new RuntimeException('Mercado Pago devolvió una orden distinta a la esperada.');
        }

        $externalReference = (string) ($order['external_reference'] ?? '');
        if ($externalReference === '' || !hash_equals((string) $paymentOrder->external_reference, $externalReference)) {
            throw new RuntimeException('La referencia de la orden de Mercado Pago no coincide con la máquina esperada.');
        }

        $expectedAmount = round((float) $paymentOrder->amount, 2);
        $providerAmount = (float) (
            $order['total_amount']
            ?? data_get($order, 'transactions.payments.0.amount')
            ?? 0
        );

        if ($providerAmount <= 0 || abs(round($providerAmount, 2) - $expectedAmount) > 0.009) {
            throw new RuntimeException('El importe confirmado por Mercado Pago no coincide con el precio esperado de la máquina.');
        }

        $machine = VendingMachine::whereKey($paymentOrder->vending_machine_id)->first();
        if (!$machine
            || (int) $machine->company_id !== (int) $paymentOrder->company_id
            || (int) $machine->vending_partner_id !== (int) $paymentOrder->vending_partner_id) {
            throw new RuntimeException('La orden no coincide con la máquina o el comercio registrados.');
        }

        $providerPos = (string) data_get($order, 'config.qr.external_pos_id', '');
        if ($providerPos !== ''
            && filled($machine->mercadopago_external_pos_id)
            && !hash_equals((string) $machine->mercadopago_external_pos_id, $providerPos)) {
            throw new RuntimeException('La caja de Mercado Pago de la orden no coincide con esta máquina.');
        }
    }

    private function refundedAmount(array $order): float
    {
        $refunds = data_get($order, 'transactions.refunds', []);
        if (!is_array($refunds)) return 0.0;

        $total = 0.0;
        foreach ($refunds as $refund) {
            if (!is_array($refund)) continue;
            $status = (string) ($refund['status'] ?? '');
            if (in_array($status, ['failed', 'canceled', 'cancelled'], true)) continue;
            $total += (float) ($refund['amount'] ?? 0);
        }
        return round($total, 2);
    }

    private function cancelRequest(VendingPartner $partner, string $orderId): Response
    {
        $send = function (string $token) use ($orderId) {
            return Http::withToken($token)
                ->acceptJson()
                ->withHeaders(['X-Idempotency-Key' => (string) Str::uuid()])
                ->timeout(20)
                ->send('POST', self::API . '/v1/orders/' . urlencode($orderId) . '/cancel');
        };

        $response = $send($this->validAccessToken($partner));
        if ($response->status() === 401 && $partner->mercadopago_refresh_token) {
            $response = $send($this->refreshAccessToken($partner));
        }
        return $response;
    }

    private function authorizedRequest(
        VendingPartner $partner,
        string $method,
        string $path,
        array $data = [],
        bool $idempotent = false
    ): Response {
        $token = $this->validAccessToken($partner);
        $request = Http::withToken($token)->acceptJson()->timeout(20);
        if ($idempotent) $request = $request->withHeaders(['X-Idempotency-Key' => (string) Str::uuid()]);

        $response = strtolower($method) === 'get'
            ? $request->get(self::API . $path, $data)
            : $request->{strtolower($method)}(self::API . $path, $data);

        if ($response->status() === 401 && $partner->mercadopago_refresh_token) {
            $request = Http::withToken($this->refreshAccessToken($partner))->acceptJson()->timeout(20);
            if ($idempotent) $request = $request->withHeaders(['X-Idempotency-Key' => (string) Str::uuid()]);
            $response = strtolower($method) === 'get'
                ? $request->get(self::API . $path, $data)
                : $request->{strtolower($method)}(self::API . $path, $data);
        }

        return $response;
    }

    private function validAccessToken(VendingPartner $partner): string
    {
        if (!$partner->mercadopago_access_token) {
            throw new RuntimeException('El comercio no tiene Mercado Pago vinculado.');
        }

        if ($partner->mercadopago_token_expires_at
            && $partner->mercadopago_token_expires_at->lte(now()->addDays(7))) {
            return $this->refreshAccessToken($partner);
        }

        return (string) $partner->mercadopago_access_token;
    }

    private function storeExternalId(VendingPartner $partner): string
    {
        return 'GF' . $partner->company_id . 'K' . $partner->id;
    }

    private function posExternalId(VendingMachine $machine): string
    {
        return 'GF' . $machine->company_id . 'M' . $machine->id;
    }

    private function safePosName(string $name): string
    {
        $name = preg_replace('/[^A-Za-z0-9 _-]+/', '', Str::ascii($name)) ?: 'POS';
        return mb_substr(trim($name), 0, 45);
    }

    private function apiError(string $prefix, Response $response): string
    {
        $message = data_get($response->json(), 'message')
            ?? data_get($response->json(), 'error.message')
            ?? data_get($response->json(), 'cause.0.description');

        return $message ? $prefix . ' ' . $message : $prefix . ' HTTP ' . $response->status() . '.';
    }
}
