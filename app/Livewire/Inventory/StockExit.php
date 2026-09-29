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
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class StockExit extends Component
{
    public string $productSearch = '';
    public string $product_id = '';
    public string $warehouse_id = '';
    public string $lot_id = '';
    public string $presentation_id = '';
    public string $quantity = '';
    public string $reason = '';
    public string $exit_type = 'adjustment';

    public function mount(): void
    {
        Gate::authorize('manage-stock');
        $warehouse = Warehouse::where('company_id', auth()->user()->company_id)->orderBy('id')->first();
        $this->warehouse_id = $warehouse ? (string) $warehouse->id : '';
    }
    public function updatedProductId(): void { $this->presentation_id = ''; $this->lot_id = ''; }
    public function updatedWarehouseId(): void { $this->lot_id = ''; }

    public function getSelectedProductProperty(): ?Product
    {
        if (!$this->product_id) return null;
        return Product::where('company_id', auth()->user()->company_id)->with(['presentations', 'baseUnit'])->find($this->product_id);
    }

    public function getAvailableLotsProperty()
    {
        if (!$this->product_id || !$this->warehouse_id) return collect();
        $companyId = auth()->user()->company_id;
        return Stock::query()->where('company_id', $companyId)->where('product_id', $this->product_id)
            ->where('warehouse_id', $this->warehouse_id)->where('quantity', '>', 0)->whereNotNull('lot_id')
            ->with(['lot' => fn ($q) => $q->where('company_id', $companyId)])->get()->pluck('lot')->filter()->unique('id')
            ->sortBy(fn ($lot) => $lot->expiration_date?->timestamp ?? PHP_INT_MAX)->values();
    }

    public function save(ProductService $productService, StockService $stockService)
    {
        Gate::authorize('manage-stock');
        $companyId = auth()->user()->company_id;
        $product = $this->selectedProduct;
        if (!$product) { $this->addError('product_id', 'El producto no pertenece a tu empresa o ya no está disponible.'); return; }
        $rules = [
            'product_id' => ['required', Rule::exists('products', 'id')->where(fn ($q) => $q->where('company_id', $companyId)->where('status', 'active'))],
            'warehouse_id' => ['required', Rule::exists('warehouses', 'id')->where(fn ($q) => $q->where('company_id', $companyId))],
            'quantity' => 'required|numeric|min:0.01|max:999999999', 'exit_type' => 'required|in:adjustment,waste',
            'presentation_id' => 'nullable|integer', 'reason' => 'nullable|string|max:500',
        ];
        if ($product->requires_lot) {
            $rules['lot_id'] = ['required', Rule::exists('stock_lots', 'id')->where(fn ($q) => $q->where('company_id', $companyId)->where('product_id', $product->id))];
        }
        $this->validate($rules);
        $presentation = null;
        if ($this->presentation_id) {
            $presentation = $product->presentations()->whereKey($this->presentation_id)->first();
            if (!$presentation) { $this->addError('presentation_id', 'La presentación seleccionada no corresponde a este producto.'); return; }
        }
        if ($product->requires_lot) {
            $lotHasStock = Stock::where('company_id', $companyId)->where('product_id', $product->id)
                ->where('warehouse_id', $this->warehouse_id)->where('lot_id', $this->lot_id)->where('quantity', '>', 0)->exists();
            if (!$lotHasStock) { $this->addError('lot_id', 'Ese lote no tiene stock disponible en el depósito seleccionado.'); return; }
        }
        $conversion = $productService->resolveToBaseUnit($product, $presentation, (float) $this->quantity);
        try {
            $stockService->registerMovement([
                'company_id' => $companyId, 'product_id' => $product->id, 'warehouse_id' => (int) $this->warehouse_id,
                'lot_id' => $this->lot_id ?: null,
                'type' => $this->exit_type === 'waste' ? MovementType::Waste : MovementType::AdjustmentOut,
                'quantity_base' => $conversion['quantity_base'], 'presentation_id' => $conversion['presentation_id'],
                'presentation_quantity' => $conversion['presentation_quantity'], 'user_id' => auth()->id(),
                'channel' => Channel::Web, 'reason' => trim($this->reason) ?: null,
            ]);
            session()->flash('message', 'Salida registrada correctamente.');
            return $this->redirect(route('inventory.index'), navigate: true);
        } catch (Exception $e) { $this->addError('quantity', $e->getMessage()); }
    }

    public function render()
    {
        Gate::authorize('manage-stock');
        $companyId = auth()->user()->company_id;
        $search = trim($this->productSearch);
        $products = Product::where('company_id', $companyId)->where('status', 'active')
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")->orWhere('internal_code', 'like', "{$search}%")
                        ->orWhere('barcode', $search)->orWhereHas('aliases', fn ($aq) => $aq->where('alias', 'like', "%{$search}%"));
                });
            })->orderBy('name')->limit(50)->get(['id', 'name', 'internal_code']);
        if ($this->product_id && !$products->contains('id', (int) $this->product_id)) {
            $selected = Product::where('company_id', $companyId)->find($this->product_id, ['id', 'name', 'internal_code']);
            if ($selected) $products->prepend($selected);
        }
        return view('livewire.inventory.stock-exit', [
            'products' => $products,
            'warehouses' => Warehouse::where('company_id', $companyId)->orderBy('name')->get(['id', 'name']),
        ]);
    }
}
