<?php

namespace App\Livewire\Products;

use App\Models\Product;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Index extends Component
{
    public string $search = '';

    public function render()
    {
        $products = Product::with(['category', 'baseUnit', 'presentations'])
            ->when($this->search, function ($query) {
                $query->where(function ($q) {
                    $q->where('name', 'like', "%{$this->search}%")
                      ->orWhere('internal_code', 'like', "%{$this->search}%")
                      ->orWhere('barcode', 'like', "%{$this->search}%");
                });
            })
            ->orderBy('name')
            ->get();

        return view('livewire.products.index', compact('products'));
    }

    public function delete(int $productId): void
    {
        $product = Product::findOrFail($productId);
        $product->delete();

        session()->flash('message', "Producto '{$product->name}' eliminado.");
    }
}
