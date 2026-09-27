<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\BotAgentService;
use App\Services\TelegramService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

class ProcessTelegramMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 60;

    public function backoff(): array
    {
        return [5, 20, 60];
    }

    public function __construct(public array $payload)
    {
    }

    public function handle(BotAgentService $botAgentService, TelegramService $telegramService): void
    {
        $updateId = $this->payload['update_id'] ?? null;
        if (!$updateId || !is_numeric($updateId)) {
            return;
        }

        try {
            DB::table('telegram_processed_updates')->insert([
                'update_id' => $updateId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException $e) {
            return;
        }

        $message = $this->payload['message'] ?? null;
        if (!is_array($message) || !isset($message['text']) || !is_string($message['text'])) {
            return;
        }

        $chat = $message['chat'] ?? null;
        if (!is_array($chat) || !isset($chat['id'])) {
            return;
        }

        // El bot de gestión trabaja en conversación privada para evitar exponer
        // inventario o aceptar confirmaciones desde grupos.
        if (($chat['type'] ?? 'private') !== 'private') {
            return;
        }

        $chatId = (string) $chat['id'];
        $text = trim($message['text']);
        if ($text === '') {
            return;
        }

        if (mb_strlen($text) > 2000) {
            $telegramService->sendMessage($chatId, 'El mensaje es demasiado largo. Enviámelo en partes más cortas.');
            return;
        }

        $user = User::where('telegram_chat_id', $chatId)
            ->where('status', 'active')
            ->first();

        if (!$user) {
            $telegramService->sendMessage($chatId, 'No estás registrado para usar este asistente.');
            return;
        }

        $rateKey = 'telegram-bot:' . $user->id;
        if (RateLimiter::tooManyAttempts($rateKey, 30)) {
            $seconds = RateLimiter::availableIn($rateKey);
            $telegramService->sendMessage($chatId, "Hay demasiadas consultas seguidas. Probá de nuevo en {$seconds} segundos.");
            return;
        }
        RateLimiter::hit($rateKey, 60);

        $botAgentService->processMessage($user, $chatId, $text);
    }
}
