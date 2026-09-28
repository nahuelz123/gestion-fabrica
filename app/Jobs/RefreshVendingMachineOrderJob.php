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

        if (!$machine || $machine->status !== 'active') {
            return;
        }

        try {
            $service->createStaticOrder($machine);
        } catch (Throwable $e) {
            report($e);
            throw $e;
        }
    }
}
