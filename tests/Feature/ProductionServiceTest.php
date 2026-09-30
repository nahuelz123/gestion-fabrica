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
use App\Services\ProductionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class ProductionServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_execute_production_uses_selected_company_warehouse_and_owner(): void
    {
        $company=Company::create(['name'=>'Fábrica']);
        $owner=User::create(['company_id'=>$company->id,'name'=>'Dueño','email'=>'owner@test.com','password'=>'pass','role'=>'owner','status'=>'active']);
        $warehouse=Warehouse::create(['company_id'=>$company->id,'name'=>'Principal']);
        $otherWarehouse=Warehouse::create(['company_id'=>$company->id,'name'=>'Secundario']);
        $unit=Unit::create(['name'=>'Unidad','abbreviation'=>'u','type'=>'count']); $cat=ProductCategory::create(['company_id'=>$company->id,'name'=>'General']);
        $ingredient=Product::create(['company_id'=>$company->id,'category_id'=>$cat->id,'name'=>'Pan','internal_code'=>'P1','type'=>'raw_material','base_unit_id'=>$unit->id,'status'=>'active']);
        $finished=Product::create(['company_id'=>$company->id,'category_id'=>$cat->id,'name'=>'Hamb','internal_code'=>'H1','type'=>'finished_product','base_unit_id'=>$unit->id,'status'=>'active']);
        $recipe=Recipe::create(['company_id'=>$company->id,'product_id'=>$finished->id,'yield_quantity'=>10]); $recipe->items()->create(['product_id'=>$ingredient->id,'quantity_base'=>10]);
        Stock::create(['company_id'=>$company->id,'product_id'=>$ingredient->id,'warehouse_id'=>$warehouse->id,'quantity'=>20]);
        Stock::create(['company_id'=>$company->id,'product_id'=>$ingredient->id,'warehouse_id'=>$otherWarehouse->id,'quantity'=>50]);

        $order=app(ProductionService::class)->executeProduction($finished->id,$warehouse->id,10,[],$owner->id);
        $this->assertSame(10.0,(float)Stock::where('product_id',$ingredient->id)->where('warehouse_id',$warehouse->id)->sum('quantity'));
        $this->assertSame(50.0,(float)Stock::where('product_id',$ingredient->id)->where('warehouse_id',$otherWarehouse->id)->sum('quantity'));
        $this->assertSame(10.0,(float)Stock::where('product_id',$finished->id)->where('warehouse_id',$warehouse->id)->sum('quantity'));
        $this->assertSame('completed',$order->status);
    }

    public function test_manager_is_rejected_by_legacy_production_service_too(): void
    {
        $company=Company::create(['name'=>'Fábrica']);
        $manager=User::create(['company_id'=>$company->id,'name'=>'Encargado','email'=>'m@test.com','password'=>'pass','role'=>'manager','status'=>'active']);
        $warehouse=Warehouse::create(['company_id'=>$company->id,'name'=>'Principal']);
        $unit=Unit::create(['name'=>'Unidad','abbreviation'=>'u','type'=>'count']); $cat=ProductCategory::create(['company_id'=>$company->id,'name'=>'General']);
        $product=Product::create(['company_id'=>$company->id,'category_id'=>$cat->id,'name'=>'Hamb','internal_code'=>'H1','type'=>'finished_product','base_unit_id'=>$unit->id,'status'=>'active']);
        $this->expectException(InvalidArgumentException::class);
        app(ProductionService::class)->executeProduction($product->id,$warehouse->id,1,[],$manager->id);
    }
}
