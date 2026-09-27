<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramService
{
    public function sendMessage(string $chatId, string $text): void
    {
        $token = (string) config('services.telegram.bot_token', '');
        if ($token === '' || $text === '') {
            return;
        }

        // Telegram limita el texto por mensaje. Dividimos respuestas grandes
        // (por ejemplo inventarios con cientos/miles de productos) sin romper UTF-8.
        foreach ($this->splitMessage($text, 3500) as $chunk) {
            try {
                $response = Http::connectTimeout(5)
                    ->timeout(15)
                    ->retry(2, 500, throw: false)
                    ->post("https://api.telegram.org/bot{$token}/sendMessage", [
                        'chat_id' => $chatId,
                        'text' => $chunk,
                    ]);

                if (!$response->successful()) {
                    Log::warning('Telegram sendMessage failed', [
                        'status' => $response->status(),
                        'chat_id' => $chatId,
                    ]);
                }
            } catch (\Throwable $e) {
                Log::error('Telegram sendMessage exception', [
                    'chat_id' => $chatId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function splitMessage(string $text, int $maxLength): array
    {
        if (mb_strlen($text) <= $maxLength) {
            return [$text];
        }

        $chunks = [];
        $remaining = $text;

        while (mb_strlen($remaining) > $maxLength) {
            $candidate = mb_substr($remaining, 0, $maxLength);
            $lastNewline = mb_strrpos($candidate, "\n");

            $cut = ($lastNewline !== false && $lastNewline > (int) ($maxLength * 0.6))
                ? $lastNewline
                : $maxLength;

            $chunks[] = rtrim(mb_substr($remaining, 0, $cut));
            $remaining = ltrim(mb_substr($remaining, $cut));
        }

        if ($remaining !== '') {
            $chunks[] = $remaining;
        }

        return $chunks;
    }
}
