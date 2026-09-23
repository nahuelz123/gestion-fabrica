<?php

namespace App\Livewire\Inventory;

use App\Enums\Channel;
use App\Enums\MovementType;
use App\Models\Product;
use App\Models\Stock;
use App\Models\Warehouse;
use App\Services\StockService;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class StockManager extends Component
{
    public string $search = '';
    public ?int $selectedProductId = null;
    public string $adjustMode = 'add';    // 'add' | 'subtract' | 'set'
    public string $adjustQty = '';
    public string $adjustPresentationId = '';

    public ?string $successMessage = null;
    public ?string $errorMessage = null;

    public function selectProduct(int $productId): void
    {
        $this->selectedProductId = $productId;
        $this->adjustQty = '';
        $this->adjustPresentationId = '';
        $this->successMessage = null;
        $this->errorMessage = null;
    }

    public function applyAdjustment(StockService $stockService): void
    {
        $this->validate([
            'adjustQty' => 'required|numeric|min:0.01',
        ]);

        $user = auth()->user();
        $companyId = $user->company_id;

        $product = Product::where('company_id', $companyId)->findOrFail($this->selectedProductId);
        $warehouse = Warehouse::where('company_id', $companyId)->firstOrFail();

        // Resolve base quantity
        $qty = (float) $this->adjustQty;
        if ($this->adjustPresentationId) {
            $pres = $product->presentations()->find($this->adjustPresentationId);
            if ($pres) {
                $qty = $qty * (float) $pres->conversion_factor;
            }
        }

        $currentQty = (float) Stock::where('product_id', $product->id)->where('company_id', $companyId)->sum('quantity');

        try {
            if ($this->adjustMode === 'set') {
                $diff = $qty - $currentQty;
                if (abs($diff) < 0.001) {
                    $this->successMessage = "El stock ya estaba en {$qty} u.";
                    return;
                }
                $type = $diff > 0 ? MovementType::AdjustmentIn : MovementType::AdjustmentOut;
                $stockService->registerMovement([
                    'company_id' => $companyId,
                    'product_id' => $product->id,
                    'warehouse_id' => $warehouse->id,
                    'type' => $type,
                    'quantity_base' => abs($diff),
                    'user_id' => $user->id,
                    'channel' => Channel::Web,
                    'reason' => 'Ajuste manual web: establecer stock',
                ]);
                $this->successMessage = "Stock de {$product->name} actualizado a {$qty} u.";

            } elseif ($this->adjustMode === 'subtract') {
                $stockService->registerMovement([
                    'company_id' => $companyId,
                    'product_id' => $product->id,
                    'warehouse_id' => $warehouse->id,
                    'type' => MovementType::AdjustmentOut,
                    'quantity_base' => $qty,
                    'user_id' => $user->id,
                    'channel' => Channel::Web,
                    'reason' => 'Ajuste manual web: egreso',
                ]);
                $newTotal = (float) Stock::where('product_id', $product->id)->where('company_id', $companyId)->sum('quantity');
                $this->successMessage = "Desconté {$qty} u de {$product->name}. Stock actual: {$newTotal} u.";

            } else { // add
                $stockService->registerMovement([
                    'company_id' => $companyId,
                    'product_id' => $product->id,
                    'warehouse_id' => $warehouse->id,
                    'type' => MovementType::AdjustmentIn,
                    'quantity_base' => $qty,
                    'user_id' => $user->id,
                    'channel' => Channel::Web,
                    'reason' => 'Ajuste manual web: ingreso',
                ]);
                $newTotal = (float) Stock::where('product_id', $product->id)->where('company_id', $companyId)->sum('quantity');
                $this->successMessage = "Sumé {$qty} u a {$product->name}. Stock actual: {$newTotal} u.";
            }

            $this->adjustQty = '';
            $this->errorMessage = null;

        } catch (\Exception $e) {
            $this->errorMessage = $e->getMessage();
        }
    }

    public function render()
    {
        $companyId = auth()->user()->company_id;

        $products = Product::query()
            ->where('company_id', $companyId)
            ->where('status', 'active')
            ->when($this->search, fn($q) => $q->where('name', 'like', "%{$this->search}%"))
            ->orderBy('name')
            ->with(['baseUnit', 'presentations'])
            ->get()
            ->map(function ($product) use ($companyId) {
                $product->current_stock = Stock::where('product_id', $product->id)->where('company_id', $companyId)->sum('quantity');
                return $product;
            });

        $selectedProduct = $this->selectedProductId
            ? Product::where('company_id', $companyId)->with(['presentations', 'baseUnit'])->find($this->selectedProductId)
            : null;

        $selectedStock = $selectedProduct
            ? (float) Stock::where('product_id', $selectedProduct->id)->where('company_id', $companyId)->sum('quantity')
            : null;

        return view('livewire.inventory.stock-manager', compact('products', 'selectedProduct', 'selectedStock'));
    }
}
