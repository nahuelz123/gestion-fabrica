<?php

namespace App\Livewire\Purchases;

use App\Enums\PurchaseStatus;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Form extends Component
{
    public string $supplier_id = '';
    public string $warehouse_id = '';
    public string $purchase_date = '';
    public string $invoice_number = '';
    public string $notes = '';

    public array $items = [];

    public function mount()
    {
        Gate::authorize('owner-only');

        $this->purchase_date = now()->toDateString();
        $this->addItem();
    }

    public function addItem()
    {
        $this->items[] = [
            'product_id' => '',
            'presentation_id' => '',
            'quantity' => '',
            'unit_cost' => '',
            'lot_code' => '',
            'expiration_date' => '',
        ];
    }

    public function removeItem($index)
    {
        unset($this->items[$index]);
        $this->items = array_values($this->items);
    }

    public function save()
    {
        $this->validate([
            'supplier_id' => 'required|exists:suppliers,id',
            'warehouse_id' => 'required|exists:warehouses,id',
            'purchase_date' => 'required|date',
            'invoice_number' => 'nullable|string|max:255',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|numeric|min:0.01',
            'items.*.unit_cost' => 'required|numeric|min:0',
        ], [
            'items.*.product_id.required' => 'Falta seleccionar el producto.',
            'items.*.quantity.required' => 'Cantidad requerida.',
            'items.*.unit_cost.required' => 'Costo requerido.',
        ]);

        // Custom validation for lots based on product
        $products = Product::whereIn('id', collect($this->items)->pluck('product_id')->filter())->get()->keyBy('id');
        
        foreach ($this->items as $index => $item) {
            $product = $products->get($item['product_id']);
            if ($product && $product->requires_lot) {
                if (empty($item['lot_code'])) {
                    $this->addError("items.{$index}.lot_code", 'El lote es obligatorio.');
                }
                if ($product->requires_expiration && empty($item['expiration_date'])) {
                    $this->addError("items.{$index}.expiration_date", 'El vencimiento es obligatorio.');
                }
            }
        }

        if ($this->getErrorBag()->isNotEmpty()) {
            return;
        }

        DB::transaction(function () {
            $purchase = Purchase::create([
                'company_id' => auth()->user()->company_id,
                'supplier_id' => $this->supplier_id,
                'warehouse_id' => $this->warehouse_id,
                'purchase_date' => $this->purchase_date,
                'invoice_number' => $this->invoice_number,
                'status' => PurchaseStatus::Draft,
                'notes' => $this->notes,
                'user_id' => auth()->id(),
            ]);

            foreach ($this->items as $item) {
                $purchase->items()->create([
                    'product_id' => $item['product_id'],
                    'presentation_id' => $item['presentation_id'] ?: null,
                    'quantity' => $item['quantity'],
                    'unit_cost' => $item['unit_cost'],
                    'lot_code' => $item['lot_code'] ?: null,
                    'expiration_date' => $item['expiration_date'] ?: null,
                ]);
            }
        });

        session()->flash('message', 'Compra creada en borrador.');
        return $this->redirect(route('purchases.index'), navigate: true);
    }

    public function render()
    {
        return view('livewire.purchases.form', [
            'suppliers' => Supplier::where('status', 'active')->orderBy('name')->get(),
            'warehouses' => Warehouse::orderBy('name')->get(),
            'products' => Product::with('presentations', 'baseUnit')->where('status', 'active')->orderBy('name')->get(),
        ]);
    }
}
