<?php

namespace Tests\Feature;

use App\Enums\Channel;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Recipe;
use App\Models\RecipeItem;
use App\Models\Stock;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\ProductionOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductionOrderServiceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Company $company;
    private Warehouse $warehouse;
    private Product $finishedProduct;
    private Product $ingredient1;
    private Product $ingredient2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['name' => 'Test Company']);
        $this->user = User::factory()->create(['company_id' => $this->company->id, 'role' => 'owner']);
        $this->warehouse = Warehouse::create(['company_id' => $this->company->id, 'name' => 'Main Warehouse']);
        
        $unit = Unit::create(['name' => 'Unidad', 'abbreviation' => 'u', 'type' => 'count']);
        $category = ProductCategory::create(['company_id' => $this->company->id, 'name' => 'Insumos']);

        $this->ingredient1 = Product::create([
            'company_id' => $this->company->id, 'category_id' => $category->id,
            'name' => 'Pan', 'presentation' => 'unidad', 'type' => 'raw_material', 'base_unit_id' => $unit->id,
        ]);
        
        $this->ingredient2 = Product::create([
            'company_id' => $this->company->id, 'category_id' => $category->id,
            'name' => 'Carne', 'presentation' => 'unidad', 'type' => 'raw_material', 'base_unit_id' => $unit->id,
        ]);

        $this->finishedProduct = Product::create([
            'company_id' => $this->company->id, 'category_id' => $category->id,
            'name' => 'Hamburguesa', 'presentation' => 'unidad', 'type' => 'finished_product', 'base_unit_id' => $unit->id,
        ]);

        $recipe = Recipe::create([
            'company_id' => $this->company->id,
            'product_id' => $this->finishedProduct->id,
            'yield_quantity' => 24, // 1 bandeja
        ]);

        RecipeItem::create(['recipe_id' => $recipe->id, 'product_id' => $this->ingredient1->id, 'quantity_base' => 24]);
        RecipeItem::create(['recipe_id' => $recipe->id, 'product_id' => $this->ingredient2->id, 'quantity_base' => 48]); // 2 carnes por hamb.
    }

    public function test_to_hamburguesas_conversion()
    {
        $this->assertEquals(288, ProductionOrderService::toHamburguesas(1, 0));
        $this->assertEquals(864, ProductionOrderService::toHamburguesas(3, 0));
        $this->assertEquals(912, ProductionOrderService::toHamburguesas(3, 2));
        $this->assertEquals(1008, ProductionOrderService::toHamburguesas(3.5, 0));
    }

    public function test_register_production_with_sufficient_stock()
    {
        Stock::create(['company_id' => $this->company->id, 'product_id' => $this->ingredient1->id, 'warehouse_id' => $this->warehouse->id, 'quantity' => 1000]);
        Stock::create(['company_id' => $this->company->id, 'product_id' => $this->ingredient2->id, 'warehouse_id' => $this->warehouse->id, 'quantity' => 2000]);

        $service = app(ProductionOrderService::class);
        $result = $service->registerProduction($this->user, $this->finishedProduct, 24); // 24 produced

        $this->assertEmpty($result['warnings']);
        $this->assertEquals(24, $result['order']->target_quantity);
        
        $this->assertEquals(1000 - 24, Stock::where('product_id', $this->ingredient1->id)->value('quantity'));
        $this->assertEquals(2000 - 48, Stock::where('product_id', $this->ingredient2->id)->value('quantity'));
        $this->assertEquals(24, Stock::where('product_id', $this->finishedProduct->id)->value('quantity'));
    }

    public function test_register_production_with_insufficient_stock_allows_negative()
    {
        Stock::create(['company_id' => $this->company->id, 'product_id' => $this->ingredient1->id, 'warehouse_id' => $this->warehouse->id, 'quantity' => 10]);
        Stock::create(['company_id' => $this->company->id, 'product_id' => $this->ingredient2->id, 'warehouse_id' => $this->warehouse->id, 'quantity' => 10]);

        $service = app(ProductionOrderService::class);
        $result = $service->registerProduction($this->user, $this->finishedProduct, 24); // Needs 24 and 48

        $this->assertNotEmpty($result['warnings']);
        
        // Stock goes negative
        $this->assertEquals(10 - 24, Stock::where('product_id', $this->ingredient1->id)->value('quantity'));
        $this->assertEquals(10 - 48, Stock::where('product_id', $this->ingredient2->id)->value('quantity'));
        
        // Finished product gets added
        $this->assertEquals(24, Stock::where('product_id', $this->finishedProduct->id)->value('quantity'));
    }

    public function test_register_production_with_actual_consumptions()
    {
        Stock::create(['company_id' => $this->company->id, 'product_id' => $this->ingredient1->id, 'warehouse_id' => $this->warehouse->id, 'quantity' => 1000]);
        Stock::create(['company_id' => $this->company->id, 'product_id' => $this->ingredient2->id, 'warehouse_id' => $this->warehouse->id, 'quantity' => 2000]);

        $service = app(ProductionOrderService::class);
        
        // We inform that we used 30 of ing1 and 50 of ing2 instead of 24 and 48
        $result = $service->registerProduction($this->user, $this->finishedProduct, 24, [
            $this->ingredient1->id => 30,
            $this->ingredient2->id => 50,
        ]);

        $this->assertEmpty($result['warnings']);
        
        $this->assertEquals(1000 - 30, Stock::where('product_id', $this->ingredient1->id)->value('quantity'));
        $this->assertEquals(2000 - 50, Stock::where('product_id', $this->ingredient2->id)->value('quantity'));
    }
}