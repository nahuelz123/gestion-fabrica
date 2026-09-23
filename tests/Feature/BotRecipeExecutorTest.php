<?php
namespace Tests\Feature;

use App\Models\Company;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Recipe;
use App\Models\RecipeItem;
use App\Models\Stock;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\BotActionExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BotRecipeExecutorTest extends TestCase
{
    use RefreshDatabase;

    private function setupData()
    {
        $companyA = Company::create(['name' => 'Empresa A']);
        $companyB = Company::create(['name' => 'Empresa B']);

        $userA = User::create(['company_id' => $companyA->id, 'name' => 'User A', 'email' => 'a@test.com', 'password' => 'pass', 'telegram_chat_id' => '111']);
        
        $warehouseA = Warehouse::create(['company_id' => $companyA->id, 'name' => 'Depo A']);

        $unitKG = Unit::create(['name' => 'Kilogramo', 'abbreviation' => 'kg', 'type' => 'weight']);
        $unitU = Unit::create(['name' => 'Unidad', 'abbreviation' => 'u', 'type' => 'count']);
        
        $catRaw = ProductCategory::create(['company_id' => $companyA->id, 'name' => 'Insumos']);
        $catFin = ProductCategory::create(['company_id' => $companyA->id, 'name' => 'Terminados']);

        $harina = Product::create(['company_id' => $companyA->id, 'category_id' => $catRaw->id, 'name' => 'Harina', 'internal_code' => 'HA', 'base_unit_id' => $unitKG->id, 'type' => 'raw_material']);
        $azucar = Product::create(['company_id' => $companyA->id, 'category_id' => $catRaw->id, 'name' => 'Azúcar', 'internal_code' => 'AZ', 'base_unit_id' => $unitKG->id, 'type' => 'raw_material']);
        
        $galletita = Product::create(['company_id' => $companyA->id, 'category_id' => $catFin->id, 'name' => 'Galletita', 'internal_code' => 'GA', 'base_unit_id' => $unitU->id, 'type' => 'finished_product']);
        $galletitaB = Product::create(['company_id' => $companyB->id, 'category_id' => ProductCategory::create(['company_id' => $companyB->id, 'name' => 'TerminadosB'])->id, 'name' => 'Galletita B', 'internal_code' => 'GB', 'base_unit_id' => $unitU->id, 'type' => 'finished_product']);

        $recipe = Recipe::create(['company_id' => $companyA->id, 'product_id' => $galletita->id, 'yield_quantity' => 100]);
        RecipeItem::create(['recipe_id' => $recipe->id, 'product_id' => $harina->id, 'quantity_base' => 5]); // 5kg harina for 100u
        RecipeItem::create(['recipe_id' => $recipe->id, 'product_id' => $azucar->id, 'quantity_base' => 2]); // 2kg azucar for 100u

        return [$companyA, $companyB, $userA, $galletita, $galletitaB, $harina, $azucar, $warehouseA];
    }

    // 1. receta existente
    public function test_get_recipe_existing()
    {
        list($companyA, $companyB, $userA) = $this->setupData();
        $executor = app(BotActionExecutor::class);
        $result = $executor->execute($userA, '111', ['name' => 'get_recipe', 'arguments' => ['product_name' => 'Galletita']], []);
        
        $this->assertTrue($result['success']);
        $this->assertStringContainsString('rinde 100', $result['message']);
        $this->assertStringContainsString('5', $result['message']);
    }

    // 2. receta inexistente
    public function test_get_recipe_no_recipe()
    {
        list($companyA, $companyB, $userA, $galletita, $galletitaB, $harina) = $this->setupData();
        $executor = app(BotActionExecutor::class);
        $result = $executor->execute($userA, '111', ['name' => 'get_recipe', 'arguments' => ['product_name' => 'Harina']], []);
        
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('no tiene una receta', $result['message']);
    }

    // 3, 18. receta de otra empresa
    public function test_get_recipe_other_company()
    {
        list($companyA, $companyB, $userA) = $this->setupData();
        $executor = app(BotActionExecutor::class);
        $result = $executor->execute($userA, '111', ['name' => 'get_recipe', 'arguments' => ['product_name' => 'Galletita B']], []);
        
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('No encontré ningún', $result['message']);
    }

    // 4. receta ambigua
    public function test_get_recipe_ambiguous()
    {
        list($companyA, $companyB, $userA, $galletita) = $this->setupData();
        Product::create(['company_id' => $companyA->id, 'category_id' => $galletita->category_id, 'name' => 'Galletita Limón', 'internal_code' => 'GL', 'base_unit_id' => $galletita->base_unit_id, 'type' => 'finished_product']);
        
        $executor = app(BotActionExecutor::class);
        $result = $executor->execute($userA, '111', ['name' => 'get_recipe', 'arguments' => ['product_name' => 'Galletita']], []);
        
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('varios productos que coinciden', $result['message']);
    }

    // 5. calculo correcto
    public function test_calculate_production_success()
    {
        list($companyA, $companyB, $userA) = $this->setupData();
        $executor = app(BotActionExecutor::class);
        $result = $executor->execute($userA, '111', ['name' => 'calculate_production', 'arguments' => ['product_name' => 'Galletita', 'quantity' => 500]], []);
        
        $this->assertTrue($result['success']);
        $this->assertStringContainsString('Harina: 25 u', $result['message']);
        $this->assertStringContainsString('Azúcar: 10 u', $result['message']);
    }

    // 9, 11. check_production stock suficiente (no modifica)
    public function test_check_production_sufficient_stock()
    {
        list($companyA, $companyB, $userA, $galletita, $galletitaB, $harina, $azucar, $warehouseA) = $this->setupData();
        Stock::create(['company_id' => $companyA->id, 'warehouse_id' => $warehouseA->id, 'product_id' => $harina->id, 'quantity' => 30]);
        Stock::create(['company_id' => $companyA->id, 'warehouse_id' => $warehouseA->id, 'product_id' => $azucar->id, 'quantity' => 20]);
        
        $executor = app(BotActionExecutor::class);
        $result = $executor->execute($userA, '111', ['name' => 'check_production', 'arguments' => ['product_name' => 'Galletita', 'quantity' => 500]], []);
        
        $this->assertTrue($result['success']);
        $this->assertStringContainsString('✅ Hay stock suficiente', $result['message']);
        
        // Assert no stock modified
        $this->assertEquals(30, Stock::where('product_id', $harina->id)->sum('quantity'));
    }

    // 10, 12. check_production stock insuficiente y faltantes
    public function test_check_production_insufficient_stock()
    {
        list($companyA, $companyB, $userA, $galletita, $galletitaB, $harina, $azucar, $warehouseA) = $this->setupData();
        Stock::create(['company_id' => $companyA->id, 'warehouse_id' => $warehouseA->id, 'product_id' => $harina->id, 'quantity' => 20]); // Needs 25, short 5
        Stock::create(['company_id' => $companyA->id, 'warehouse_id' => $warehouseA->id, 'product_id' => $azucar->id, 'quantity' => 10]); // Needs 10, exact
        
        $executor = app(BotActionExecutor::class);
        $result = $executor->execute($userA, '111', ['name' => 'check_production', 'arguments' => ['product_name' => 'Galletita', 'quantity' => 500]], []);
        
        $this->assertTrue($result['success']);
        $this->assertStringContainsString('❌ No alcanza el stock', $result['message']);
        $this->assertStringContainsString('Harina: 5', $result['message']);
        $this->assertStringNotContainsString('Azúcar', $result['message']); // Because missing is 0
    }

    // 13, 15. get_missing_inputs correctos y no modifica
    public function test_get_missing_inputs()
    {
        list($companyA, $companyB, $userA, $galletita, $galletitaB, $harina, $azucar) = $this->setupData();
        
        $executor = app(BotActionExecutor::class);
        $result = $executor->execute($userA, '111', ['name' => 'get_missing_inputs', 'arguments' => ['product_name' => 'Galletita', 'quantity' => 500]], []);
        
        $this->assertTrue($result['success']);
        $this->assertStringContainsString('te falta', $result['message']);
        $this->assertStringContainsString('Harina: 25', $result['message']);
        
        $this->assertEquals(0, Stock::where('product_id', $harina->id)->sum('quantity'));
    }

    // 14. get_missing_inputs devuelve vacio cuando alcanza
    public function test_get_missing_inputs_all_available()
    {
        list($companyA, $companyB, $userA, $galletita, $galletitaB, $harina, $azucar, $warehouseA) = $this->setupData();
        Stock::create(['company_id' => $companyA->id, 'warehouse_id' => $warehouseA->id, 'product_id' => $harina->id, 'quantity' => 100]);
        Stock::create(['company_id' => $companyA->id, 'warehouse_id' => $warehouseA->id, 'product_id' => $azucar->id, 'quantity' => 100]);
        
        $executor = app(BotActionExecutor::class);
        $result = $executor->execute($userA, '111', ['name' => 'get_missing_inputs', 'arguments' => ['product_name' => 'Galletita', 'quantity' => 500]], []);
        
        $this->assertTrue($result['success']);
        $this->assertStringContainsString('No te falta nada', $result['message']);
    }

    // 6. calculate_production receta inexistente
    public function test_calculate_production_no_recipe()
    {
        list($companyA, $companyB, $userA, $galletita, $galletitaB, $harina) = $this->setupData();
        $executor = app(BotActionExecutor::class);
        $result = $executor->execute($userA, '111', ['name' => 'calculate_production', 'arguments' => ['product_name' => 'Harina', 'quantity' => 100]], []);
        
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('no tiene una receta', $result['message']);
    }

    // 7. calculate_production producto inexistente
    public function test_calculate_production_invalid_product()
    {
        list($companyA, $companyB, $userA) = $this->setupData();
        $executor = app(BotActionExecutor::class);
        $result = $executor->execute($userA, '111', ['name' => 'calculate_production', 'arguments' => ['product_name' => 'Pizza', 'quantity' => 100]], []);
        
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('No encontré ningún', $result['message']);
    }

    // 8. calculate_production empresa incorrecta
    public function test_calculate_production_wrong_company()
    {
        list($companyA, $companyB, $userA) = $this->setupData();
        $executor = app(BotActionExecutor::class);
        $result = $executor->execute($userA, '111', ['name' => 'calculate_production', 'arguments' => ['product_name' => 'Galletita B', 'quantity' => 100]], []);
        
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('No encontré ningún', $result['message']);
    }
}