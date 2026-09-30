<?php

namespace App\Livewire\Purchases;

use App\Enums\PurchaseStatus;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
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
    public string $productSearch = '';
    public array $items = [];

    public function mount(): void
    {
        Gate::authorize('owner-only');
        $this->purchase_date = now()->toDateString();
        $warehouse = Warehouse::where('company_id', auth()->user()->company_id)->orderBy('id')->first();
        $this->warehouse_id = $warehouse ? (string) $warehouse->id : '';
        $this->addItem();
    }

    public function addItem(): void
    {
        Gate::authorize('owner-only');
        $this->items[] = ['product_id'=>'','presentation_id'=>'','quantity'=>'','unit_cost'=>'','lot_code'=>'','expiration_date'=>''];
    }

    public function removeItem(int $index): void
    {
        Gate::authorize('owner-only');
        unset($this->items[$index]);
        $this->items = array_values($this->items);
    }

    public function save()
    {
        Gate::authorize('owner-only');
        $companyId = auth()->user()->company_id;
        $this->validate([
            'supplier_id' => ['required', Rule::exists('suppliers', 'id')->where(fn ($q) => $q->where('company_id', $companyId)->where('status', 'active'))],
            'warehouse_id' => ['required', Rule::exists('warehouses', 'id')->where(fn ($q) => $q->where('company_id', $companyId))],
            'purchase_date' => 'required|date',
            'invoice_number' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:2000',
            'items' => 'required|array|min:1|max:100',
            'items.*.product_id' => ['required', Rule::exists('products', 'id')->where(fn ($q) => $q->where('company_id', $companyId)->where('status', 'active'))],
            'items.*.presentation_id' => 'nullable|integer',
            'items.*.quantity' => 'required|numeric|min:0.01|max:999999999',
            'items.*.unit_cost' => 'required|numeric|min:0|max:999999999',
            'items.*.lot_code' => 'nullable|string|max:100',
            'items.*.expiration_date' => 'nullable|date',
        ], [
            'items.*.product_id.required' => 'Falta seleccionar el producto.',
            'items.*.quantity.required' => 'Cantidad requerida.',
            'items.*.unit_cost.required' => 'Costo requerido.',
        ]);

        $products = Product::where('company_id', $companyId)
            ->whereIn('id', collect($this->items)->pluck('product_id')->filter())
            ->with('presentations')->get()->keyBy('id');

        foreach ($this->items as $index => $item) {
            $product = $products->get((int) $item['product_id']);
            if (!$product) {
                $this->addError("items.{$index}.product_id", 'El producto no pertenece a la empresa activa.');
                continue;
            }
            if (!empty($item['presentation_id']) && !$product->presentations->contains('id', (int) $item['presentation_id'])) {
                $this->addError("items.{$index}.presentation_id", 'La presentación no corresponde al producto.');
            }
            if ($product->requires_lot && empty($item['lot_code'])) {
                $this->addError("items.{$index}.lot_code", 'El lote es obligatorio.');
            }
            if ($product->requires_expiration && empty($item['expiration_date'])) {
                $this->addError("items.{$index}.expiration_date", 'El vencimiento es obligatorio.');
            }
        }
        if ($this->getErrorBag()->isNotEmpty()) return;

        DB::transaction(function () use ($companyId) {
            $purchase = Purchase::create([
                'company_id'=>$companyId,'supplier_id'=>$this->supplier_id,'warehouse_id'=>$this->warehouse_id,
                'purchase_date'=>$this->purchase_date,'invoice_number'=>trim($this->invoice_number) ?: null,
                'status'=>PurchaseStatus::Draft,'notes'=>trim($this->notes) ?: null,'user_id'=>auth()->id(),
            ]);
            foreach ($this->items as $item) {
                $purchase->items()->create([
                    'product_id'=>$item['product_id'],'presentation_id'=>$item['presentation_id'] ?: null,
                    'quantity'=>$item['quantity'],'unit_cost'=>$item['unit_cost'],'lot_code'=>trim((string)$item['lot_code']) ?: null,
                    'expiration_date'=>$item['expiration_date'] ?: null,
                ]);
            }
        });

        session()->flash('message', 'Compra creada en borrador.');
        return $this->redirect(route('purchases.index'), navigate: true);
    }

    public function render()
    {
        Gate::authorize('owner-only');
        $companyId = auth()->user()->company_id;
        $search = trim($this->productSearch);
        $selectedIds = collect($this->items)->pluck('product_id')->filter()->map(fn ($id) => (int)$id)->unique()->values();
        $products = Product::where('company_id', $companyId)->where('status', 'active')
            ->with(['presentations','baseUnit'])
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name','like',"%{$search}%")->orWhere('internal_code','like',"{$search}%")
                        ->orWhere('barcode',$search)->orWhereHas('aliases', fn ($aq) => $aq->where('alias','like',"%{$search}%"));
                });
            })->orderBy('name')->limit(75)->get();
        if ($selectedIds->isNotEmpty()) {
            $selected = Product::where('company_id',$companyId)->whereIn('id',$selectedIds)
                ->with(['presentations','baseUnit'])->get();
            $products = $products->concat($selected)->unique('id')->sortBy('name')->values();
        }
        return view('livewire.purchases.form', [
            'suppliers'=>Supplier::where('company_id',$companyId)->where('status','active')->orderBy('name')->get(['id','name']),
            'warehouses'=>Warehouse::where('company_id',$companyId)->orderBy('name')->get(['id','name']),
            'products'=>$products,
        ]);
    }
}
