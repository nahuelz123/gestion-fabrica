<?php

namespace App\Jobs;

use App\Models\VendingMachine;
use App\Services\MercadoPagoVendingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class RefreshVendingMachineOrderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [5, 20, 60];

    public function __construct(public int $machineId)
    {
    }

    public function handle(MercadoPagoVendingService $service): void
    {
        $machine = VendingMachine::with(['partner', 'product'])->find($this->machineId);

        // Si no hay mercadería no preparamos un nuevo cobro: evita que alguien pague
        // cuando la máquina ya quedó vacía.
        if (!$machine || $machine->status !== 'active' || $machine->loaded_units <= 0) {
            return;
        }

        try {
            if (!$machine->mercadopago_pos_id) {
                $service->provisionMachine($machine);
            } else {
                $service->createStaticOrder($machine);
            }
        } catch (Throwable $e) {
            report($e);
            throw $e;
        }
    }
}
