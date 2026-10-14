<?php

namespace Tests\Feature;

use App\Enums\Channel;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Recipe;
use App\Models\Stock;
use App\Models\StockLot;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\ProductionCalculatorService;
use App\Services\ProductionOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductionLotEligibilityTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $company = Company::create(['name' => 'Fábrica']);
        $user = User::create(['company_id' => $company->id, 'name' => 'Dueño', 'email' => 'lots@test.com', 'password' => 'pass', 'role' => 'owner', 'status' => 'active']);
        $warehouse = Warehouse::create(['company_id' => $company->id, 'name' => 'Principal']);
        $unit = Unit::create(['name' => 'Unidad', 'abbreviation' => 'u', 'type' => 'count']);
        $category = ProductCategory::create(['company_id' => $company->id, 'name' => 'General']);
        $ingredient = Product::create(['company_id' => $company->id, 'category_id' => $category->id, 'name' => 'Carne', 'internal_code' => 'CAR-LOT', 'type' => 'raw_material', 'base_unit_id' => $unit->id, 'status' => 'active', 'requires_lot' => true]);
        $finished = Product::create(['company_id' => $company->id, 'category_id' => $category->id, 'name' => 'Hamburguesa', 'internal_code' => 'HAM-LOT', 'type' => 'finished_product', 'base_unit_id' => $unit->id, 'status' => 'active']);
        $recipe = Recipe::create(['company_id' => $company->id, 'product_id' => $finished->id, 'yield_quantity' => 10]);
        $recipe->items()->create(['product_id' => $ingredient->id, 'quantity_base' => 10]);

        $lotAmounts = [
            ['code' => 'VENCIDO', 'status' => 'active', 'expires' => now()->subDay()->toDateString(), 'quantity' => 100],
            ['code' => 'BLOQUEADO', 'status' => 'blocked', 'expires' => now()->addDays(5)->toDateString(), 'quantity' => 100],
            ['code' => 'VIGENTE', 'status' => 'active', 'expires' => now()->addDays(2)->toDateString(), 'quantity' => 5],
        ];

        foreach ($lotAmounts as $row) {
            $lot = StockLot::create([
                'company_id' => $company->id, 'product_id' => $ingredient->id, 'lot_code' => $row['code'],
                'entry_date' => now()->toDateString(), 'expiration_date' => $row['expires'],
                'initial_quantity' => $row['quantity'], 'status' => $row['status'],
            ]);
            Stock::create(['company_id' => $company->id, 'product_id' => $ingredient->id, 'warehouse_id' => $warehouse->id, 'lot_id' => $lot->id, 'quantity' => $row['quantity']]);
        }

        return compact('company', 'user', 'warehouse', 'ingredient', 'finished');
    }

    public function test_production_calculator_excludes_expired_and_blocked_lots(): void
    {
        $data = $this->fixture();
        $result = app(ProductionCalculatorService::class)->calculateRequirements($data['finished'], 10, $data['warehouse']->id);
        $this->assertFalse($result['can_produce']);
        $this->assertEquals(5, $result['items'][0]['available']);
        $this->assertEquals(5, $result['items'][0]['missing']);
        $this->assertEquals(5, app(ProductionCalculatorService::class)->calculateMaxProducible($data['finished'], [], $data['warehouse']->id)['max_units']);
    }

    public function test_production_never_uses_expired_or_blocked_lots(): void
    {
        $data = $this->fixture();
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('No alcanza el stock loteado');
        app(ProductionOrderService::class)->registerProduction($data['user'], $data['finished'], 10, [], Channel::Web, $data['warehouse']->id);
    }
}
