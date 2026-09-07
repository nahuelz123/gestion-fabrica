<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\BotAgentService;
use App\Services\TelegramService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\QueryException;

class ProcessTelegramMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public array $payload)
    {
    }

    public function handle(BotAgentService $botAgentService, TelegramService $telegramService): void
    {
        $updateId = $this->payload['update_id'] ?? null;
        if (!$updateId) return;

        // Idempotency check: attempt to insert the update_id
        try {
            DB::table('telegram_processed_updates')->insert([
                'update_id' => $updateId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException $e) {
            // If it's a duplicate entry error, it means we already processed this webhook.
            // Return silently.
            return;
        }

        $message = $this->payload['message'] ?? null;
        if (!$message || !isset($message['text'])) return;

        $chatId = (string) $message['chat']['id'];
        $text = $message['text'];

        $user = User::where('telegram_chat_id', $chatId)->where('status', 'active')->first();

        if (!$user) {
            $telegramService->sendMessage($chatId, "No estás registrado para usar este asistente.");
            return;
        }

        $botAgentService->processMessage($user, $chatId, $text);
    }
}
