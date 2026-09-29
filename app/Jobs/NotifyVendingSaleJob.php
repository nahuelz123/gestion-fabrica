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
                . "Recibo interno: {$safe($sale->receipt_number)}\n"
                . "Comercio: {$safe($sale->partner->name)}\n"
                . "Máquina: {$safe($sale->machine->name)}\n"
                . 'Cobro original: $' . number_format((float) $sale->gross_amount, 2, ',', '.') . "\n"
                . 'Reembolsado: $' . number_format((float) $sale->refunded_amount, 2, ',', '.') . "\n"
                . '<b>Saldo para la hamburguesería: $' . number_format((float) $sale->factory_amount, 2, ',', '.') . '</b>';
        } else {
            $message = "🍔 <b>Venta de máquina confirmada</b>\n"
                . "Recibo interno: {$safe($sale->receipt_number)}\n"
                . "Comercio: {$safe($sale->partner->name)}\n"
                . "Máquina: {$safe($sale->machine->name)}\n"
                . "Producto: {$safe($sale->product->name)}\n"
                . 'Total cobrado: $' . number_format((float) $sale->gross_amount, 2, ',', '.') . "\n"
                . 'Comisión kiosco: $' . number_format((float) $sale->commission_amount, 2, ',', '.') . "\n"
                . '<b>Para la hamburguesería: $' . number_format((float) $sale->factory_amount, 2, ',', '.') . '</b>';
        }

        User::where('company_id', $sale->company_id)
            ->whereNotNull('telegram_chat_id')
            ->where('status', 'active')
            ->whereIn('role', ['owner', 'manager'])
            ->pluck('telegram_chat_id')
            ->unique()
            ->each(fn ($chatId) => $telegram->sendMessage((string) $chatId, $message));
    }
}
