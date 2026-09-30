<?php

namespace Tests\Feature;

use App\Enums\PurchaseStatus;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Purchase;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\PurchaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class PurchaseServiceTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $company=Company::create(['name'=>'Fábrica']);
        $owner=User::create(['company_id'=>$company->id,'name'=>'Dueño','email'=>'owner@test.com','password'=>'pass','role'=>'owner','status'=>'active']);
        $manager=User::create(['company_id'=>$company->id,'name'=>'Encargado','email'=>'manager@test.com','password'=>'pass','role'=>'manager','status'=>'active']);
        $warehouse=Warehouse::create(['company_id'=>$company->id,'name'=>'Principal']);
        $supplier=Supplier::create(['company_id'=>$company->id,'name'=>'Proveedor','status'=>'active']);
        $unit=Unit::create(['name'=>'Unidad','abbreviation'=>'u','type'=>'count']); $cat=ProductCategory::create(['company_id'=>$company->id,'name'=>'Insumos']);
        $product=Product::create(['company_id'=>$company->id,'category_id'=>$cat->id,'name'=>'Papel','internal_code'=>'PAP','type'=>'raw_material','base_unit_id'=>$unit->id,'status'=>'active']);
        $purchase=Purchase::create(['company_id'=>$company->id,'supplier_id'=>$supplier->id,'warehouse_id'=>$warehouse->id,'purchase_date'=>now()->toDateString(),'status'=>PurchaseStatus::Draft,'user_id'=>$owner->id]);
        $purchase->items()->create(['product_id'=>$product->id,'quantity'=>10,'unit_cost'=>100]);
        return compact('company','owner','manager','warehouse','supplier','product','purchase');
    }

    public function test_confirm_purchase_registers_stock_once(): void
    {
        $d=$this->fixture(); $service=app(PurchaseService::class); $service->confirm($d['purchase']->id,$d['owner']->id);
        $this->assertSame(PurchaseStatus::Confirmed,$d['purchase']->fresh()->status);
        $this->assertDatabaseHas('stock',['company_id'=>$d['company']->id,'product_id'=>$d['product']->id,'warehouse_id'=>$d['warehouse']->id,'quantity'=>10]);
        $this->assertSame(1,StockMovement::where('reference_type',Purchase::class)->where('reference_id',$d['purchase']->id)->count());
        try { $service->confirm($d['purchase']->id,$d['owner']->id); $this->fail('La segunda confirmación debía fallar.'); } catch (\Throwable) {}
        $this->assertSame(1,StockMovement::where('reference_type',Purchase::class)->where('reference_id',$d['purchase']->id)->count());
    }

    public function test_manager_cannot_confirm_purchase(): void
    {
        $d=$this->fixture(); $this->expectException(InvalidArgumentException::class);
        app(PurchaseService::class)->confirm($d['purchase']->id,$d['manager']->id);
    }

    public function test_owner_cannot_confirm_purchase_from_another_company(): void
    {
        $d=$this->fixture(); $other=Company::create(['name'=>'Otra']);
        $otherOwner=User::create(['company_id'=>$other->id,'name'=>'Otro','email'=>'other@test.com','password'=>'pass','role'=>'owner','status'=>'active']);
        try { app(PurchaseService::class)->confirm($d['purchase']->id,$otherOwner->id); $this->fail('Debía rechazar otra empresa.'); } catch (\Throwable) {}
        $this->assertSame(PurchaseStatus::Draft,$d['purchase']->fresh()->status);
        $this->assertDatabaseCount('stock_movements',0);
    }
}
