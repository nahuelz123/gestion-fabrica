<?php

namespace Tests\Feature;

use App\Enums\Channel;
use App\Enums\MovementType;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class InventorySecurityTest extends TestCase
{
    use RefreshDatabase;
    private Unit $unit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->unit = Unit::create(['name'=>'Unidad','abbreviation'=>'u','type'=>'count']);
    }

    private function companyData(string $suffix): array
    {
        $company = Company::create(['name'=>'Empresa '.$suffix]);
        $category = ProductCategory::create(['company_id'=>$company->id,'name'=>'Insumos']);
        $product = Product::create(['company_id'=>$company->id,'category_id'=>$category->id,'type'=>'raw_material','name'=>'Producto '.$suffix,'internal_code'=>'TEST-'.$suffix,'base_unit_id'=>$this->unit->id,'cost'=>0,'price'=>0,'status'=>'active']);
        $warehouse = Warehouse::create(['company_id'=>$company->id,'name'=>'Depósito '.$suffix]);
        $user = User::create(['company_id'=>$company->id,'name'=>'Usuario '.$suffix,'email'=>strtolower($suffix).'@example.test','password'=>'secret123','role'=>'manager','status'=>'active']);
        return compact('company','category','product','warehouse','user');
    }

    public function test_stock_service_rejects_cross_company_product(): void
    {
        $a = $this->companyData('A'); $b = $this->companyData('B');
        $this->expectException(InvalidArgumentException::class);
        app(StockService::class)->registerMovement(['company_id'=>$a['company']->id,'product_id'=>$b['product']->id,'warehouse_id'=>$a['warehouse']->id,'type'=>MovementType::AdjustmentIn,'quantity_base'=>10,'user_id'=>$a['user']->id,'channel'=>Channel::Web]);
    }

    public function test_stock_service_rejects_cross_company_warehouse_without_side_effects(): void
    {
        $a = $this->companyData('A'); $b = $this->companyData('B');
        try {
            app(StockService::class)->registerMovement(['company_id'=>$a['company']->id,'product_id'=>$a['product']->id,'warehouse_id'=>$b['warehouse']->id,'type'=>MovementType::AdjustmentIn,'quantity_base'=>10,'user_id'=>$a['user']->id,'channel'=>Channel::Web]);
            $this->fail('Se esperaba rechazo por depósito de otra empresa.');
        } catch (InvalidArgumentException) {
            $this->assertDatabaseCount('stock', 0);
            $this->assertDatabaseCount('stock_movements', 0);
        }
    }

    public function test_valid_movement_creates_ledger_and_stock(): void
    {
        $a = $this->companyData('A');
        $movement = app(StockService::class)->registerMovement(['company_id'=>$a['company']->id,'product_id'=>$a['product']->id,'warehouse_id'=>$a['warehouse']->id,'type'=>MovementType::AdjustmentIn,'quantity_base'=>12,'user_id'=>$a['user']->id,'channel'=>Channel::Web,'reason'=>'Prueba segura']);
        $this->assertSame(12.0, (float)$movement->quantity_base);
        $this->assertDatabaseHas('stock', ['company_id'=>$a['company']->id,'product_id'=>$a['product']->id,'warehouse_id'=>$a['warehouse']->id,'quantity'=>12]);
        $this->assertDatabaseHas('stock_movements', ['company_id'=>$a['company']->id,'product_id'=>$a['product']->id,'user_id'=>$a['user']->id]);
    }
}
