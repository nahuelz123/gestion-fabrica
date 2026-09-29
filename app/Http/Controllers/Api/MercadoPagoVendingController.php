<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessMercadoPagoWebhookJob;
use App\Models\VendingPartner;
use App\Services\MercadoPagoVendingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class MercadoPagoVendingController extends Controller
{
    public function connect(Request $request, VendingPartner $partner, MercadoPagoVendingService $service)
    {
        abort_unless(auth()->user()?->isOwner(), 403);
        abort_unless($partner->company_id === auth()->user()->company_id, 404);

        $state = Str::random(48);
        $verifier = Str::random(96);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        session([
            'mp_oauth_state' => $state,
            'mp_oauth_partner_id' => $partner->id,
            'mp_oauth_code_verifier' => $verifier,
        ]);

        return redirect()->away($service->authorizationUrl($state, $challenge));
    }

    public function callback(Request $request, MercadoPagoVendingService $service)
    {
        abort_unless(auth()->user()?->isOwner(), 403);

        if ($request->filled('error')) {
            return redirect()->route('vending.partners.index')
                ->with('error', 'Mercado Pago no autorizó la vinculación: ' . (string) $request->query('error_description', $request->query('error')));
        }

        $state = (string) $request->query('state', '');
        $expected = (string) session()->pull('mp_oauth_state', '');
        $partnerId = session()->pull('mp_oauth_partner_id');
        $verifier = (string) session()->pull('mp_oauth_code_verifier', '');

        abort_if($state === '' || $expected === '' || !hash_equals($expected, $state), 403, 'Estado OAuth inválido.');
        abort_if(!$partnerId || !$request->filled('code') || $verifier === '', 422, 'Faltan datos para completar la vinculación.');

        $partner = VendingPartner::where('company_id', auth()->user()->company_id)->findOrFail($partnerId);

        try {
            $tokens = $service->exchangeAuthorizationCode((string) $request->query('code'), $verifier);
            $partner->update([
                'mercadopago_user_id' => isset($tokens['user_id']) ? (string) $tokens['user_id'] : null,
                'mercadopago_access_token' => $tokens['access_token'] ?? null,
                'mercadopago_refresh_token' => $tokens['refresh_token'] ?? null,
                'mercadopago_token_expires_at' => !empty($tokens['expires_in'])
                    ? now()->addSeconds((int) $tokens['expires_in'])
                    : null,
            ]);

            if ($partner->hasCompleteLocation()) {
                $service->provisionPartnerStore($partner->fresh());
            }
        } catch (Throwable $e) {
            report($e);
            return redirect()->route('vending.partners.edit', $partner->id)
                ->with('error', $e->getMessage());
        }

        return redirect()->route('vending.partners.index')
            ->with('message', "Mercado Pago quedó vinculado a {$partner->name}.");
    }

    public function webhook(Request $request)
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
            'attempts' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($inserted === 0) {
            return response()->json(['status' => 'duplicate']);
        }

        ProcessMercadoPagoWebhookJob::dispatch($eventId);
        return response()->json(['status' => 'accepted'], 202);
    }

    private function validSignature(Request $request, string $secret): bool
    {
        $signature = (string) $request->header('x-signature', '');
        $requestId = (string) $request->header('x-request-id', '');

        // Algunos stacks PHP normalizan los puntos de los parámetros de query
        // (data.id -> data_id). Aceptamos ambas variantes y, como último recurso,
        // el data.id del body. El valor sigue usándose únicamente para validar HMAC.
        $dataId = (string) (
            $request->query->get('data.id')
            ?? $request->query->get('data_id')
            ?? data_get($request->json()->all(), 'data.id')
            ?? ''
        );

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

        $manifest = 'id:' . mb_strtolower($dataId)
            . ';request-id:' . $requestId
            . ';ts:' . $ts . ';';

        return hash_equals(hash_hmac('sha256', $manifest, $secret), $v1);
    }
}
