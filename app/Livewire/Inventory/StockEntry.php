<?php

namespace App\Livewire\Inventory;

use App\Enums\Channel;
use App\Enums\MovementType;
use App\Models\Product;
use App\Models\Warehouse;
use App\Services\ProductService;
use App\Services\StockService;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class StockEntry extends Component
{
    public string $product_id = '';
    public string $warehouse_id = '';
    public string $presentation_id = '';
    public string $quantity = '';
    public string $reason = '';
    
    // Lot fields
    public string $lot_code = '';
    public ?string $expiration_date = null;

    public function getSelectedProductProperty(): ?Product
    {
        if (!$this->product_id) return null;
        return Product::with('presentations')->find($this->product_id);
    }

    public function save(ProductService $productService, StockService $stockService)
    {
        $product = $this->selectedProduct;
        
        $rules = [
            'product_id' => 'required|exists:products,id',
            'warehouse_id' => 'required|exists:warehouses,id',
            'quantity' => 'required|numeric|min:0.01',
        ];

        if ($product && $product->requires_lot) {
            $rules['lot_code'] = 'required|string|max:50';
            if ($product->requires_expiration) {
                $rules['expiration_date'] = 'required|date';
            }
        }

        $this->validate($rules);

        $presentation = null;
        if ($this->presentation_id) {
            $presentation = $product->presentations->firstWhere('id', $this->presentation_id);
        }

        $conversion = $productService->resolveToBaseUnit($product, $presentation, (float) $this->quantity);

        $lotData = [];
        if ($product->requires_lot) {
            $lotData = [
                'lot_code' => $this->lot_code,
                'expiration_date' => $this->expiration_date,
            ];
            // Calculate default expiration if missing but required
            if (!$this->expiration_date && $product->requires_expiration && $product->shelf_life_days) {
                $lotData['expiration_date'] = now()->addDays($product->shelf_life_days)->toDateString();
            }
        }

        $data = [
            'company_id' => auth()->user()->company_id,
            'product_id' => $product->id,
            'warehouse_id' => (int) $this->warehouse_id,
            'type' => MovementType::AdjustmentIn, // Specific inbound adjustment
            'quantity_base' => $conversion['quantity_base'],
            'presentation_id' => $conversion['presentation_id'],
            'presentation_quantity' => $conversion['presentation_quantity'],
            'user_id' => auth()->id(),
            'channel' => Channel::Web,
            'reason' => $this->reason,
            'lot_data' => $lotData,
        ];

        $stockService->registerMovement($data);

        session()->flash('message', "Entrada registrada correctamente ({$conversion['quantity_base']} unidades base).");
        return $this->redirect(route('inventory.index'), navigate: true);
    }

    public function render()
    {
        return view('livewire.inventory.stock-entry', [
            'products' => Product::where('status', 'active')->orderBy('name')->get(),
            'warehouses' => Warehouse::orderBy('name')->get(),
        ]);
    }
}
