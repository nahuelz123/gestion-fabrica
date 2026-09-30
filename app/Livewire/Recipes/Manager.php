<?php

namespace App\Livewire\Recipes;

use App\Models\Product;
use App\Models\Recipe;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
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

    public function mount(int $productId): void
    {
        Gate::authorize('owner-only');
        $this->product = Product::where('company_id', auth()->user()->company_id)->findOrFail($productId);
        $this->recipe = $this->product->recipe()->with('items')->first();
        if ($this->recipe) {
            $this->yield_quantity = (string) rtrim(rtrim($this->recipe->yield_quantity, '0'), '.');
            $this->notes = $this->recipe->notes ?? '';
            foreach ($this->recipe->items as $item) $this->items[] = ['product_id'=>$item->product_id,'quantity_base'=>(string)rtrim(rtrim($item->quantity_base,'0'),'.')];
        } else $this->addItem();
    }

    public function addItem(): void { Gate::authorize('owner-only'); $this->items[] = ['product_id'=>'','quantity_base'=>'']; }
    public function removeItem(int $index): void { Gate::authorize('owner-only'); unset($this->items[$index]); $this->items = array_values($this->items); }

    public function save()
    {
        Gate::authorize('owner-only');
        $companyId = auth()->user()->company_id;
        abort_unless($this->product->company_id === $companyId, 404);
        $this->validate([
            'yield_quantity'=>'required|numeric|min:0.01|max:999999999','notes'=>'nullable|string|max:2000','items'=>'required|array|min:1|max:100',
            'items.*.product_id'=>[
                'required', Rule::exists('products','id')->where(fn($q)=>$q->where('company_id',$companyId)->where('status','active')),
                function ($attribute,$value,$fail) { if ((int)$value === (int)$this->product->id) $fail('Un producto no puede ser ingrediente de sí mismo.'); },
            ],
            'items.*.quantity_base'=>'required|numeric|min:0.0001|max:999999999',
        ], ['items.*.product_id.required'=>'Debe seleccionar un insumo.','items.*.quantity_base.required'=>'La cantidad es obligatoria.']);
        $selectedIds = collect($this->items)->pluck('product_id')->filter();
        if ($selectedIds->duplicates()->isNotEmpty()) { $this->addError('items','No puede agregar el mismo insumo más de una vez. Consolide las cantidades.'); return; }

        DB::transaction(function () {
            if (!$this->recipe) {
                $this->recipe = $this->product->recipe()->create(['company_id'=>$this->product->company_id,'yield_quantity'=>$this->yield_quantity,'notes'=>trim($this->notes) ?: null]);
            } else {
                $this->recipe->update(['yield_quantity'=>$this->yield_quantity,'notes'=>trim($this->notes) ?: null]);
                $this->recipe->items()->delete();
            }
            foreach ($this->items as $item) $this->recipe->items()->create(['product_id'=>$item['product_id'],'quantity_base'=>$item['quantity_base']]);
        });
        session()->flash('message','Receta guardada exitosamente.');
        return $this->redirect(route('products.index'), navigate:true);
    }

    public function render()
    {
        Gate::authorize('owner-only');
        return view('livewire.recipes.manager', ['ingredients'=>Product::with('baseUnit')->where('company_id',auth()->user()->company_id)->where('status','active')->orderBy('name')->get()]);
    }
}
