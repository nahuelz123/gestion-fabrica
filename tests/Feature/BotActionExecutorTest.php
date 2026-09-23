<?php
namespace Tests\Feature;

use App\Models\Company;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Stock;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\BotActionExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BotActionExecutorTest extends TestCase
{
    use RefreshDatabase;

    private function setupData()
    {
        $companyA = Company::create(['name' => 'Empresa A']);
        $companyB = Company::create(['name' => 'Empresa B']);

        $userA = User::create(['company_id' => $companyA->id, 'name' => 'User A', 'email' => 'a@test.com', 'password' => 'pass', 'telegram_chat_id' => '111']);
        $userB = User::create(['company_id' => $companyB->id, 'name' => 'User B', 'email' => 'b@test.com', 'password' => 'pass', 'telegram_chat_id' => '222']);

        $warehouseA = Warehouse::create(['company_id' => $companyA->id, 'name' => 'Depo A']);
        $warehouseB = Warehouse::create(['company_id' => $companyB->id, 'name' => 'Depo B']);

        $unit = Unit::create(['name' => 'Unit', 'abbreviation' => 'u', 'type' => 'count']);
        $catA = ProductCategory::create(['company_id' => $companyA->id, 'name' => 'Cat A']);
        $catB = ProductCategory::create(['company_id' => $companyB->id, 'name' => 'Cat B']);

        $prodA = Product::create(['company_id' => $companyA->id, 'category_id' => $catA->id, 'name' => 'Papel', 'internal_code' => 'PA', 'base_unit_id' => $unit->id, 'requires_lot' => false]);
        $prodB = Product::create(['company_id' => $companyB->id, 'category_id' => $catB->id, 'name' => 'Papel', 'internal_code' => 'PB', 'base_unit_id' => $unit->id, 'requires_lot' => false]);

        return [$companyA, $companyB, $userA, $userB, $prodA, $prodB];
    }

    // 1. Usuario de empresa A no puede consultar producto de empresa B.
    public function test_user_a_cannot_query_product_b()
    {
        list($companyA, $companyB, $userA, $userB, $prodA, $prodB) = $this->setupData();
        $prodB->update(['name' => 'Exclusivo B']);

        $executor = app(BotActionExecutor::class);
        $result = $executor->execute($userA, '111', ['name' => 'get_stock', 'arguments' => ['product_name' => 'Exclusivo B']], []);
        
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('No encontré ningún producto llamado', $result['message']);
    }

    // 2. Usuario de empresa A no puede modificar producto de empresa B.
    public function test_user_a_cannot_modify_product_b()
    {
        list($companyA, $companyB, $userA, $userB, $prodA, $prodB) = $this->setupData();
        $prodB->update(['name' => 'Exclusivo B']);

        $executor = app(BotActionExecutor::class);
        $result = $executor->execute($userA, '111', ['name' => 'register_stock', 'arguments' => ['product_name' => 'Exclusivo B', 'quantity' => 10]], []);
        
        $this->assertFalse($result['success']);
    }

    // 11. create_product genera código interno automáticamente
    // 12. Mantiene presentation
    // 13. Mantiene tipo insumo
    public function test_create_product_success()
    {
        list($companyA, $companyB, $userA) = $this->setupData();
        $executor = app(BotActionExecutor::class);
        
        $result = $executor->execute($userA, '111', ['name' => 'create_product', 'arguments' => ['name' => 'Harina', 'presentation' => 'Bolsa 50kg']], []);
        
        $this->assertTrue($result['success']);
        $prod = Product::where('name', 'Harina')->first();
        $this->assertNotNull($prod->internal_code);
        $this->assertEquals('raw_material', $prod->type->value);
        $this->assertEquals('Bolsa 50kg', $prod->presentations->first()->name);
    }

    // 14. register_stock reutiliza lógica (y verifica stock)
    public function test_register_stock_success()
    {
        list($companyA, $companyB, $userA, $userB, $prodA) = $this->setupData();
        $executor = app(BotActionExecutor::class);
        
        $result = $executor->execute($userA, '111', ['name' => 'register_stock', 'arguments' => ['product_name' => 'Papel', 'quantity' => 5]], []);
        
        $this->assertTrue($result['success']);
        $this->assertEquals(5, Stock::where('product_id', $prodA->id)->sum('quantity'));
    }

    // 16. get_stock no modifica datos
    public function test_get_stock()
    {
        list($companyA, $companyB, $userA, $userB, $prodA) = $this->setupData();
        $executor = app(BotActionExecutor::class);
        
        $result = $executor->execute($userA, '111', ['name' => 'get_stock', 'arguments' => ['product_name' => 'Papel']], []);
        
        $this->assertTrue($result['success']);
        $this->assertStringContainsString('Tenemos 0', $result['message']);
    }

    // 18. Producto ambiguo no se selecciona arbitrariamente
    public function test_ambiguous_product()
    {
        list($companyA, $companyB, $userA, $userB, $prodA) = $this->setupData();
        Product::create(['company_id' => $companyA->id, 'category_id' => $prodA->category_id, 'name' => 'Papel Kraft', 'internal_code' => 'PK', 'base_unit_id' => $prodA->base_unit_id, 'requires_lot' => false]);
        
        $executor = app(BotActionExecutor::class);
        $result = $executor->execute($userA, '111', ['name' => 'get_stock', 'arguments' => ['product_name' => 'Papel']], []);
        
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('varios productos que coinciden', $result['message']);
    }

    // NEW 1. Inyección maliciosa de company_id
    public function test_malicious_company_id_is_ignored()
    {
        list($companyA, $companyB, $userA, $userB, $prodA, $prodB) = $this->setupData();
        $prodB->update(['name' => 'Exclusivo B']);

        $executor = app(BotActionExecutor::class);
        // User A tries to modify Exclusivo B passing companyB id explicitly in arguments
        $result = $executor->execute($userA, '111', [
            'name' => 'register_stock', 
            'arguments' => [
                'product_name' => 'Exclusivo B', 
                'quantity' => 10,
                'company_id' => $companyB->id // Malicious injection
            ]
        ], []);
        
        $this->assertFalse($result['success']);
        $this->assertEquals(0, Stock::where('product_id', $prodB->id)->sum('quantity'));
    }

    // NEW 2. Rollback real
    public function test_transaction_rollback_on_failure()
    {
        list($companyA, $companyB, $userA) = $this->setupData();
        
        // Mock ProductService to fail inside transaction to prove rollback
        $mock = \Mockery::mock(\App\Services\ProductService::class);
        $mock->shouldReceive('createProduct')->andThrow(new \Exception("DB Error"));
        $this->app->instance(\App\Services\ProductService::class, $mock);
        
        $executor = app(BotActionExecutor::class);
        
        $initialProductCount = Product::count();
        $initialPresentationCount = \App\Models\ProductPresentation::count();
        
        $result = $executor->execute($userA, '111', ['name' => 'create_product', 'arguments' => ['name' => 'Harina', 'presentation' => 'Bolsa 50kg']], []);
        
        $this->assertFalse($result['success']);
        $this->assertEquals('No pude completar la operación debido a un problema interno.', $result['message']);
        
        // Assert rollback
        $this->assertEquals($initialProductCount, Product::count());
        $this->assertEquals($initialPresentationCount, \App\Models\ProductPresentation::count());
    }

    // NEW 4. Ambigüedad en register_stock
    public function test_register_stock_rejects_ambiguous_product()
    {
        list($companyA, $companyB, $userA, $userB, $prodA) = $this->setupData();
        Product::create(['company_id' => $companyA->id, 'category_id' => $prodA->category_id, 'name' => 'Papel Kraft', 'internal_code' => 'PK', 'base_unit_id' => $prodA->base_unit_id, 'requires_lot' => false]);
        
        $executor = app(BotActionExecutor::class);
        $result = $executor->execute($userA, '111', ['name' => 'register_stock', 'arguments' => ['product_name' => 'Papel', 'quantity' => 10]], []);
        
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('varios productos que coinciden', $result['message']);
        
        // Assert no stock was modified arbitrarily
        $this->assertEquals(0, Stock::where('product_id', $prodA->id)->sum('quantity'));
    }

    // NEW 5. Test de get_low_stock
    public function test_get_low_stock()
    {
        list($companyA, $companyB, $userA) = $this->setupData();
        $executor = app(BotActionExecutor::class);
        
        $result = $executor->execute($userA, '111', ['name' => 'get_low_stock', 'arguments' => []], []);
        
        // success false indicates it doesn't do anything yet (not implemented in DB)
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('todavía no está configurada', $result['message']);
    }

    public function test_set_add_remove_stock()
    {
        list($companyA, $companyB, $userA) = $this->setupData();
        
        $unit = Unit::create(['name' => 'Unidad', 'abbreviation' => 'u', 'type' => 'count']);
        $cat = ProductCategory::create(['company_id' => $companyA->id, 'name' => 'Cat']);
        $prod = Product::create([
            'company_id' => $companyA->id, 'category_id' => $cat->id,
            'name' => 'Bacon', 'presentation' => 'unidad', 'type' => 'raw_material', 'base_unit_id' => $unit->id,
        ]);
        
        $executor = app(BotActionExecutor::class);
        
        // set_stock
        $res = $executor->execute($userA, '111', ['name' => 'set_stock', 'arguments' => ['product_name' => 'Bacon', 'quantity' => 12]], []);
        $this->assertTrue($res['success']);
        $this->assertEquals(12, Stock::where('product_id', $prod->id)->sum('quantity'));

        // add_stock
        $res = $executor->execute($userA, '111', ['name' => 'add_stock', 'arguments' => ['product_name' => 'Bacon', 'quantity' => 3]], []);
        $this->assertTrue($res['success']);
        $this->assertEquals(15, Stock::where('product_id', $prod->id)->sum('quantity'));

        // remove_stock
        $res = $executor->execute($userA, '111', ['name' => 'remove_stock', 'arguments' => ['product_name' => 'Bacon', 'quantity' => 5]], []);
        $this->assertTrue($res['success']);
        $this->assertEquals(10, Stock::where('product_id', $prod->id)->sum('quantity'));
    }

    public function test_register_production()
    {
        list($companyA, $companyB, $userA) = $this->setupData();
        
        $unit = Unit::create(['name' => 'Unidad', 'abbreviation' => 'u', 'type' => 'count']);
        $cat = ProductCategory::create(['company_id' => $companyA->id, 'name' => 'Cat']);
        $ingredient = Product::create([
            'company_id' => $companyA->id, 'category_id' => $cat->id,
            'name' => 'Pan', 'presentation' => 'unidad', 'type' => 'raw_material', 'base_unit_id' => $unit->id,
        ]);
        $finished = Product::create([
            'company_id' => $companyA->id, 'category_id' => $cat->id,
            'name' => 'Hamb', 'presentation' => 'unidad', 'type' => 'finished_product', 'base_unit_id' => $unit->id,
        ]);
        
        $recipe = \App\Models\Recipe::create([
            'company_id' => $companyA->id, 'product_id' => $finished->id, 'yield_quantity' => 24,
        ]);
        \App\Models\RecipeItem::create(['recipe_id' => $recipe->id, 'product_id' => $ingredient->id, 'quantity_base' => 24]);

        Stock::create(['company_id' => $companyA->id, 'product_id' => $ingredient->id, 'warehouse_id' => Warehouse::where('company_id', $companyA->id)->first()->id, 'quantity' => 100]);

        $executor = app(BotActionExecutor::class);
        $res = $executor->execute($userA, '111', [
            'name' => 'register_production', 
            'arguments' => ['product_name' => 'Hamb', 'quantity' => 24]
        ], []);
        
        $this->assertTrue($res['success']);
        $this->assertEquals(100 - 24, Stock::where('product_id', $ingredient->id)->sum('quantity'));
        $this->assertEquals(24, Stock::where('product_id', $finished->id)->sum('quantity'));
    }

    public function test_set_stock_bulk()
    {
        list($companyA, $companyB, $userA) = $this->setupData();
        
        $unit = Unit::create(['name' => 'Unidad', 'abbreviation' => 'u', 'type' => 'count']);
        $cat = ProductCategory::create(['company_id' => $companyA->id, 'name' => 'Cat']);
        $bacon = Product::create(['company_id' => $companyA->id, 'category_id' => $cat->id, 'name' => 'Bacon', 'presentation' => 'unidad', 'type' => 'raw_material', 'base_unit_id' => $unit->id]);
        $cheddar = Product::create(['company_id' => $companyA->id, 'category_id' => $cat->id, 'name' => 'Cheddar', 'presentation' => 'unidad', 'type' => 'raw_material', 'base_unit_id' => $unit->id]);
        $bolsita = Product::create(['company_id' => $companyA->id, 'category_id' => $cat->id, 'name' => 'Bolsita', 'presentation' => 'unidad', 'type' => 'raw_material', 'base_unit_id' => $unit->id]);
        $otro = Product::create(['company_id' => $companyA->id, 'category_id' => $cat->id, 'name' => 'Otro', 'presentation' => 'unidad', 'type' => 'raw_material', 'base_unit_id' => $unit->id]);

        Stock::create(['company_id' => $companyA->id, 'product_id' => $otro->id, 'warehouse_id' => Warehouse::where('company_id', $companyA->id)->first()->id, 'quantity' => 100]);

        $executor = app(BotActionExecutor::class);
        $res = $executor->execute($userA, '111', [
            'name' => 'set_stock', 
            'arguments' => [
                'items' => [
                    ['product_name' => 'Bacon', 'quantity' => 12],
                    ['product_name' => 'Cheddar', 'quantity' => 3],
                    ['product_name' => 'Bolsita', 'quantity' => 450],
                ]
            ]
        ], []);
        
        $this->assertTrue($res['success']);
        $this->assertEquals(12, Stock::where('product_id', $bacon->id)->sum('quantity'));
        $this->assertEquals(3, Stock::where('product_id', $cheddar->id)->sum('quantity'));
        $this->assertEquals(450, Stock::where('product_id', $bolsita->id)->sum('quantity'));
        // El resto debe quedar igual
        $this->assertEquals(100, Stock::where('product_id', $otro->id)->sum('quantity'));
    }

    public function test_company_isolation_on_set_stock()
    {
        list($companyA, $companyB, $userA) = $this->setupData();
        
        $unit = Unit::create(['name' => 'Unidad', 'abbreviation' => 'u', 'type' => 'count']);
        $catB = ProductCategory::create(['company_id' => $companyB->id, 'name' => 'Cat B']);
        $prodB = Product::create([
            'company_id' => $companyB->id, 'category_id' => $catB->id,
            'name' => 'Bacon', 'presentation' => 'unidad', 'type' => 'raw_material', 'base_unit_id' => $unit->id,
        ]);
        
        $executor = app(BotActionExecutor::class);
        
        // userA intenta modificar un producto de la empresa B llamándolo por nombre
        $res = $executor->execute($userA, '111', ['name' => 'set_stock', 'arguments' => ['product_name' => 'Bacon', 'quantity' => 12]], []);
        
        // No debería encontrar el producto porque busca en companyA
        $this->assertFalse($res['success']);
        $this->assertStringContainsString('No encontré ningún producto', $res['message']);
        
        // Intentar mandando company_id de la empresa B (Gemini injection)
        $res = $executor->execute($userA, '111', ['name' => 'set_stock', 'arguments' => ['product_name' => 'Bacon', 'quantity' => 12, 'company_id' => $companyB->id]], []);
        $this->assertFalse($res['success']);
    }

    public function test_register_production_actual_consumptions()
    {
        list($companyA, $companyB, $userA) = $this->setupData();
        
        $unit = Unit::create(['name' => 'Unidad', 'abbreviation' => 'u', 'type' => 'count']);
        $cat = ProductCategory::create(['company_id' => $companyA->id, 'name' => 'Cat']);
        $ingredient1 = Product::create([
            'company_id' => $companyA->id, 'category_id' => $cat->id,
            'name' => 'Pan', 'presentation' => 'unidad', 'type' => 'raw_material', 'base_unit_id' => $unit->id,
        ]);
        $ingredient2 = Product::create([
            'company_id' => $companyA->id, 'category_id' => $cat->id,
            'name' => 'Carne', 'presentation' => 'unidad', 'type' => 'raw_material', 'base_unit_id' => $unit->id,
        ]);
        $finished = Product::create([
            'company_id' => $companyA->id, 'category_id' => $cat->id,
            'name' => 'Hamb', 'presentation' => 'unidad', 'type' => 'finished_product', 'base_unit_id' => $unit->id,
        ]);
        
        $recipe = \App\Models\Recipe::create([
            'company_id' => $companyA->id, 'product_id' => $finished->id, 'yield_quantity' => 24,
        ]);
        \App\Models\RecipeItem::create(['recipe_id' => $recipe->id, 'product_id' => $ingredient1->id, 'quantity_base' => 24]);
        \App\Models\RecipeItem::create(['recipe_id' => $recipe->id, 'product_id' => $ingredient2->id, 'quantity_base' => 48]);

        Stock::create(['company_id' => $companyA->id, 'product_id' => $ingredient1->id, 'warehouse_id' => Warehouse::where('company_id', $companyA->id)->first()->id, 'quantity' => 100]);
        Stock::create(['company_id' => $companyA->id, 'product_id' => $ingredient2->id, 'warehouse_id' => Warehouse::where('company_id', $companyA->id)->first()->id, 'quantity' => 200]);

        $executor = app(BotActionExecutor::class);
        $res = $executor->execute($userA, '111', [
            'name' => 'register_production', 
            'arguments' => [
                'product_name' => 'Hamb', 
                'quantity' => 24,
                'actual_consumptions' => [
                    ['product_name' => 'Pan', 'quantity' => 25],
                    ['product_name' => 'Carne', 'quantity' => 50],
                ]
            ]
        ], []);
        
        $this->assertTrue($res['success']);
        // Descuenta el real (25 y 50), NO el teórico (24 y 48)
        $this->assertEquals(100 - 25, Stock::where('product_id', $ingredient1->id)->sum('quantity'));
        $this->assertEquals(200 - 50, Stock::where('product_id', $ingredient2->id)->sum('quantity'));
        $this->assertEquals(24, Stock::where('product_id', $finished->id)->sum('quantity'));
    }
}