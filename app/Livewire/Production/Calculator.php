<?php

namespace App\Livewire\Production;

use App\Enums\ProductType;
use App\Models\Product;
use App\Services\ProductionCalculatorService;
use App\Services\ProductionOrderService;
use Exception;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Calculator extends Component
{
    public string $product_id = '';
    public string $target_quantity = '1';
    public string $carros = '';
    public string $bandejas = '';
    public array $actual_consumptions = [];
    public ?array $result = null;
    public ?string $errorMessage = null;
    public ?string $successMessage = null;

    public function mount(): void
    {
        Gate::authorize('manage-production');
    }

    private function rules(): array
    {
        $companyId = auth()->user()->company_id;

        return [
            'product_id' => [
                'required',
                Rule::exists('products', 'id')->where(
                    fn ($q) => $q
                        ->where('company_id', $companyId)
                        ->where('type', ProductType::FinishedProduct->value)
                        ->where('status', 'active')
                ),
            ],
            'target_quantity' => 'required|numeric|min:0.01|max:999999999',
            'carros' => 'nullable|numeric|min:0|max:999999',
            'bandejas' => 'nullable|numeric|min:0|max:999999',
        ];
    }

    private function resolveTargetQuantity(): void
    {
        $this->validate([
            'carros' => 'nullable|numeric|min:0|max:999999',
            'bandejas' => 'nullable|numeric|min:0|max:999999',
        ]);

        $carros = $this->carros === '' ? 0.0 : (float) $this->carros;
        $bandejas = $this->bandejas === '' ? 0.0 : (float) $this->bandejas;

        if ($carros > 0 || $bandejas > 0) {
            $quantity = ProductionOrderService::toHamburguesas($carros, $bandejas);
            $this->target_quantity = rtrim(rtrim(number_format($quantity, 4, '.', ''), '0'), '.');
        }
    }

    public function calculate(ProductionCalculatorService $calculator): void
    {
        Gate::authorize('manage-production');
        $this->resolveTargetQuantity();
        $this->validate($this->rules());

        $this->errorMessage = null;
        $this->successMessage = null;
        $this->result = null;
        $this->actual_consumptions = [];

        $product = Product::where('company_id', auth()->user()->company_id)
            ->where('type', ProductType::FinishedProduct)
            ->where('status', 'active')
            ->findOrFail($this->product_id);

        try {
            $this->result = $calculator->calculateRequirements($product, (float) $this->target_quantity);

            // Precargamos el consumo teórico para que el encargado sólo tenga que
            // tocar los valores que realmente fueron distintos durante el turno.
            foreach ($this->result['items'] as $item) {
                $this->actual_consumptions[(string) $item['ingredient']->id] = rtrim(
                    rtrim(number_format((float) $item['required'], 4, '.', ''), '0'),
                    '.'
                );
            }
        } catch (Exception $e) {
            $this->errorMessage = $e->getMessage();
        }
    }

    public function confirm(ProductionOrderService $productionOrderService): void
    {
        Gate::authorize('manage-production');
        $this->resolveTargetQuantity();
        $this->validate($this->rules());

        $product = Product::where('company_id', auth()->user()->company_id)
            ->where('type', ProductType::FinishedProduct)
            ->where('status', 'active')
            ->with('recipe.items')
            ->findOrFail($this->product_id);

        $recipeIngredientIds = $product->recipe?->items->pluck('product_id')->map(fn ($id) => (int) $id)->all() ?? [];
        $actualConsumptions = [];

        foreach ($this->actual_consumptions as $productId => $quantity) {
            if ($quantity === '' || $quantity === null) continue;

            $productId = (int) $productId;
            if (!in_array($productId, $recipeIngredientIds, true)) {
                $this->errorMessage = 'Se detectó un insumo que no pertenece a la receta. Volvé a calcular antes de confirmar.';
                return;
            }
            if (!is_numeric($quantity) || !is_finite((float) $quantity) || (float) $quantity < 0) {
                $this->errorMessage = 'Los consumos reales deben ser cantidades válidas y no negativas.';
                return;
            }

            $actualConsumptions[$productId] = (float) $quantity;
        }

        try {
            $result = $productionOrderService->registerProduction(
                auth()->user(),
                $product,
                (float) $this->target_quantity,
                $actualConsumptions
            );

            $this->result = null;
            $this->product_id = '';
            $this->target_quantity = '1';
            $this->carros = '';
            $this->bandejas = '';
            $this->actual_consumptions = [];
            $this->successMessage = '✅ Producción registrada. Se descontaron los consumos informados y se sumó el producto terminado.';

            if (!empty($result['warnings'])) {
                $this->successMessage .= "\n" . implode("\n", $result['warnings']);
            }
        } catch (Exception $e) {
            $this->errorMessage = $e->getMessage();
        }
    }

    public function render()
    {
        Gate::authorize('manage-production');

        $products = Product::where('company_id', auth()->user()->company_id)
            ->where('status', 'active')
            ->where('type', ProductType::FinishedProduct)
            ->whereHas('recipe')
            ->orderBy('name')
            ->get();

        return view('livewire.production.calculator', ['products' => $products]);
    }
}
