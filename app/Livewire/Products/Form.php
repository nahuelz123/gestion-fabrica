<?php

namespace App\Livewire\Products;

use App\Enums\ProductStatus;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductPresentation;
use App\Models\Unit;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Form extends Component
{
    // Product fields
    public ?int $productId = null;
    public string $name = '';
    public string $internal_code = '';
    public ?string $barcode = null;
    public string $category_id = '';
    public string $base_unit_id = '';
    public bool $requires_lot = false;
    public bool $requires_expiration = false;
    public ?int $shelf_life_days = null;
    public string $cost = '0';
    public string $price = '0';
    public ?string $min_stock = null;
    public string $status = 'active';

    // Presentations sub-form
    public array $presentations = [];
    public string $pres_name = '';
    public ?string $pres_barcode = null;
    public string $pres_conversion_factor = '';
    public bool $pres_is_purchase_default = false;
    public bool $pres_is_sale_default = false;
    public ?int $editingPresentationIndex = null;

    public function mount(?int $id = null): void
    {
        Gate::authorize('owner-only');

        if ($id) {
            $product = Product::with('presentations')->findOrFail($id);
            $this->productId = $product->id;
            $this->name = $product->name;
            $this->internal_code = $product->internal_code;
            $this->barcode = $product->barcode;
            $this->category_id = (string) $product->category_id;
            $this->base_unit_id = (string) $product->base_unit_id;
            $this->requires_lot = $product->requires_lot;
            $this->requires_expiration = $product->requires_expiration;
            $this->shelf_life_days = $product->shelf_life_days;
            $this->cost = (string) $product->cost;
            $this->price = (string) $product->price;
            $this->min_stock = $product->min_stock !== null ? (string) $product->min_stock : null;
            $this->status = $product->status->value;

            $this->presentations = $product->presentations->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'barcode' => $p->barcode,
                'conversion_factor' => (string) $p->conversion_factor,
                'is_purchase_default' => $p->is_purchase_default,
                'is_sale_default' => $p->is_sale_default,
            ])->toArray();
        }
    }

    public function addPresentation(): void
    {
        $this->validate([
            'pres_name' => 'required|string|max:255',
            'pres_conversion_factor' => 'required|numeric|min:0.0001',
        ], [], [
            'pres_name' => 'nombre de presentación',
            'pres_conversion_factor' => 'factor de conversión',
        ]);

        if ($this->editingPresentationIndex !== null) {
            $this->presentations[$this->editingPresentationIndex] = [
                'id' => $this->presentations[$this->editingPresentationIndex]['id'] ?? null,
                'name' => $this->pres_name,
                'barcode' => $this->pres_barcode ?: null,
                'conversion_factor' => $this->pres_conversion_factor,
                'is_purchase_default' => $this->pres_is_purchase_default,
                'is_sale_default' => $this->pres_is_sale_default,
            ];
            $this->editingPresentationIndex = null;
        } else {
            $this->presentations[] = [
                'id' => null,
                'name' => $this->pres_name,
                'barcode' => $this->pres_barcode ?: null,
                'conversion_factor' => $this->pres_conversion_factor,
                'is_purchase_default' => $this->pres_is_purchase_default,
                'is_sale_default' => $this->pres_is_sale_default,
            ];
        }

        $this->resetPresentationForm();
    }

    public function editPresentation(int $index): void
    {
        $pres = $this->presentations[$index];
        $this->pres_name = $pres['name'];
        $this->pres_barcode = $pres['barcode'];
        $this->pres_conversion_factor = (string) $pres['conversion_factor'];
        $this->pres_is_purchase_default = $pres['is_purchase_default'];
        $this->pres_is_sale_default = $pres['is_sale_default'];
        $this->editingPresentationIndex = $index;
    }

    public function removePresentation(int $index): void
    {
        $pres = $this->presentations[$index];

        // If it has an ID, delete from DB
        if (!empty($pres['id'])) {
            ProductPresentation::destroy($pres['id']);
        }

        unset($this->presentations[$index]);
        $this->presentations = array_values($this->presentations);

        if ($this->editingPresentationIndex === $index) {
            $this->resetPresentationForm();
        }
    }

    public function cancelEditPresentation(): void
    {
        $this->resetPresentationForm();
    }

    private function resetPresentationForm(): void
    {
        $this->pres_name = '';
        $this->pres_barcode = null;
        $this->pres_conversion_factor = '';
        $this->pres_is_purchase_default = false;
        $this->pres_is_sale_default = false;
        $this->editingPresentationIndex = null;
    }

    public function save(): void
    {
        $rules = [
            'name' => 'required|string|max:255',
            'internal_code' => 'required|string|max:50',
            'category_id' => 'required|exists:product_categories,id',
            'base_unit_id' => 'required|exists:units,id',
            'cost' => 'required|numeric|min:0',
            'price' => 'required|numeric|min:0',
            'min_stock' => 'nullable|numeric|min:0',
            'shelf_life_days' => 'nullable|integer|min:1',
        ];

        $this->validate($rules, [], [
            'name' => 'nombre',
            'internal_code' => 'código interno',
            'category_id' => 'categoría',
            'base_unit_id' => 'unidad base',
            'cost' => 'costo',
            'price' => 'precio',
            'min_stock' => 'stock mínimo',
            'shelf_life_days' => 'vida útil',
        ]);

        $data = [
            'company_id' => auth()->user()->company_id,
            'name' => $this->name,
            'internal_code' => $this->internal_code,
            'barcode' => $this->barcode ?: null,
            'category_id' => $this->category_id,
            'base_unit_id' => $this->base_unit_id,
            'requires_lot' => $this->requires_lot,
            'requires_expiration' => $this->requires_expiration,
            'shelf_life_days' => $this->requires_expiration ? $this->shelf_life_days : null,
            'cost' => $this->cost,
            'price' => $this->price,
            'min_stock' => $this->min_stock,
            'status' => $this->status,
        ];

        if ($this->productId) {
            $product = Product::findOrFail($this->productId);
            $product->update($data);
        } else {
            $product = Product::create($data);
        }

        // Sync presentations
        $existingIds = [];
        foreach ($this->presentations as $pres) {
            if (!empty($pres['id'])) {
                // Update existing
                ProductPresentation::where('id', $pres['id'])->update([
                    'name' => $pres['name'],
                    'barcode' => $pres['barcode'] ?: null,
                    'conversion_factor' => $pres['conversion_factor'],
                    'is_purchase_default' => $pres['is_purchase_default'],
                    'is_sale_default' => $pres['is_sale_default'],
                ]);
                $existingIds[] = $pres['id'];
            } else {
                // Create new
                $newPres = ProductPresentation::create([
                    'product_id' => $product->id,
                    'name' => $pres['name'],
                    'barcode' => $pres['barcode'] ?: null,
                    'conversion_factor' => $pres['conversion_factor'],
                    'is_purchase_default' => $pres['is_purchase_default'],
                    'is_sale_default' => $pres['is_sale_default'],
                ]);
                $existingIds[] = $newPres->id;
            }
        }

        $action = $this->productId ? 'actualizado' : 'creado';
        session()->flash('message', "Producto '{$product->name}' {$action} correctamente.");

        $this->redirect(route('products.index'), navigate: true);
    }

    public function render()
    {
        return view('livewire.products.form', [
            'categories' => ProductCategory::orderBy('name')->get(),
            'units' => Unit::all(),
        ]);
    }
}
