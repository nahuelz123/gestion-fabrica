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

    public function test_manager_can_reach_inventory_and_simulator_but_not_administration(): void
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

    public function test_manager_bot_permissions_include_read_only_production_simulation(): void
    {
        $this->assertTrue($this->manager->canUseBotAction('get_stock'));
        $this->assertTrue($this->manager->canUseBotAction('add_stock'));
        $this->assertTrue($this->manager->canUseBotAction('remove_stock'));
        $this->assertTrue($this->manager->canUseBotAction('calculate_production'));
        $this->assertTrue($this->manager->canUseBotAction('check_production'));
        $this->assertTrue($this->manager->canUseBotAction('get_max_production'));
        $this->assertTrue($this->manager->canUseBotAction('get_missing_inputs'));
        $this->assertTrue($this->manager->canUseBotAction('plan_production'));

        $this->assertFalse($this->manager->canUseBotAction('create_product'));
        $this->assertFalse($this->manager->canUseBotAction('create_recipe'));
        $this->assertFalse($this->manager->canUseBotAction('register_production'));
        $this->assertTrue($this->owner->canUseBotAction('register_production'));
    }

    public function test_manager_can_run_real_simulation_without_creating_production_order(): void
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
        Stock::create([
            'company_id'=>$this->company->id,
            'product_id'=>$ingredient->id,
            'warehouse_id'=>Warehouse::where('company_id',$this->company->id)->value('id'),
            'quantity'=>48,
        ]);

        Livewire::actingAs($this->manager)
            ->test(Calculator::class)
            ->set('product_id', (string) $finished->id)
            ->set('target_quantity', '24')
            ->call('calculate')
            ->assertSet('result.can_produce', true)
            ->assertSee('Modo simulación');

        $this->assertDatabaseCount('production_orders', 0);
        $this->assertSame(0, ProductionOrder::count());
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
