<?php

namespace App\Livewire\Products;

use App\Enums\Channel;
use App\Enums\MovementType;
use App\Models\Product;
use App\Models\ProductAlias;
use App\Models\ProductCategory;
use App\Models\ProductPresentation;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Services\StockService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
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
    public string $min_stock = '';
    public string $aliases = '';

    public ?string $barcode = null;
    public bool $requires_lot = false;
    public bool $requires_expiration = false;
    public ?int $shelf_life_days = null;

    public function mount(?int $id = null): void
    {
        Gate::authorize('owner-only');

        if ($id) {
            $product = Product::where('company_id', auth()->user()->company_id)
                ->with('aliases')
                ->findOrFail($id);

            $this->productId = $product->id;
            $this->type = $product->type->value;
            $this->name = $product->name;
            $this->presentation = $product->presentation;
            $this->min_stock = $product->min_stock !== null ? (string) $product->min_stock : '';
            $this->aliases = $product->aliases->pluck('alias')->implode(', ');
            $this->barcode = $product->barcode;
            $this->requires_lot = $product->requires_lot;
            $this->requires_expiration = $product->requires_expiration;
            $this->shelf_life_days = $product->shelf_life_days;
        }
    }

    public function save(StockService $stockService): void
    {
        Gate::authorize('owner-only');
        $companyId = auth()->user()->company_id;

        $rules = [
            'type' => 'required|in:raw_material,finished_product',
            'name' => 'required|string|max:255',
            'presentation' => 'required|string|max:255',
            'initial_stock' => 'nullable|numeric|min:0',
            'min_stock' => 'nullable|numeric|min:0',
            'aliases' => 'nullable|string|max:1000',
        ];

        if ($this->type === 'finished_product') {
            $rules['barcode'] = [
                'nullable', 'string', 'max:255',
                Rule::unique('products', 'barcode')->ignore($this->productId),
            ];
            $rules['shelf_life_days'] = 'nullable|integer|min:1';
        }

        $this->validate($rules, [], [
            'name' => 'nombre',
            'presentation' => 'presentación',
            'initial_stock' => 'stock inicial',
            'min_stock' => 'stock mínimo',
            'aliases' => 'aliases',
            'shelf_life_days' => 'vida útil',
        ]);

        DB::transaction(function () use ($stockService, $companyId) {
            if ($this->productId) {
                $unit = Unit::firstOrCreate(
                    ['abbreviation' => 'u'],
                    ['name' => 'Unidad', 'type' => 'count']
                );
                $categoryName = $this->type === 'raw_material' ? 'Insumos' : 'Producto Terminado';
                $category = ProductCategory::firstOrCreate(
                    ['company_id' => $companyId, 'name' => $categoryName]
                );

                $product = Product::where('company_id', $companyId)->findOrFail($this->productId);
                $data = [
                    'category_id' => $category->id,
                    'base_unit_id' => $unit->id,
                    'type' => $this->type,
                    'name' => trim($this->name),
                    'presentation' => trim($this->presentation),
                    'min_stock' => $this->min_stock === '' ? null : (float) $this->min_stock,
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
                        'name' => trim($this->presentation),
                        'barcode' => $this->type === 'finished_product' ? $this->barcode : null,
                    ]);
                }
            } else {
                $productService = app(\App\Services\ProductService::class);
                $product = $productService->createProduct($companyId, [
                    'type' => $this->type,
                    'name' => trim($this->name),
                    'presentation' => trim($this->presentation),
                    'barcode' => $this->barcode,
                    'requires_lot' => $this->requires_lot,
                    'requires_expiration' => $this->requires_expiration,
                    'shelf_life_days' => $this->shelf_life_days,
                ]);
                $product->update([
                    'min_stock' => $this->min_stock === '' ? null : (float) $this->min_stock,
                ]);
            }

            $aliases = collect(explode(',', $this->aliases))
                ->map(fn ($alias) => trim($alias))
                ->filter()
                ->unique(fn ($alias) => mb_strtolower($alias))
                ->take(20)
                ->values();

            ProductAlias::where('company_id', $companyId)
                ->where('product_id', $product->id)
                ->delete();

            foreach ($aliases as $alias) {
                ProductAlias::updateOrCreate(
                    ['company_id' => $companyId, 'alias' => $alias],
                    ['product_id' => $product->id]
                );
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
