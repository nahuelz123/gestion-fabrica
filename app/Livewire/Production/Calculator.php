<?php

namespace App\Livewire\Production;

use App\Models\Product;
use App\Services\ProductionCalculatorService;
use App\Services\ProductionOrderService;
use Exception;
use Illuminate\Support\Facades\Auth;
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

    // Fields for execution
    public string $warehouse_id = '';

    public function calculate(ProductionCalculatorService $calculator)
    {
        $this->validate([
            'product_id' => 'required|exists:products,id',
            'target_quantity' => 'required|numeric|min:0.01',
        ]);

        $this->errorMessage = null;
        $this->successMessage = null;
        $this->result = null;

        $product = Product::where('company_id', auth()->user()->company_id)->find($this->product_id);

        try {
            $this->result = $calculator->calculateRequirements($product, (float) $this->target_quantity);
        } catch (Exception $e) {
            $this->errorMessage = $e->getMessage();
        }
    }

    public function confirm(ProductionOrderService $productionOrderService)
    {
        $this->validate([
            'product_id' => 'required|exists:products,id',
            'target_quantity' => 'required|numeric|min:0.01',
        ]);

        $product = Product::where('company_id', auth()->user()->company_id)->findOrFail($this->product_id);

        try {
            $result = $productionOrderService->registerProduction(
                auth()->user(),
                $product,
                (float) $this->target_quantity,
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
        $products = Product::where('company_id', auth()->user()->company_id)
            ->where('status', 'active')
            ->whereHas('recipe')
            ->orderBy('name')
            ->get();

        return view('livewire.production.calculator', [
            'products' => $products,
        ]);
    }
}
