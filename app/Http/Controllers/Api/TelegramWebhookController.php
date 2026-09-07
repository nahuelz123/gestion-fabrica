<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessTelegramMessageJob;
use Illuminate\Http\Request;

class TelegramWebhookController extends Controller
{
    public function handle(Request $request)
    {
        $secretToken = $request->header('X-Telegram-Bot-Api-Secret-Token');
        
        if (!$secretToken || $secretToken !== env('TELEGRAM_WEBHOOK_SECRET')) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // Return fast, process in background
        ProcessTelegramMessageJob::dispatch($request->all());

        return response()->json(['status' => 'ok']);
    }
}
