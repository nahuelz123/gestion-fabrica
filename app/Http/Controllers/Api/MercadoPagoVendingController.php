<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\VendingPartner;
use App\Services\MercadoPagoVendingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MercadoPagoVendingController extends Controller
{
    public function connect(Request $request, VendingPartner $partner, MercadoPagoVendingService $service)
    {
        abort_unless(auth()->user()?->isOwner(), 403);
        abort_unless($partner->company_id === auth()->user()->company_id, 404);

        $state = Str::random(48);
        session([
            'mp_oauth_state' => $state,
            'mp_oauth_partner_id' => $partner->id,
        ]);

        return redirect()->away($service->authorizationUrl($state));
    }

    public function callback(Request $request, MercadoPagoVendingService $service)
    {
        abort_unless(auth()->user()?->isOwner(), 403);

        $state = (string) $request->query('state', '');
        $expected = (string) session()->pull('mp_oauth_state', '');
        $partnerId = session()->pull('mp_oauth_partner_id');

        abort_if($state === '' || $expected === '' || !hash_equals($expected, $state), 403, 'Estado OAuth inválido.');
        abort_if(!$partnerId || !$request->filled('code'), 422, 'Faltan datos para completar la vinculación.');

        $partner = VendingPartner::where('company_id', auth()->user()->company_id)
            ->findOrFail($partnerId);

        $tokens = $service->exchangeAuthorizationCode((string) $request->query('code'));

        $partner->update([
            'mercadopago_user_id' => isset($tokens['user_id']) ? (string) $tokens['user_id'] : null,
            'mercadopago_access_token' => $tokens['access_token'] ?? null,
            'mercadopago_refresh_token' => $tokens['refresh_token'] ?? null,
            'mercadopago_token_expires_at' => !empty($tokens['expires_in'])
                ? now()->addSeconds((int) $tokens['expires_in'])
                : null,
        ]);

        return redirect()->route('dashboard')
            ->with('message', "Mercado Pago quedó vinculado a {$partner->name}.");
    }

    public function webhook(Request $request, MercadoPagoVendingService $service)
    {
        $secret = (string) config('services.mercadopago.webhook_secret');
        if ($secret === '') {
            return response()->json(['error' => 'Webhook not configured'], 503);
        }

        if (!$this->validSignature($request, $secret)) {
            return response()->json(['error' => 'Invalid signature'], 401);
        }

        $payload = $request->json()->all();
        $eventId = (string) ($payload['id'] ?? '');
        $resourceId = (string) data_get($payload, 'data.id', '');

        if ($eventId === '' || $resourceId === '') {
            return response()->json(['status' => 'ignored']);
        }

        $inserted = DB::table('mercadopago_webhook_events')->insertOrIgnore([
            'event_id' => $eventId,
            'resource_id' => $resourceId,
            'action' => $payload['action'] ?? null,
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($inserted === 0) {
            return response()->json(['status' => 'duplicate']);
        }

        // El webhook ya fue autenticado e idempotentado. El servicio vuelve a consultar
        // la order directamente a Mercado Pago antes de registrar una venta.
        $service->processVerifiedWebhook($payload);

        DB::table('mercadopago_webhook_events')
            ->where('event_id', $eventId)
            ->update(['processed_at' => now(), 'updated_at' => now()]);

        return response()->json(['status' => 'ok']);
    }

    private function validSignature(Request $request, string $secret): bool
    {
        $signature = (string) $request->header('x-signature', '');
        $requestId = (string) $request->header('x-request-id', '');
        $dataId = (string) $request->query('data.id', '');

        if ($signature === '' || $requestId === '' || $dataId === '') {
            return false;
        }

        $ts = null;
        $v1 = null;
        foreach (explode(',', $signature) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, null);
            if ($key === 'ts') $ts = $value;
            if ($key === 'v1') $v1 = $value;
        }

        if (!$ts || !$v1) {
            return false;
        }

        // Mercado Pago exige data.id en minúscula al validar firmas de orders.
        $manifest = 'id:' . mb_strtolower($dataId)
            . ';request-id:' . $requestId
            . ';ts:' . $ts . ';';

        $calculated = hash_hmac('sha256', $manifest, $secret);

        return hash_equals($calculated, $v1);
    }
}
