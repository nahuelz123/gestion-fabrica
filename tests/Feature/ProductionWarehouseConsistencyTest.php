<?php

namespace Tests\Feature;

use App\Enums\Channel;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Recipe;
use App\Models\Stock;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\ProductionCalculatorService;
use App\Services\ProductionOrderService;
use App\Services\ProductionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductionWarehouseConsistencyTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $company = Company::create(['name' => 'Fábrica']);
        $user = User::create(['company_id' => $company->id, 'name' => 'Dueño', 'email' => 'warehouse@test.com', 'password' => 'pass', 'role' => 'owner', 'status' => 'active']);
        $primary = Warehouse::create(['company_id' => $company->id, 'name' => 'Principal']);
        $secondary = Warehouse::create(['company_id' => $company->id, 'name' => 'Secundario']);
        $unit = Unit::create(['name' => 'Unidad', 'abbreviation' => 'u', 'type' => 'count']);
        $category = ProductCategory::create(['company_id' => $company->id, 'name' => 'General']);
        $ingredient = Product::create(['company_id' => $company->id, 'category_id' => $category->id, 'name' => 'Pan', 'internal_code' => 'PAN-W', 'type' => 'raw_material', 'base_unit_id' => $unit->id, 'status' => 'active']);
        $finished = Product::create(['company_id' => $company->id, 'category_id' => $category->id, 'name' => 'Hamburguesa', 'internal_code' => 'HAM-W', 'type' => 'finished_product', 'base_unit_id' => $unit->id, 'status' => 'active']);
        $recipe = Recipe::create(['company_id' => $company->id, 'product_id' => $finished->id, 'yield_quantity' => 10]);
        $recipe->items()->create(['product_id' => $ingredient->id, 'quantity_base' => 10]);
        Stock::create(['company_id' => $company->id, 'product_id' => $ingredient->id, 'warehouse_id' => $secondary->id, 'quantity' => 100]);
        return compact('company', 'user', 'primary', 'secondary', 'ingredient', 'finished');
    }

    public function test_calculator_only_uses_selected_warehouse(): void
    {
        $d = $this->fixture();
        $calculator = app(ProductionCalculatorService::class);
        $primary = $calculator->calculateRequirements($d['finished'], 10, $d['primary']->id);
        $this->assertFalse($primary['can_produce']);
        $this->assertEquals(10, $primary['items'][0]['missing']);
        $this->assertTrue($calculator->calculateRequirements($d['finished'], 10)['can_produce']);
        $this->assertSame(0, $calculator->calculateMaxProducible($d['finished'], [], $d['primary']->id)['max_units']);
    }

    public function test_selected_warehouse_cannot_use_other_deposits_stock(): void
    {
        $d = $this->fixture();
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('No hay stock suficiente');
        app(ProductionService::class)->executeProduction($d['finished']->id, $d['primary']->id, 10, [], $d['user']->id);
    }

    public function test_production_registration_deducts_selected_warehouse_only(): void
    {
        $d = $this->fixture();
        Stock::create(['company_id' => $d['company']->id, 'product_id' => $d['ingredient']->id, 'warehouse_id' => $d['primary']->id, 'quantity' => 20]);
        $result = app(ProductionOrderService::class)->registerProduction($d['user'], $d['finished'], 10, [], Channel::Web, $d['primary']->id);
        $this->assertSame($d['primary']->id, (int) $result['order']->warehouse_id);
        $this->assertEquals(10, Stock::where('product_id', $d['ingredient']->id)->where('warehouse_id', $d['primary']->id)->sum('quantity'));
        $this->assertEquals(100, Stock::where('product_id', $d['ingredient']->id)->where('warehouse_id', $d['secondary']->id)->sum('quantity'));
    }
}
