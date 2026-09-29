<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_manager_can_only_reach_inventory_area(): void
    {
        $this->actingAs($this->manager);
        foreach (['/inventario','/inventario/entrada','/inventario/salida','/inventario/ajuste','/inventario/movimientos'] as $uri) $this->get($uri)->assertOk();
        foreach (['/productos','/proveedores','/compras','/produccion/calculadora','/maquinas'] as $uri) $this->get($uri)->assertForbidden();
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

    public function test_manager_bot_permissions_are_stock_only(): void
    {
        $this->assertTrue($this->manager->canUseBotAction('get_stock'));
        $this->assertTrue($this->manager->canUseBotAction('add_stock'));
        $this->assertTrue($this->manager->canUseBotAction('remove_stock'));
        $this->assertFalse($this->manager->canUseBotAction('create_product'));
        $this->assertFalse($this->manager->canUseBotAction('create_recipe'));
        $this->assertFalse($this->manager->canUseBotAction('plan_production'));
        $this->assertTrue($this->owner->canUseBotAction('plan_production'));
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
