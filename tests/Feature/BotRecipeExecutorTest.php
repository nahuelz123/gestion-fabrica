<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\BotActionExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BotRecipeExecutorTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_create_update_and_read_recipe_while_manager_cannot(): void
    {
        $company=Company::create(['name'=>'Fábrica']); Warehouse::create(['company_id'=>$company->id,'name'=>'Principal']);
        $owner=User::create(['company_id'=>$company->id,'name'=>'Dueño','email'=>'o@test.com','password'=>'pass','role'=>'owner','status'=>'active']);
        $manager=User::create(['company_id'=>$company->id,'name'=>'Encargado','email'=>'m@test.com','password'=>'pass','role'=>'manager','status'=>'active']);
        $unit=Unit::create(['name'=>'Unidad','abbreviation'=>'u','type'=>'count']); $cat=ProductCategory::create(['company_id'=>$company->id,'name'=>'General']);
        $pan=Product::create(['company_id'=>$company->id,'category_id'=>$cat->id,'name'=>'Pan','internal_code'=>'PAN','type'=>'raw_material','base_unit_id'=>$unit->id,'status'=>'active']);
        $medallon=Product::create(['company_id'=>$company->id,'category_id'=>$cat->id,'name'=>'Medallón','internal_code'=>'MED','type'=>'raw_material','base_unit_id'=>$unit->id,'status'=>'active']);
        $hamb=Product::create(['company_id'=>$company->id,'category_id'=>$cat->id,'name'=>'Hamburguesa clásica','internal_code'=>'HAM','type'=>'finished_product','base_unit_id'=>$unit->id,'status'=>'active']);
        $executor=app(BotActionExecutor::class);

        $result=$executor->execute($owner,'1',['name'=>'create_recipe','arguments'=>['product_name'=>'Hamburguesa clásica','yield_quantity'=>24,'items'=>[['product_name'=>'Pan','quantity'=>24],['product_name'=>'Medallón','quantity'=>24]]]],[]);
        $this->assertTrue($result['success']);
        $recipe=$hamb->recipe()->with('items')->firstOrFail(); $this->assertCount(2,$recipe->items); $this->assertSame(24.0,(float)$recipe->yield_quantity);

        $result=$executor->execute($owner,'1',['name'=>'update_recipe','arguments'=>['product_name'=>'Hamburguesa clásica','yield_quantity'=>24,'items'=>[['product_name'=>'Pan','quantity'=>24],['product_name'=>'Medallón','quantity'=>30]]]],[]);
        $this->assertTrue($result['success']);
        $this->assertSame(30.0,(float)$hamb->recipe->items()->where('product_id',$medallon->id)->value('quantity_base'));

        $read=$executor->execute($owner,'1',['name'=>'get_recipe','arguments'=>['product_name'=>'Hamburguesa clásica']],[]);
        $this->assertTrue($read['success']); $this->assertStringContainsString('Pan',$read['message']);

        $denied=$executor->execute($manager,'2',['name'=>'create_recipe','arguments'=>['product_name'=>'Hamburguesa clásica','yield_quantity'=>1,'items'=>[['product_name'=>'Pan','quantity'=>1]]]],[]);
        $this->assertFalse($denied['success']);
    }
}
