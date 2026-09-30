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

    private function data(): array
    {
        $a=Company::create(['name'=>'Empresa A']); $b=Company::create(['name'=>'Empresa B']);
        $owner=User::create(['company_id'=>$a->id,'name'=>'Dueño','email'=>'owner@test.com','password'=>'pass','role'=>'owner','status'=>'active','telegram_chat_id'=>'111']);
        $manager=User::create(['company_id'=>$a->id,'name'=>'Encargado','email'=>'manager@test.com','password'=>'pass','role'=>'manager','status'=>'active','telegram_chat_id'=>'112']);
        User::create(['company_id'=>$b->id,'name'=>'Otro','email'=>'other@test.com','password'=>'pass','role'=>'owner','status'=>'active','telegram_chat_id'=>'222']);
        $wa=Warehouse::create(['company_id'=>$a->id,'name'=>'Principal']); Warehouse::create(['company_id'=>$b->id,'name'=>'Otro']);
        $unit=Unit::create(['name'=>'Unidad','abbreviation'=>'u','type'=>'count']);
        $ca=ProductCategory::create(['company_id'=>$a->id,'name'=>'Insumos']); $cb=ProductCategory::create(['company_id'=>$b->id,'name'=>'Insumos']);
        $pa=Product::create(['company_id'=>$a->id,'category_id'=>$ca->id,'name'=>'Papel','internal_code'=>'PA','type'=>'raw_material','base_unit_id'=>$unit->id,'status'=>'active']);
        $pb=Product::create(['company_id'=>$b->id,'category_id'=>$cb->id,'name'=>'Exclusivo B','internal_code'=>'PB','type'=>'raw_material','base_unit_id'=>$unit->id,'status'=>'active']);
        return compact('a','b','owner','manager','wa','unit','ca','pa','pb');
    }

    public function test_owner_and_manager_can_manage_stock_but_company_isolation_is_enforced(): void
    {
        $d=$this->data(); $executor=app(BotActionExecutor::class);
        $this->assertTrue($executor->execute($d['owner'],'111',['name'=>'add_stock','arguments'=>['product_name'=>'Papel','quantity'=>5]],[])['success']);
        $this->assertTrue($executor->execute($d['manager'],'112',['name'=>'add_stock','arguments'=>['product_name'=>'Papel','quantity'=>3]],[])['success']);
        $this->assertSame(8.0,(float)Stock::where('company_id',$d['a']->id)->where('product_id',$d['pa']->id)->sum('quantity'));
        $result=$executor->execute($d['owner'],'111',['name'=>'add_stock','arguments'=>['product_name'=>'Exclusivo B','quantity'=>10,'company_id'=>$d['b']->id]],[]);
        $this->assertFalse($result['success']);
        $this->assertSame(0.0,(float)Stock::where('product_id',$d['pb']->id)->sum('quantity'));
    }

    public function test_manager_cannot_use_non_stock_actions(): void
    {
        $d=$this->data(); $executor=app(BotActionExecutor::class);
        $result=$executor->execute($d['manager'],'112',['name'=>'create_product','arguments'=>['name'=>'Harina','presentation_name'=>'bolsa']],[]);
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('sólo permite', $result['message']);
        $this->assertDatabaseMissing('products',['company_id'=>$d['a']->id,'name'=>'Harina']);
    }

    public function test_owner_can_create_and_update_product_with_current_schema(): void
    {
        $d=$this->data(); $executor=app(BotActionExecutor::class);
        $result=$executor->execute($d['owner'],'111',['name'=>'create_product','arguments'=>['name'=>'Hamburguesa cheddar','type'=>'finished_product','presentation_name'=>'caja','barcode'=>'7790001','min_stock'=>12]],[]);
        $this->assertTrue($result['success']);
        $product=Product::where('company_id',$d['a']->id)->where('name','Hamburguesa cheddar')->firstOrFail();
        $this->assertSame('finished_product',$product->type->value);
        $this->assertSame('caja',$product->presentations()->first()->name);
        $result=$executor->execute($d['owner'],'111',['name'=>'update_product','arguments'=>['product_name'=>'Hamburguesa cheddar','new_name'=>'Hamburguesa cheddar premium','min_stock'=>20]],[]);
        $this->assertTrue($result['success']);
        $this->assertDatabaseHas('products',['id'=>$product->id,'name'=>'Hamburguesa cheddar premium','min_stock'=>20]);
    }

    public function test_bulk_stock_is_all_or_nothing_when_a_product_cannot_be_resolved(): void
    {
        $d=$this->data(); $executor=app(BotActionExecutor::class);
        $result=$executor->execute($d['owner'],'111',['name'=>'add_stock','arguments'=>['items'=>[['product_name'=>'Papel','quantity'=>10],['product_name'=>'No existe','quantity'=>4]]]],[]);
        $this->assertFalse($result['success']);
        $this->assertSame(0.0,(float)Stock::where('product_id',$d['pa']->id)->sum('quantity'));
    }
}
