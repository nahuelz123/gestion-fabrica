<?php

namespace App\Jobs;

use App\Models\User;
use App\Models\VendingSale;
use App\Services\TelegramService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class NotifyVendingSaleJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public int $saleId)
    {
    }

    public function handle(TelegramService $telegram): void
    {
        $sale = VendingSale::with(['machine', 'partner', 'product'])->find($this->saleId);
        if (!$sale) return;

        $safe = fn ($value) => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        if ($sale->status === 'refunded' || $sale->status === 'partially_refunded') {
            $title = $sale->status === 'refunded' ? '↩️ <b>Venta reembolsada</b>' : '↩️ <b>Reembolso parcial</b>';
            $message = $title . "\n"
                . "Kiosco: {$safe($sale->partner->name)}\n"
                . "Máquina: {$safe($sale->machine->name)}\n"
                . "Producto: {$safe($sale->product->name)}\n"
                . 'Importe: $' . number_format(max(0, (float) $sale->gross_amount - (float) $sale->refunded_amount), 2, ',', '.');
        } else {
            $message = "🍔 <b>Nueva venta de máquina</b>\n"
                . "Kiosco: {$safe($sale->partner->name)}\n"
                . "Máquina: {$safe($sale->machine->name)}\n"
                . "Producto: {$safe($sale->product->name)}\n"
                . 'Importe: $' . number_format((float) $sale->gross_amount, 2, ',', '.');
        }

        User::where('company_id', $sale->company_id)
            ->whereNotNull('telegram_chat_id')
            ->where('status', 'active')
            ->where('role', 'owner')
            ->pluck('telegram_chat_id')
            ->unique()
            ->each(fn ($chatId) => $telegram->sendMessage((string) $chatId, $message));
    }
}
