<?php

namespace App\Livewire\Recipes;

use App\Models\Product;
use App\Models\Recipe;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Manager extends Component
{
    public Product $product;
    public ?Recipe $recipe = null;

    public string $yield_quantity = '1';
    public string $notes = '';

    public array $items = [];

    public function mount(int $productId)
    {
        $this->product = Product::where('company_id', auth()->user()->company_id)->findOrFail($productId);
        $this->recipe = $this->product->recipe()->with('items')->first();

        if ($this->recipe) {
            $this->yield_quantity = (string) rtrim(rtrim($this->recipe->yield_quantity, '0'), '.');
            $this->notes = $this->recipe->notes ?? '';
            foreach ($this->recipe->items as $item) {
                $this->items[] = [
                    'product_id' => $item->product_id,
                    'quantity_base' => (string) rtrim(rtrim($item->quantity_base, '0'), '.'),
                ];
            }
        } else {
            $this->addItem();
        }
    }

    public function addItem()
    {
        $this->items[] = [
            'product_id' => '',
            'quantity_base' => '',
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
            'yield_quantity' => 'required|numeric|min:0.01',
            'notes' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.product_id' => [
                'required',
                'exists:products,id',
                // Prevent product from being its own ingredient
                function ($attribute, $value, $fail) {
                    if ($value == $this->product->id) {
                        $fail('Un producto no puede ser ingrediente de sí mismo.');
                    }
                },
            ],
            'items.*.quantity_base' => 'required|numeric|min:0.0001',
        ], [
            'items.*.product_id.required' => 'Debe seleccionar un insumo.',
            'items.*.quantity_base.required' => 'La cantidad es obligatoria.',
        ]);

        // Check duplicates
        $selectedIds = collect($this->items)->pluck('product_id')->filter();
        if ($selectedIds->duplicates()->isNotEmpty()) {
            $this->addError('items', 'No puede agregar el mismo insumo más de una vez. Consolide las cantidades.');
            return;
        }

        if (!$this->recipe) {
            $this->recipe = $this->product->recipe()->create([
                'company_id' => $this->product->company_id,
                'yield_quantity' => $this->yield_quantity,
                'notes' => $this->notes,
            ]);
        } else {
            $this->recipe->update([
                'yield_quantity' => $this->yield_quantity,
                'notes' => $this->notes,
            ]);
            $this->recipe->items()->delete(); // Wipe and recreate for simplicity
        }

        foreach ($this->items as $item) {
            $this->recipe->items()->create([
                'product_id' => $item['product_id'],
                'quantity_base' => $item['quantity_base'],
            ]);
        }

        session()->flash('message', 'Receta guardada exitosamente.');
        return $this->redirect(route('products.index'), navigate: true);
    }

    public function render()
    {
        return view('livewire.recipes.manager', [
            'ingredients' => Product::with('baseUnit')->where('company_id', auth()->user()->company_id)->where('status', 'active')->orderBy('name')->get(),
        ]);
    }
}
