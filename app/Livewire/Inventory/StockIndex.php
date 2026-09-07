<?php

namespace App\Livewire\Inventory;

use App\Models\Stock;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class StockIndex extends Component
{
    public string $search = '';

    public function render()
    {
        $query = clone Stock::with(['product.baseUnit', 'warehouse', 'lot'])
            ->whereHas('product', function ($q) {
                $q->when($this->search, function ($sq) {
                    $sq->where('name', 'like', "%{$this->search}%")
                      ->orWhere('internal_code', 'like', "%{$this->search}%")
                      ->orWhere('barcode', 'like', "%{$this->search}%");
                });
            });
            
        // If we search, we don't necessarily group by exactly the same, but let's just fetch all stock rows
        $stocks = $query->orderBy('warehouse_id')
            ->get()
            // To group by product and warehouse for the view
            ->groupBy(function($item) {
                return $item->product_id . '-' . $item->warehouse_id;
            });
            
        // We will pass flat list but ordered, or calculate total per product
        
        $flatStocks = $query->join('products', 'stock.product_id', '=', 'products.id')
            ->orderBy('products.name')
            ->select('stock.*') // avoid column name collisions
            ->get();

        return view('livewire.inventory.stock-index', [
            'stocks' => $flatStocks,
        ]);
    }
}
