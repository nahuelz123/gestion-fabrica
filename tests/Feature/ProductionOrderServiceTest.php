<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Recipe;
use App\Models\Stock;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\ProductionOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class ProductionOrderServiceTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $company=Company::create(['name'=>'Fábrica']);
        $owner=User::create(['company_id'=>$company->id,'name'=>'Dueño','email'=>'o@test.com','password'=>'pass','role'=>'owner','status'=>'active']);
        $manager=User::create(['company_id'=>$company->id,'name'=>'Encargado','email'=>'m@test.com','password'=>'pass','role'=>'manager','status'=>'active']);
        $warehouse=Warehouse::create(['company_id'=>$company->id,'name'=>'Principal']);
        $unit=Unit::create(['name'=>'Unidad','abbreviation'=>'u','type'=>'count']); $cat=ProductCategory::create(['company_id'=>$company->id,'name'=>'General']);
        $ingredient=Product::create(['company_id'=>$company->id,'category_id'=>$cat->id,'name'=>'Pan','internal_code'=>'PAN','type'=>'raw_material','base_unit_id'=>$unit->id,'status'=>'active']);
        $finished=Product::create(['company_id'=>$company->id,'category_id'=>$cat->id,'name'=>'Hamburguesa','internal_code'=>'HAM','type'=>'finished_product','base_unit_id'=>$unit->id,'status'=>'active']);
        $recipe=Recipe::create(['company_id'=>$company->id,'product_id'=>$finished->id,'yield_quantity'=>24]);
        $recipe->items()->create(['product_id'=>$ingredient->id,'quantity_base'=>24]);
        Stock::create(['company_id'=>$company->id,'product_id'=>$ingredient->id,'warehouse_id'=>$warehouse->id,'quantity'=>100]);
        return compact('company','owner','manager','warehouse','ingredient','finished','cat','unit');
    }

    public function test_owner_registers_production_atomically(): void
    {
        $d=$this->fixture(); $result=app(ProductionOrderService::class)->registerProduction($d['owner'],$d['finished'],24);
        $this->assertNotNull($result['order']);
        $this->assertSame(76.0,(float)Stock::where('product_id',$d['ingredient']->id)->sum('quantity'));
        $this->assertSame(24.0,(float)Stock::where('product_id',$d['finished']->id)->sum('quantity'));
        $this->assertDatabaseHas('production_orders',['id'=>$result['order']->id,'company_id'=>$d['company']->id,'status'=>'completed']);
        $this->assertSame(2,\App\Models\StockMovement::where('reference_type',\App\Models\ProductionOrder::class)->where('reference_id',$result['order']->id)->count());
    }

    public function test_manager_can_register_production_with_actual_consumption(): void
    {
        $d=$this->fixture();
        $result=app(ProductionOrderService::class)->registerProduction(
            $d['manager'],
            $d['finished'],
            24,
            [$d['ingredient']->id => 20]
        );

        $this->assertNotNull($result['order']);
        $this->assertSame($d['manager']->id, (int) $result['order']->user_id);
        $this->assertSame(80.0,(float)Stock::where('product_id',$d['ingredient']->id)->sum('quantity'));
        $this->assertSame(24.0,(float)Stock::where('product_id',$d['finished']->id)->sum('quantity'));
        $this->assertDatabaseHas('production_order_items', [
            'production_order_id'=>$result['order']->id,
            'product_id'=>$d['ingredient']->id,
            'required_quantity'=>24,
            'consumed_quantity'=>20,
        ]);
    }

    public function test_actual_consumptions_cannot_include_products_outside_recipe(): void
    {
        $d=$this->fixture();
        $otherIngredient=Product::create([
            'company_id'=>$d['company']->id,
            'category_id'=>$d['cat']->id,
            'name'=>'Bolsa extra',
            'internal_code'=>'BOL',
            'type'=>'raw_material',
            'base_unit_id'=>$d['unit']->id,
            'status'=>'active',
        ]);

        $this->expectException(InvalidArgumentException::class);
        app(ProductionOrderService::class)->registerProduction(
            $d['manager'],
            $d['finished'],
            24,
            [$otherIngredient->id => 2]
        );
    }

    public function test_product_from_another_company_is_rejected(): void
    {
        $d=$this->fixture(); $other=Company::create(['name'=>'Otra']); $cat=ProductCategory::create(['company_id'=>$other->id,'name'=>'Otro']);
        $foreign=Product::create(['company_id'=>$other->id,'category_id'=>$cat->id,'name'=>'Ajena','internal_code'=>'OTR','type'=>'finished_product','base_unit_id'=>$d['finished']->base_unit_id,'status'=>'active']);
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        app(ProductionOrderService::class)->registerProduction($d['owner'],$foreign,1);
    }
}
