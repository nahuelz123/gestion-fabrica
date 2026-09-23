<?php

namespace App\Livewire\Products;

use App\Enums\Channel;
use App\Enums\MovementType;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductPresentation;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Services\StockService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Form extends Component
{
    public ?int $productId = null;
    
    public string $type = 'raw_material';
    public string $name = '';
    public string $presentation = '';
    public string $initial_stock = '';

    public ?string $barcode = null;
    public bool $requires_lot = false;
    public bool $requires_expiration = false;
    public ?int $shelf_life_days = null;

    public function mount(?int $id = null): void
    {
        Gate::authorize('owner-only');

        if ($id) {
            $product = Product::findOrFail($id);
            $this->productId = $product->id;
            $this->type = $product->type->value;
            $this->name = $product->name;
            $this->presentation = $product->presentation;
            
            $this->barcode = $product->barcode;
            $this->requires_lot = $product->requires_lot;
            $this->requires_expiration = $product->requires_expiration;
            $this->shelf_life_days = $product->shelf_life_days;
        }
    }

    public function save(StockService $stockService): void
    {
        $rules = [
            'type' => 'required|in:raw_material,finished_product',
            'name' => 'required|string|max:255',
            'presentation' => 'required|string|max:255',
            'initial_stock' => 'nullable|numeric|min:0',
        ];

        if ($this->type === 'finished_product') {
            $rules['barcode'] = 'nullable|string|max:255';
            $rules['shelf_life_days'] = 'nullable|integer|min:1';
        }

        $this->validate($rules, [], [
            'name' => 'nombre',
            'presentation' => 'presentación',
            'initial_stock' => 'stock inicial',
            'shelf_life_days' => 'vida útil',
        ]);

        DB::transaction(function () use ($stockService) {
            $companyId = auth()->user()->company_id;

            if ($this->productId) {
                // Update existing product
                $unit = Unit::firstOrCreate(
                    ['abbreviation' => 'u'],
                    ['name' => 'Unidad', 'type' => 'count']
                );
                $categoryName = $this->type === 'raw_material' ? 'Insumos' : 'Producto Terminado';
                $category = ProductCategory::firstOrCreate(
                    ['company_id' => $companyId, 'name' => $categoryName]
                );

                $product = Product::findOrFail($this->productId);
                $data = [
                    'company_id' => $companyId,
                    'category_id' => $category->id,
                    'type' => $this->type,
                    'name' => $this->name,
                    'presentation' => $this->presentation,
                ];
                if ($this->type === 'finished_product') {
                    $data['barcode'] = $this->barcode ?: null;
                    $data['requires_lot'] = $this->requires_lot;
                    $data['requires_expiration'] = $this->requires_expiration;
                    $data['shelf_life_days'] = $this->requires_expiration ? $this->shelf_life_days : null;
                } else {
                    $data['barcode'] = null;
                    $data['requires_lot'] = false;
                    $data['requires_expiration'] = false;
                    $data['shelf_life_days'] = null;
                }
                $product->update($data);

                $pres = ProductPresentation::where('product_id', $product->id)
                    ->where('is_purchase_default', true)
                    ->first() ?: ProductPresentation::where('product_id', $product->id)->first();
                if ($pres) {
                    $pres->update([
                        'name' => $this->presentation,
                        'barcode' => $this->type === 'finished_product' ? $this->barcode : null,
                    ]);
                }
            } else {
                $productService = app(\App\Services\ProductService::class);
                $product = $productService->createProduct($companyId, [
                    'type' => $this->type,
                    'name' => $this->name,
                    'presentation' => $this->presentation,
                    'barcode' => $this->barcode,
                    'requires_lot' => $this->requires_lot,
                    'requires_expiration' => $this->requires_expiration,
                    'shelf_life_days' => $this->shelf_life_days,
                ]);
            }

            if (!$this->productId && !empty($this->initial_stock) && $this->initial_stock > 0) {
                $warehouse = Warehouse::where('company_id', $companyId)->first();
                if ($warehouse) {
                    $stockService->registerMovement([
                        'company_id' => $companyId,
                        'product_id' => $product->id,
                        'warehouse_id' => $warehouse->id,
                        'type' => MovementType::AdjustmentIn,
                        'quantity_base' => (float) $this->initial_stock,
                        'user_id' => auth()->id(),
                        'channel' => Channel::Web,
                        'reason' => 'Stock inicial',
                    ]);
                }
            }

            $action = $this->productId ? 'actualizado' : 'creado';
            session()->flash('message', "Producto '{$product->name}' {$action} correctamente.");
        });

        $this->redirect(route('products.index'), navigate: true);
    }

    public function render()
    {
        return view('livewire.products.form');
    }
}
