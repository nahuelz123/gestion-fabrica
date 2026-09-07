<?php

namespace App\Livewire\Inventory;

use App\Enums\Channel;
use App\Enums\MovementType;
use App\Models\Product;
use App\Models\Stock;
use App\Models\Warehouse;
use App\Services\ProductService;
use App\Services\StockService;
use Exception;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class StockExit extends Component
{
    public string $product_id = '';
    public string $warehouse_id = '';
    public string $lot_id = '';
    public string $presentation_id = '';
    public string $quantity = '';
    public string $reason = '';
    public string $exit_type = 'adjustment'; // adjustment or waste

    public function getSelectedProductProperty(): ?Product
    {
        if (!$this->product_id) return null;
        return Product::with('presentations')->find($this->product_id);
    }
    
    public function getAvailableLotsProperty()
    {
        if (!$this->product_id || !$this->warehouse_id) return collect();
        
        return Stock::with('lot')
            ->where('product_id', $this->product_id)
            ->where('warehouse_id', $this->warehouse_id)
            ->where('quantity', '>', 0)
            ->whereNotNull('lot_id')
            ->get()
            ->pluck('lot');
    }

    public function save(ProductService $productService, StockService $stockService)
    {
        $product = $this->selectedProduct;
        
        $rules = [
            'product_id' => 'required|exists:products,id',
            'warehouse_id' => 'required|exists:warehouses,id',
            'quantity' => 'required|numeric|min:0.01',
            'exit_type' => 'required|in:adjustment,waste',
        ];

        if ($product && $product->requires_lot) {
            $rules['lot_id'] = 'required|exists:stock_lots,id';
        }

        $this->validate($rules);

        $presentation = null;
        if ($this->presentation_id) {
            $presentation = $product->presentations->firstWhere('id', $this->presentation_id);
        }

        $conversion = $productService->resolveToBaseUnit($product, $presentation, (float) $this->quantity);

        $data = [
            'company_id' => auth()->user()->company_id,
            'product_id' => $product->id,
            'warehouse_id' => (int) $this->warehouse_id,
            'lot_id' => $this->lot_id ?: null,
            'type' => $this->exit_type === 'waste' ? MovementType::Waste : MovementType::AdjustmentOut,
            'quantity_base' => $conversion['quantity_base'], // Positive absolute value passed, StockService negates it based on isOutbound()
            'presentation_id' => $conversion['presentation_id'],
            'presentation_quantity' => $conversion['presentation_quantity'],
            'user_id' => auth()->id(),
            'channel' => Channel::Web,
            'reason' => $this->reason,
        ];

        try {
            $stockService->registerMovement($data);
            session()->flash('message', "Salida registrada correctamente.");
            return $this->redirect(route('inventory.index'), navigate: true);
        } catch (Exception $e) {
            $this->addError('quantity', $e->getMessage());
        }
    }

    public function render()
    {
        return view('livewire.inventory.stock-exit', [
            'products' => Product::where('status', 'active')->orderBy('name')->get(),
            'warehouses' => Warehouse::orderBy('name')->get(),
        ]);
    }
}
