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
    public string $adjustMode = 'set';
    public string $adjustQty = '';
    public string $adjustPresentationId = '';
    public string $lot_id = '';
    public string $lot_code = '';
    public ?string $expiration_date = null;
    public bool $attentionOnly = false;
    public ?string $successMessage = null;
    public ?string $errorMessage = null;

    public function mount(): void
    {
        Gate::authorize('manage-stock');
        $warehouse = Warehouse::where('company_id', auth()->user()->company_id)->orderBy('id')->first();
        $this->warehouse_id = $warehouse ? (string) $warehouse->id : '';
        $this->attentionOnly = request()->boolean('attention');
    }
    public function updatedSearch(): void { $this->resetPage(); }
    public function updatedPerPage(): void { $this->perPage = max(10, min($this->perPage, 100)); $this->resetPage(); }
    public function updatedWarehouseId(): void
    {
        $this->successMessage = null;
        $this->errorMessage = null;
        $this->lot_id = '';
    }

    public function selectProduct(int $productId): void
    {
        Gate::authorize('manage-stock');
        Product::where('company_id', auth()->user()->company_id)->findOrFail($productId);
        $this->selectedProductId = $productId;
        $product = Product::where('company_id', auth()->user()->company_id)->findOrFail($productId);
        $this->adjustMode = $product->requires_lot ? 'add' : 'set';
        $this->adjustQty = '';
        $this->adjustPresentationId = '';
        $this->lot_id = '';
        $this->lot_code = '';
        $this->expiration_date = null;
        $this->successMessage = null;
        $this->errorMessage = null;
    }

    public function applyAdjustment(StockService $stockService): void
    {
        Gate::authorize('manage-stock');
        $companyId = auth()->user()->company_id;
        $this->validate([
            'selectedProductId' => ['required', Rule::exists('products', 'id')->where(fn ($q) => $q->where('company_id', $companyId)->where('status', 'active'))],
            'warehouse_id' => ['required', Rule::exists('warehouses', 'id')->where(fn ($q) => $q->where('company_id', $companyId))],
            'adjustMode' => 'required|in:add,subtract,set',
            'adjustQty' => 'required|numeric|min:0|max:999999999',
            'adjustPresentationId' => 'nullable|integer',
        ]);

        if ($this->adjustMode !== 'set' && (float) $this->adjustQty <= 0) {
            $this->addError('adjustQty', 'La cantidad debe ser mayor a cero.');
            return;
        }

        $user = auth()->user();
        $product = Product::where('company_id', $companyId)
            ->where('status', 'active')
            ->with('presentations')
            ->findOrFail($this->selectedProductId);

        if ($product->requires_lot && $this->adjustMode === 'set') {
            $this->adjustMode = 'add';
            $this->errorMessage = 'Este producto se controla por lote. Para mantener la trazabilidad, corregí sumando o restando el lote correspondiente.';
            return;
        }

        if ($product->requires_lot) {
            if ($this->adjustMode === 'subtract') {
                $this->validate([
                    'lot_id' => ['required', Rule::exists('stock_lots', 'id')->where(fn ($q) => $q
                        ->where('company_id', $companyId)
                        ->where('product_id', $product->id))],
                ], ['lot_id.required' => 'Elegí el lote del que querés descontar.']);

                $lotHasStock = Stock::where('company_id', $companyId)
                    ->where('product_id', $product->id)
                    ->where('warehouse_id', $this->warehouse_id)
                    ->where('lot_id', $this->lot_id)
                    ->where('quantity', '>', 0)
                    ->exists();

                if (!$lotHasStock) {
                    $this->addError('lot_id', 'Ese lote no tiene stock disponible en este depósito.');
                    return;
                }
            } else {
                $rules = ['lot_code' => 'required|string|max:50'];
                if ($product->requires_expiration) {
                    $rules['expiration_date'] = $product->shelf_life_days ? 'nullable|date' : 'required|date';
                }
                $this->validate($rules, ['lot_code.required' => 'Ingresá el código de lote.']);
            }
        }
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
                    'reason' => 'Recuento físico de stock',
                ]);
                $this->successMessage = "Listo. {$product->name} quedó en {$qty} u en {$warehouse->name}.";
            } else {
                $type = $this->adjustMode === 'subtract' ? MovementType::AdjustmentOut : MovementType::AdjustmentIn;
                $lotData = [];
                $lotId = null;
                if ($product->requires_lot) {
                    if ($this->adjustMode === 'subtract') {
                        $lotId = (int) $this->lot_id;
                    } else {
                        $expirationDate = $this->expiration_date;
                        if (!$expirationDate && $product->requires_expiration && $product->shelf_life_days) {
                            $expirationDate = now()->addDays((int) $product->shelf_life_days)->toDateString();
                        }
                        $lotData = [
                            'lot_code' => trim($this->lot_code),
                            'entry_date' => now()->toDateString(),
                            'expiration_date' => $expirationDate,
                        ];
                    }
                }

                $stockService->registerMovement([
                    'company_id' => $companyId, 'product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'type' => $type,
                    'quantity_base' => $qty, 'presentation_id' => $presentationId, 'presentation_quantity' => $presentationQty,
                    'lot_id' => $lotId, 'lot_data' => $lotData,
                    'user_id' => $user->id, 'channel' => Channel::Web,
                    'reason' => $this->adjustMode === 'subtract' ? 'Corrección manual: egreso' : 'Corrección manual: ingreso',
                ]);
                $newTotal = (float) Stock::where('company_id', $companyId)->where('product_id', $product->id)->where('warehouse_id', $warehouse->id)->sum('quantity');
                $verb = $this->adjustMode === 'subtract' ? 'Desconté' : 'Sumé';
                $this->successMessage = "{$verb} {$qty} u de {$product->name}. Stock en {$warehouse->name}: {$newTotal} u.";
            }
            $this->adjustQty = '';
            $this->lot_id = '';
            $this->lot_code = '';
            $this->expiration_date = null;
            $this->errorMessage = null;
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
            ->when($this->attentionOnly, function ($query) {
                $query->havingRaw('COALESCE(current_stock, 0) <= 0 OR (min_stock IS NOT NULL AND min_stock > 0 AND COALESCE(current_stock, 0) <= min_stock)');
            })
            ->orderByRaw($this->attentionOnly ? 'COALESCE(current_stock, 0) ASC' : 'name ASC')
            ->when($this->attentionOnly, fn ($query) => $query->orderBy('name'))
            ->paginate(max(10, min($this->perPage, 100)));
        $selectedProduct = $this->selectedProductId
            ? Product::where('company_id', $companyId)->with(['presentations', 'baseUnit'])->find($this->selectedProductId) : null;
        $selectedStock = ($selectedProduct && $this->warehouse_id)
            ? (float) Stock::where('company_id', $companyId)->where('product_id', $selectedProduct->id)->where('warehouse_id', $this->warehouse_id)->sum('quantity') : null;

        $availableLots = collect();
        if ($selectedProduct?->requires_lot && $this->warehouse_id) {
            $availableLots = Stock::query()
                ->where('company_id', $companyId)
                ->where('product_id', $selectedProduct->id)
                ->where('warehouse_id', $this->warehouse_id)
                ->where('quantity', '>', 0)
                ->whereNotNull('lot_id')
                ->with('lot')
                ->get()
                ->filter(fn ($stock) => $stock->lot)
                ->sortBy(fn ($stock) => $stock->lot->expiration_date?->timestamp ?? PHP_INT_MAX)
                ->values();
        }

        return view('livewire.inventory.stock-manager', [
            'products' => $products, 'selectedProduct' => $selectedProduct, 'selectedStock' => $selectedStock,
            'availableLots' => $availableLots,
            'warehouses' => Warehouse::where('company_id', $companyId)->orderBy('name')->get(['id', 'name']),
        ]);
    }
}
