<?php

namespace App\Livewire\Inventory;

use App\Models\Stock;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
class StockIndex extends Component
{
    use WithPagination;

    public string $search = '';
    public int $perPage = 50;

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $companyId = auth()->user()->company_id;
        $search = trim($this->search);

        $stocks = Stock::query()
            ->where('stock.company_id', $companyId)
            ->join('products', function ($join) use ($companyId) {
                $join->on('stock.product_id', '=', 'products.id')
                    ->where('products.company_id', '=', $companyId);
            })
            ->with([
                'product:id,company_id,name,internal_code,base_unit_id,min_stock',
                'product.baseUnit:id,abbreviation',
                'warehouse:id,name',
                'lot:id,lot_code,expiration_date',
            ])
            ->when($search !== '', function ($query) use ($search, $companyId) {
                $query->where(function ($q) use ($search, $companyId) {
                    $q->where('products.name', 'like', "%{$search}%")
                        ->orWhere('products.internal_code', 'like', "{$search}%")
                        ->orWhere('products.barcode', $search)
                        ->orWhereExists(function ($aliasQuery) use ($search, $companyId) {
                            $aliasQuery->selectRaw('1')
                                ->from('product_aliases')
                                ->whereColumn('product_aliases.product_id', 'products.id')
                                ->where('product_aliases.company_id', $companyId)
                                ->where('product_aliases.alias', 'like', "%{$search}%");
                        });
                });
            })
            ->orderBy('products.name')
            ->orderBy('stock.warehouse_id')
            ->select('stock.*')
            ->paginate(max(10, min($this->perPage, 100)));

        return view('livewire.inventory.stock-index', compact('stocks'));
    }
}
