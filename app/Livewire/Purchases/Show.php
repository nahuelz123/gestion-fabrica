<?php

namespace App\Livewire\Purchases;

use App\Enums\PurchaseStatus;
use App\Models\Purchase;
use App\Services\PurchaseService;
use Exception;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Show extends Component
{
    public Purchase $purchase;

    public function mount(int $id)
    {
        $this->purchase = Purchase::with(['supplier', 'user', 'warehouse', 'items.product.baseUnit', 'items.presentation'])
            ->where('company_id', auth()->user()->company_id)
            ->findOrFail($id);
    }

    public function confirmPurchase(PurchaseService $purchaseService)
    {
        try {
            $purchaseService->confirm($this->purchase->id, auth()->id());
            
            session()->flash('message', 'Compra confirmada. Se ha registrado el ingreso de stock.');
            return $this->redirect(route('purchases.index'), navigate: true);
        } catch (Exception $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function render()
    {
        return view('livewire.purchases.show');
    }
}
