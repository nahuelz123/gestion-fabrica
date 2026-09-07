<?php

namespace App\Livewire\Production;

use App\Models\Product;
use App\Services\ProductionCalculatorService;
use Exception;
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
    public string $output_lot_code = '';
    public string $output_expiration_date = '';

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

    public function confirm(ProductionService $productionService)
    {
        $this->validate([
            'warehouse_id' => 'required|exists:warehouses,id',
        ]);

        $product = Product::find($this->product_id);

        if ($product->requires_lot) {
            $this->validate([
                'output_lot_code' => 'required|string|max:255',
            ], [
                'output_lot_code.required' => 'El código de lote es obligatorio para este producto final.',
            ]);

            if ($product->requires_expiration) {
                $this->validate([
                    'output_expiration_date' => 'required|date',
                ]);
            }
        }

        try {
            $productionService->executeProduction(
                $this->product_id,
                $this->warehouse_id,
                (float) $this->target_quantity,
                [
                    'lot_code' => $this->output_lot_code ?: null,
                    'expiration_date' => $this->output_expiration_date ?: null,
                ],
                auth()->id()
            );

            $this->result = null;
            $this->product_id = '';
            $this->target_quantity = '1';
            $this->output_lot_code = '';
            $this->output_expiration_date = '';
            $this->successMessage = 'Producción registrada exitosamente. El stock fue descontado y el producto final ingresado.';
            
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

        $warehouses = \App\Models\Warehouse::where('company_id', auth()->user()->company_id)->orderBy('name')->get();

        return view('livewire.production.calculator', [
            'products' => $products,
            'warehouses' => $warehouses,
        ]);
    }
}
