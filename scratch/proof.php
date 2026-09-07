<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Company;
use App\Models\ProductCategory;
use App\Models\Product;
use App\Models\Stock;
use App\Models\Unit;
use App\Enums\MovementType;
use Illuminate\Support\Facades\DB;

$company = Company::first();
$cat = ProductCategory::first();
$unit = Unit::first();

// Clean up if running multiple times
Product::whereIn('internal_code', ['PA', 'PB'])->delete();

// Create A and B
$prodA = Product::create(['company_id' => $company->id, 'category_id' => $cat->id, 'name' => 'Prod A', 'internal_code' => 'PA', 'base_unit_id' => $unit->id]);
$prodB = Product::create(['company_id' => $company->id, 'category_id' => $cat->id, 'name' => 'Prod B', 'internal_code' => 'PB', 'base_unit_id' => $unit->id]);

// Give Stock
$stockService = app(\App\Services\StockService::class);
$stockService->registerMovement(['company_id' => $company->id, 'product_id' => $prodA->id, 'warehouse_id' => 1, 'type' => MovementType::AdjustmentIn, 'quantity_base' => 100, 'user_id' => 1, 'reference_type' => 'App\Models\Company', 'reference_id' => 1]);
$stockService->registerMovement(['company_id' => $company->id, 'product_id' => $prodB->id, 'warehouse_id' => 1, 'type' => MovementType::AdjustmentIn, 'quantity_base' => 10, 'user_id' => 1, 'reference_type' => 'App\Models\Company', 'reference_id' => 1]);

$stockABefore = Stock::where('product_id', $prodA->id)->value('quantity');

echo "\n--- INICIO DE PRUEBA ---\n";
echo "1. Stock A antes de la transaccion: {$stockABefore}\n";

try {
    DB::transaction(function() use ($stockService, $company, $prodA, $prodB) {
        echo "2. Procesando A...\n";
        $stockService->registerMovement(['company_id' => $company->id, 'product_id' => $prodA->id, 'warehouse_id' => 1, 'type' => MovementType::ProductionConsumption, 'quantity_base' => -50, 'user_id' => 1, 'reference_type' => 'App\Models\Company', 'reference_id' => 2]);
        
        $stockAInside = Stock::where('product_id', $prodA->id)->value('quantity');
        echo "3. Stock A dentro de la transaccion (despues de descontar A): {$stockAInside}\n";

        echo "4. Procesando B...\n";
        throw new \Exception("Fallo intencional al procesar B porque no alcanzo el stock.");

        echo "5. Procesando C... (ESTO NO DEBE IMPRIMIRSE NUNCA)\n"; 
    });
} catch (\Exception $e) {
    echo "6. Excepcion atrapada en B: " . $e->getMessage() . "\n";
}

$stockAAfter = Stock::where('product_id', $prodA->id)->value('quantity');
echo "7. Stock A despues del rollback de la transaccion: {$stockAAfter}\n";
echo "--- FIN DE PRUEBA ---\n";
