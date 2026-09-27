<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessTelegramMessageJob;
use Illuminate\Http\Request;

class TelegramWebhookController extends Controller
{
    public function handle(Request $request)
    {
        $provided = (string) $request->header('X-Telegram-Bot-Api-Secret-Token', '');
        $expected = (string) config('services.telegram.webhook_secret', '');

        // Comparación timing-safe y fail-closed si la configuración falta.
        if ($expected === '' || $provided === '' || !hash_equals($expected, $provided)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // Un update normal de Telegram es pequeño. Rechazamos payloads anómalos
        // para no llenar la cola/memoria con requests maliciosos.
        $contentLength = (int) $request->header('Content-Length', 0);
        if ($contentLength > 262144) {
            return response()->json(['error' => 'Payload too large'], 413);
        }

        $payload = $request->all();
        if (!isset($payload['update_id']) || !is_numeric($payload['update_id'])) {
            return response()->json(['error' => 'Invalid update'], 422);
        }

        ProcessTelegramMessageJob::dispatch($payload);

        return response()->json(['status' => 'ok']);
    }
}
