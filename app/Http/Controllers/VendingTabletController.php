<?php

namespace App\Http\Controllers;

use App\Jobs\RefreshVendingMachineOrderJob;
use App\Models\VendingMachine;
use Illuminate\Http\Request;

class VendingTabletController extends Controller
{
    public function __invoke(Request $request, string $token)
    {
        $machine = VendingMachine::with(['partner', 'product'])
            ->where('public_token', $token)
            ->where('status', 'active')
            ->firstOrFail();

        $activeOrder = $machine->paymentOrders()
            ->whereIn('status', ['created', 'pending'])
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now()->addMinute());
            })
            ->latest('id')
            ->first();

        if (!$activeOrder && $machine->isReady()) {
            RefreshVendingMachineOrderJob::dispatch($machine->id);
        }

        return response()
            ->view('vending.tablet', compact('machine', 'activeOrder'))
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
    }
}
