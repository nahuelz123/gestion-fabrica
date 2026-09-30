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
    public ?array $result = null;
    public ?string $errorMessage = null;
    public ?string $successMessage = null;

    public function mount(): void
    {
        Gate::authorize('simulate-production');
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
        ];
    }

    public function calculate(ProductionCalculatorService $calculator): void
    {
        Gate::authorize('simulate-production');
        $this->validate($this->rules());

        $this->errorMessage = null;
        $this->successMessage = null;
        $this->result = null;

        $product = Product::where('company_id', auth()->user()->company_id)
            ->where('type', ProductType::FinishedProduct)
            ->where('status', 'active')
            ->findOrFail($this->product_id);

        try {
            $this->result = $calculator->calculateRequirements($product, (float) $this->target_quantity);
        } catch (Exception $e) {
            $this->errorMessage = $e->getMessage();
        }
    }

    public function confirm(ProductionOrderService $productionOrderService): void
    {
        // El simulador es compartido con el gerente, pero cualquier escritura de
        // producción queda explícitamente protegida para el dueño.
        Gate::authorize('owner-only');
        $this->validate($this->rules());

        $product = Product::where('company_id', auth()->user()->company_id)
            ->where('type', ProductType::FinishedProduct)
            ->where('status', 'active')
            ->findOrFail($this->product_id);

        try {
            $result = $productionOrderService->registerProduction(
                auth()->user(),
                $product,
                (float) $this->target_quantity
            );

            $this->result = null;
            $this->product_id = '';
            $this->target_quantity = '1';
            $this->successMessage = '✅ Producción registrada. Se descontaron los insumos y se sumó el producto terminado.';

            if (!empty($result['warnings'])) {
                $this->successMessage .= "\n" . implode("\n", $result['warnings']);
            }
        } catch (Exception $e) {
            $this->errorMessage = $e->getMessage();
        }
    }

    public function render()
    {
        Gate::authorize('simulate-production');

        $products = Product::where('company_id', auth()->user()->company_id)
            ->where('status', 'active')
            ->where('type', ProductType::FinishedProduct)
            ->whereHas('recipe')
            ->orderBy('name')
            ->get();

        return view('livewire.production.calculator', ['products' => $products]);
    }
}
