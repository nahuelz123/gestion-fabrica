<?php

namespace App\Livewire\Products;

use App\Models\Product;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
class Index extends Component
{
    use WithPagination;

    public string $search = '';
    public int $perPage = 50;

    public function mount(): void
    {
        Gate::authorize('owner-only');
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $companyId = auth()->user()->company_id;
        $search = trim($this->search);

        $products = Product::query()
            ->where('company_id', $companyId)
            ->with(['category:id,name', 'baseUnit:id,name,abbreviation', 'presentations:id,product_id,name,conversion_factor,is_purchase_default'])
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('internal_code', 'like', "{$search}%")
                      ->orWhere('barcode', $search)
                      ->orWhereHas('aliases', fn ($aliasQuery) => $aliasQuery->where('alias', 'like', "%{$search}%"));
                });
            })
            ->orderBy('name')
            ->paginate(max(10, min($this->perPage, 100)));

        return view('livewire.products.index', compact('products'));
    }

    public function delete(int $productId): void
    {
        Gate::authorize('owner-only');

        $product = Product::where('company_id', auth()->user()->company_id)
            ->findOrFail($productId);

        $product->delete();

        session()->flash('message', "Producto '{$product->name}' eliminado.");
    }
}
