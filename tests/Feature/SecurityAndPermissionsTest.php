<?php

namespace Tests\Feature;

use App\Livewire\Production\Calculator;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductionOrder;
use App\Models\Recipe;
use App\Models\RecipeItem;
use App\Models\Stock;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SecurityAndPermissionsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $owner;
    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->company = Company::create(['name' => 'Fábrica Seguridad']);
        Warehouse::create(['company_id' => $this->company->id, 'name' => 'Principal']);
        $this->owner = User::create(['company_id'=>$this->company->id,'name'=>'Dueño','email'=>'owner@example.test','password'=>'secret123','role'=>'owner','status'=>'active']);
        $this->manager = User::create(['company_id'=>$this->company->id,'name'=>'Encargado','email'=>'manager@example.test','password'=>'secret123','role'=>'manager','status'=>'active']);
    }

    public function test_manager_can_reach_inventory_and_production_but_not_administration(): void
    {
        $this->actingAs($this->manager);

        foreach (['/inventario','/inventario/entrada','/inventario/salida','/inventario/ajuste','/inventario/movimientos','/produccion/calculadora'] as $uri) {
            $this->get($uri)->assertOk();
        }

        foreach (['/productos','/proveedores','/compras','/maquinas'] as $uri) {
            $this->get($uri)->assertForbidden();
        }
    }

    public function test_owner_can_reach_administration_routes(): void
    {
        $this->actingAs($this->owner);
        $this->get('/productos')->assertOk();
        $this->get('/proveedores')->assertOk();
        $this->get('/compras')->assertOk();
        $this->get('/produccion/calculadora')->assertOk();
        $this->get('/maquinas')->assertOk();
    }

    public function test_manager_bot_permissions_include_stock_and_operational_production(): void
    {
        $this->assertTrue($this->manager->canUseBotAction('get_stock'));
        $this->assertTrue($this->manager->canUseBotAction('add_stock'));
        $this->assertTrue($this->manager->canUseBotAction('remove_stock'));
        $this->assertTrue($this->manager->canUseBotAction('calculate_production'));
        $this->assertTrue($this->manager->canUseBotAction('check_production'));
        $this->assertTrue($this->manager->canUseBotAction('get_max_production'));
        $this->assertTrue($this->manager->canUseBotAction('get_missing_inputs'));
        $this->assertTrue($this->manager->canUseBotAction('plan_production'));
        $this->assertTrue($this->manager->canUseBotAction('register_production'));

        $this->assertFalse($this->manager->canUseBotAction('create_product'));
        $this->assertFalse($this->manager->canUseBotAction('create_recipe'));
        $this->assertTrue($this->owner->canUseBotAction('register_production'));
    }

    public function test_manager_can_confirm_one_cart_and_actual_consumption(): void
    {
        $unit = Unit::create(['name'=>'Unidad','abbreviation'=>'u','type'=>'count']);
        $category = ProductCategory::create(['company_id'=>$this->company->id,'name'=>'Producción']);
        $ingredient = Product::create([
            'company_id'=>$this->company->id,
            'category_id'=>$category->id,
            'type'=>'raw_material',
            'name'=>'Pan',
            'presentation'=>'unidad',
            'base_unit_id'=>$unit->id,
            'status'=>'active',
        ]);
        $finished = Product::create([
            'company_id'=>$this->company->id,
            'category_id'=>$category->id,
            'type'=>'finished_product',
            'name'=>'Hamburguesa prueba',
            'presentation'=>'unidad',
            'base_unit_id'=>$unit->id,
            'status'=>'active',
        ]);
        $recipe = Recipe::create([
            'company_id'=>$this->company->id,
            'product_id'=>$finished->id,
            'yield_quantity'=>24,
        ]);
        RecipeItem::create(['recipe_id'=>$recipe->id,'product_id'=>$ingredient->id,'quantity_base'=>24]);
        $warehouseId = Warehouse::where('company_id',$this->company->id)->value('id');
        Stock::create([
            'company_id'=>$this->company->id,
            'product_id'=>$ingredient->id,
            'warehouse_id'=>$warehouseId,
            'quantity'=>400,
        ]);

        Livewire::actingAs($this->manager)
            ->test(Calculator::class)
            ->set('product_id', (string) $finished->id)
            ->set('carros', '1')
            ->set('bandejas', '0')
            ->call('calculate')
            ->assertSet('target_quantity', '288')
            ->assertSet('result.can_produce', true)
            ->assertSee('Confirmar producción realizada')
            ->set('actual_consumptions.'.$ingredient->id, '280')
            ->call('confirm')
            ->assertSet('successMessage', '✅ Producción registrada. Se descontaron los consumos informados y se sumó el producto terminado.');

        $this->assertSame(120.0, (float) Stock::where('product_id',$ingredient->id)->sum('quantity'));
        $this->assertSame(288.0, (float) Stock::where('product_id',$finished->id)->sum('quantity'));
        $this->assertDatabaseHas('production_orders', [
            'company_id'=>$this->company->id,
            'product_id'=>$finished->id,
            'target_quantity'=>288,
            'user_id'=>$this->manager->id,
            'status'=>'completed',
        ]);
        $this->assertSame(1, ProductionOrder::count());
    }

    public function test_inactive_authenticated_user_is_logged_out(): void
    {
        $this->manager->update(['status' => 'inactive']);
        $this->actingAs($this->manager);
        $this->get('/')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_public_legal_pages_and_security_headers_are_available(): void
    {
        $response = $this->get('/privacidad');
        $response->assertOk()->assertSee('Política de privacidad');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $this->get('/cookies')->assertOk()->assertSee('Política de cookies');
    }
}
