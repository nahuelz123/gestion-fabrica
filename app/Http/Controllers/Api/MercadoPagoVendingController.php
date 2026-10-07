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

        $returnTo = in_array($request->query('return'), ['machine', 'partners', 'machines'], true)
            ? (string) $request->query('return')
            : 'partners';

        if (!$this->mercadoPagoIntegrationReady()) {
            return redirect()->route('vending.partners.edit', ['id' => $partner->id, 'return' => $returnTo])
                ->with('error', 'Mercado Pago todavía no está configurado a nivel general para Rapi Burguer. El kiosco quedó guardado y podrá vincularse cuando se complete esa configuración única.');
        }

        $state = Str::random(48);
        $verifier = Str::random(96);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        session([
            'mp_oauth_state' => $state,
            'mp_oauth_partner_id' => $partner->id,
            'mp_oauth_code_verifier' => $verifier,
            'mp_oauth_return' => $returnTo,
        ]);

        try {
            $authorizationUrl = $service->authorizationUrl($state, $challenge);
        } catch (Throwable $e) {
            report($e);
            session()->forget([
                'mp_oauth_state',
                'mp_oauth_partner_id',
                'mp_oauth_code_verifier',
                'mp_oauth_return',
            ]);

            return redirect()->route('vending.partners.edit', ['id' => $partner->id, 'return' => $returnTo])
                ->with('error', 'No se pudo iniciar la vinculación con Mercado Pago. Revisá la configuración general de la integración.');
        }

        return redirect()->away($authorizationUrl);
    }

    public function callback(Request $request, MercadoPagoVendingService $service)
    {
        abort_unless(auth()->user()?->isOwner(), 403);

        $returnTo = (string) session()->get('mp_oauth_return', 'partners');

        if ($request->filled('error')) {
            session()->forget('mp_oauth_return');
            return redirect()->route('vending.partners.index')
                ->with('error', 'Mercado Pago no autorizó la vinculación: ' . (string) $request->query('error_description', $request->query('error')));
        }

        $state = (string) $request->query('state', '');
        $expected = (string) session()->pull('mp_oauth_state', '');
        $partnerId = session()->pull('mp_oauth_partner_id');
        $verifier = (string) session()->pull('mp_oauth_code_verifier', '');
        $returnTo = (string) session()->pull('mp_oauth_return', $returnTo);

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
            return redirect()->route('vending.partners.edit', ['id' => $partner->id])
                ->with('error', $e->getMessage());
        }

        if ($returnTo === 'machine') {
            return redirect()->route('vending.machines.create', ['partner' => $partner->id])
                ->with('message', "Mercado Pago quedó vinculado a {$partner->name}. Ahora terminá de cargar la máquina.");
        }

        if ($returnTo === 'machines') {
            return redirect()->route('vending.index')
                ->with('message', "Mercado Pago quedó vinculado a {$partner->name}. Ya podés sincronizar la máquina.");
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
            $existing = DB::table('mercadopago_webhook_events')
                ->where('event_id', $eventId)
                ->first();

            if ($existing && !$existing->processed_at) {
                ProcessMercadoPagoWebhookJob::dispatch($eventId);
                return response()->json(['status' => 'retry_accepted'], 202);
            }

            return response()->json(['status' => 'duplicate']);
        }

        ProcessMercadoPagoWebhookJob::dispatch($eventId);
        return response()->json(['status' => 'accepted'], 202);
    }

    private function mercadoPagoIntegrationReady(): bool
    {
        return filled(config('services.mercadopago.client_id'))
            && filled(config('services.mercadopago.client_secret'))
            && filled(config('services.mercadopago.redirect_uri'))
            && filled(config('services.mercadopago.webhook_secret'));
    }

    private function validSignature(Request $request, string $secret): bool
    {
        $signature = (string) $request->header('x-signature', '');
        $requestId = (string) $request->header('x-request-id', '');

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
