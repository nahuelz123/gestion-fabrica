<?php

namespace App\Livewire\Inventory;

use App\Enums\Channel;
use App\Enums\MovementType;
use App\Models\Product;
use App\Models\Stock;
use App\Models\Warehouse;
use App\Services\StockService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
class StockManager extends Component
{
    use WithPagination;
    public string $search = '';
    public int $perPage = 25;
    public ?int $selectedProductId = null;
    public string $warehouse_id = '';
    public string $adjustMode = 'add';
    public string $adjustQty = '';
    public string $adjustPresentationId = '';
    public ?string $successMessage = null;
    public ?string $errorMessage = null;

    public function mount(): void
    {
        Gate::authorize('manage-stock');
        $warehouse = Warehouse::where('company_id', auth()->user()->company_id)->orderBy('id')->first();
        $this->warehouse_id = $warehouse ? (string) $warehouse->id : '';
    }
    public function updatedSearch(): void { $this->resetPage(); }
    public function updatedPerPage(): void { $this->perPage = max(10, min($this->perPage, 100)); $this->resetPage(); }
    public function updatedWarehouseId(): void { $this->successMessage = null; $this->errorMessage = null; }

    public function selectProduct(int $productId): void
    {
        Gate::authorize('manage-stock');
        Product::where('company_id', auth()->user()->company_id)->findOrFail($productId);
        $this->selectedProductId = $productId;
        $this->adjustQty = ''; $this->adjustPresentationId = ''; $this->successMessage = null; $this->errorMessage = null;
    }

    public function applyAdjustment(StockService $stockService): void
    {
        Gate::authorize('manage-stock');
        $companyId = auth()->user()->company_id;
        $this->validate([
            'selectedProductId' => ['required', Rule::exists('products', 'id')->where(fn ($q) => $q->where('company_id', $companyId)->where('status', 'active'))],
            'warehouse_id' => ['required', Rule::exists('warehouses', 'id')->where(fn ($q) => $q->where('company_id', $companyId))],
            'adjustMode' => 'required|in:add,subtract,set', 'adjustQty' => 'required|numeric|min:0.01|max:999999999',
            'adjustPresentationId' => 'nullable|integer',
        ]);
        $user = auth()->user();
        $product = Product::where('company_id', $companyId)->where('status', 'active')->with('presentations')->findOrFail($this->selectedProductId);
        $warehouse = Warehouse::where('company_id', $companyId)->findOrFail($this->warehouse_id);
        $qty = (float) $this->adjustQty;
        $presentationId = null; $presentationQty = null;
        if ($this->adjustPresentationId) {
            $pres = $product->presentations()->whereKey($this->adjustPresentationId)->first();
            if (!$pres) { $this->addError('adjustPresentationId', 'La presentación no corresponde al producto seleccionado.'); return; }
            $presentationId = $pres->id; $presentationQty = $qty; $qty *= (float) $pres->conversion_factor;
        }
        $currentQty = (float) Stock::where('company_id', $companyId)->where('product_id', $product->id)->where('warehouse_id', $warehouse->id)->sum('quantity');
        try {
            if ($this->adjustMode === 'set') {
                $diff = $qty - $currentQty;
                if (abs($diff) < 0.001) { $this->successMessage = "El stock de {$product->name} en {$warehouse->name} ya estaba en {$qty} u."; return; }
                $stockService->registerMovement([
                    'company_id' => $companyId, 'product_id' => $product->id, 'warehouse_id' => $warehouse->id,
                    'type' => $diff > 0 ? MovementType::AdjustmentIn : MovementType::AdjustmentOut,
                    'quantity_base' => abs($diff), 'user_id' => $user->id, 'channel' => Channel::Web,
                    'reason' => 'Ajuste manual web: establecer stock',
                ]);
                $this->successMessage = "Stock de {$product->name} en {$warehouse->name} actualizado a {$qty} u.";
            } else {
                $type = $this->adjustMode === 'subtract' ? MovementType::AdjustmentOut : MovementType::AdjustmentIn;
                $stockService->registerMovement([
                    'company_id' => $companyId, 'product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'type' => $type,
                    'quantity_base' => $qty, 'presentation_id' => $presentationId, 'presentation_quantity' => $presentationQty,
                    'user_id' => $user->id, 'channel' => Channel::Web,
                    'reason' => $this->adjustMode === 'subtract' ? 'Ajuste manual web: egreso' : 'Ajuste manual web: ingreso',
                ]);
                $newTotal = (float) Stock::where('company_id', $companyId)->where('product_id', $product->id)->where('warehouse_id', $warehouse->id)->sum('quantity');
                $verb = $this->adjustMode === 'subtract' ? 'Desconté' : 'Sumé';
                $this->successMessage = "{$verb} {$qty} u de {$product->name}. Stock en {$warehouse->name}: {$newTotal} u.";
            }
            $this->adjustQty = ''; $this->errorMessage = null;
        } catch (\Exception $e) { $this->errorMessage = $e->getMessage(); }
    }

    public function render()
    {
        Gate::authorize('manage-stock');
        $companyId = auth()->user()->company_id;
        $search = trim($this->search);
        $products = Product::query()->where('company_id', $companyId)->where('status', 'active')
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")->orWhere('internal_code', 'like', "{$search}%")
                        ->orWhere('barcode', $search)->orWhereHas('aliases', fn ($aq) => $aq->where('alias', 'like', "%{$search}%"));
                });
            })
            ->with(['baseUnit:id,abbreviation'])
            ->withSum(['stocks as current_stock' => fn ($q) => $q->where('company_id', $companyId)], 'quantity')
            ->orderBy('name')->paginate(max(10, min($this->perPage, 100)));
        $selectedProduct = $this->selectedProductId
            ? Product::where('company_id', $companyId)->with(['presentations', 'baseUnit'])->find($this->selectedProductId) : null;
        $selectedStock = ($selectedProduct && $this->warehouse_id)
            ? (float) Stock::where('company_id', $companyId)->where('product_id', $selectedProduct->id)->where('warehouse_id', $this->warehouse_id)->sum('quantity') : null;
        return view('livewire.inventory.stock-manager', [
            'products' => $products, 'selectedProduct' => $selectedProduct, 'selectedStock' => $selectedStock,
            'warehouses' => Warehouse::where('company_id', $companyId)->orderBy('name')->get(['id', 'name']),
        ]);
    }
}
