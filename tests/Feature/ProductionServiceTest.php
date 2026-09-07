<?php

namespace Tests\Feature;

use App\Enums\MovementType;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Recipe;
use App\Models\RecipeItem;
use App\Models\Stock;
use App\Models\StockLot;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\ProductionService;
use App\Services\StockService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Tests\TestCase;

class ProductionServiceTest extends TestCase
{
    use DatabaseTruncation;

    public function test_fefo_consumption()
    {
        $company = Company::create(['name' => 'Test Company']);
        $user = User::create(['company_id' => $company->id, 'name' => 'Test', 'email' => 'test2@test.com', 'password' => 'pass']);
        $warehouse = Warehouse::create(['company_id' => $company->id, 'name' => 'Test Warehouse']);
        $unit = Unit::create(['name' => 'Unit', 'abbreviation' => 'u', 'type' => 'count']);
        $category = ProductCategory::create(['company_id' => $company->id, 'name' => 'Test Category']);
        
        $productoFinal = Product::create([
            'company_id' => $company->id,
            'category_id' => $category->id,
            'name' => 'Producto Final',
            'internal_code' => 'PF',
            'base_unit_id' => $unit->id,
            'requires_lot' => false,
        ]);

        $insumo = Product::create([
            'company_id' => $company->id,
            'category_id' => $category->id,
            'name' => 'Insumo FEFO',
            'internal_code' => 'IF',
            'base_unit_id' => $unit->id,
            'requires_lot' => true,
        ]);

        $recipe = Recipe::create([
            'company_id' => $company->id,
            'product_id' => $productoFinal->id,
            'yield_quantity' => 1,
        ]);

        RecipeItem::create([
            'recipe_id' => $recipe->id,
            'product_id' => $insumo->id,
            'quantity_base' => 1, // 1 a 1
        ]);

        $stockService = app(StockService::class);

        // Lote A: 20 unidades, vence antes (01/01/2026)
        $stockService->registerMovement([
            'company_id' => $company->id,
            'product_id' => $insumo->id,
            'warehouse_id' => $warehouse->id,
            'type' => MovementType::AdjustmentIn,
            'quantity_base' => 20,
            'user_id' => $user->id,
            'reference_type' => 'Test',
            'reference_id' => 1,
            'lot_data' => [
                'lot_code' => 'LOTE-A',
                'expiration_date' => '2026-01-01'
            ]
        ]);

        // Lote B: 30 unidades, vence despues (31/12/2026)
        $stockService->registerMovement([
            'company_id' => $company->id,
            'product_id' => $insumo->id,
            'warehouse_id' => $warehouse->id,
            'type' => MovementType::AdjustmentIn,
            'quantity_base' => 30,
            'user_id' => $user->id,
            'reference_type' => 'Test',
            'reference_id' => 1,
            'lot_data' => [
                'lot_code' => 'LOTE-B',
                'expiration_date' => '2026-12-31'
            ]
        ]);

        $loteA = StockLot::where('lot_code', 'LOTE-A')->first();
        $loteB = StockLot::where('lot_code', 'LOTE-B')->first();

        // Verificar stock inicial
        $this->assertEquals(20, Stock::where('lot_id', $loteA->id)->value('quantity'));
        $this->assertEquals(30, Stock::where('lot_id', $loteB->id)->value('quantity'));

        // Pedir fabricar 35 unidades (Necesitamos 35 del insumo)
        $service = app(ProductionService::class);
        $order = $service->executeProduction(
            $productoFinal->id,
            $warehouse->id,
            35,
            [], // No final lot needed
            $user->id
        );

        // Verificar saldos despues del FEFO
        // Lote A deberia estar en 0 (consumio sus 20)
        $this->assertEquals(0, Stock::where('lot_id', $loteA->id)->value('quantity'));
        
        // Lote B deberia estar en 15 (consumio los 15 restantes)
        $this->assertEquals(15, Stock::where('lot_id', $loteB->id)->value('quantity'));

        // Verificar StockMovements de consumo (tienen que ser 2)
        $consumos = StockMovement::where('reference_type', \App\Models\ProductionOrder::class)
            ->where('reference_id', $order->id)
            ->where('type', 'production_consumption')
            ->orderBy('id', 'asc')
            ->get();

        $this->assertCount(2, $consumos);
        $this->assertEquals(-20, $consumos[0]->quantity_base);
        $this->assertEquals($loteA->id, $consumos[0]->lot_id);

        $this->assertEquals(-15, $consumos[1]->quantity_base);
        $this->assertEquals($loteB->id, $consumos[1]->lot_id);
    }
}
