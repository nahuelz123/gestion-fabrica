<?php

namespace Tests\Feature;

use App\Livewire\Inventory\StockManager;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Stock;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class LowStockDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_names_low_stock_products_and_review_opens_only_attention_items(): void
    {
        $this->withoutVite();

        $company = Company::create(['name' => 'Rapi Burguer']);
        $owner = User::create([
            'company_id' => $company->id,
            'name' => 'Dueño',
            'email' => 'owner-low@example.test',
            'password' => 'secret12345',
            'role' => 'owner',
            'status' => 'active',
        ]);
        $unit = Unit::create(['name' => 'Unidad', 'abbreviation' => 'u', 'type' => 'count']);
        $category = ProductCategory::create(['company_id' => $company->id, 'name' => 'Insumos']);
        $warehouse = Warehouse::create(['company_id' => $company->id, 'name' => 'Principal']);

        $low = Product::create([
            'company_id' => $company->id,
            'category_id' => $category->id,
            'type' => 'raw_material',
            'name' => 'Pan',
            'internal_code' => 'PAN-LOW',
            'presentation' => 'unidad',
            'base_unit_id' => $unit->id,
            'min_stock' => 10,
            'status' => 'active',
        ]);
        $zero = Product::create([
            'company_id' => $company->id,
            'category_id' => $category->id,
            'type' => 'raw_material',
            'name' => 'Bolsitas',
            'internal_code' => 'BOL-ZERO',
            'presentation' => 'unidad',
            'base_unit_id' => $unit->id,
            'min_stock' => 5,
            'status' => 'active',
        ]);
        $ok = Product::create([
            'company_id' => $company->id,
            'category_id' => $category->id,
            'type' => 'raw_material',
            'name' => 'Cheddar',
            'internal_code' => 'CHED-OK',
            'presentation' => 'unidad',
            'base_unit_id' => $unit->id,
            'min_stock' => 5,
            'status' => 'active',
        ]);

        Stock::create(['company_id'=>$company->id,'product_id'=>$low->id,'warehouse_id'=>$warehouse->id,'quantity'=>4]);
        Stock::create(['company_id'=>$company->id,'product_id'=>$ok->id,'warehouse_id'=>$warehouse->id,'quantity'=>20]);

        $this->actingAs($owner)
            ->get('/')
            ->assertOk()
            ->assertSee('Pan')
            ->assertSee('Bolsitas')
            ->assertSee('Revisar y corregir');

        Livewire::actingAs($owner)
            ->withQueryParams(['attention' => '1'])
            ->test(StockManager::class)
            ->assertSet('attentionOnly', true)
            ->assertSee('Pan')
            ->assertSee('Bolsitas')
            ->assertDontSee('Cheddar');
    }
    public function test_lot_controlled_low_stock_can_be_corrected_from_attention_screen(): void
    {
        $this->withoutVite();

        $company = Company::create(['name' => 'Rapi Burguer']);
        $owner = User::create([
            'company_id' => $company->id,
            'name' => 'Dueño',
            'email' => 'owner-lot@example.test',
            'password' => 'secret12345',
            'role' => 'owner',
            'status' => 'active',
        ]);
        $unit = Unit::create(['name' => 'Unidad', 'abbreviation' => 'u', 'type' => 'count']);
        $category = ProductCategory::create(['company_id' => $company->id, 'name' => 'Terminados']);
        Warehouse::create(['company_id' => $company->id, 'name' => 'Principal']);

        $hamburguesa = Product::create([
            'company_id' => $company->id,
            'category_id' => $category->id,
            'type' => 'finished_product',
            'name' => 'Hamburguesa',
            'internal_code' => 'HAM-LOT',
            'presentation' => 'unidad',
            'base_unit_id' => $unit->id,
            'requires_lot' => true,
            'requires_expiration' => true,
            'shelf_life_days' => 30,
            'min_stock' => 10,
            'status' => 'active',
        ]);

        Livewire::actingAs($owner)
            ->withQueryParams(['attention' => '1'])
            ->test(StockManager::class)
            ->call('selectProduct', $hamburguesa->id)
            ->assertSet('adjustMode', 'add')
            ->assertSee('Código de lote')
            ->set('lot_code', 'AJUSTE-TEST')
            ->set('adjustQty', '1250')
            ->call('applyAdjustment')
            ->assertSet('successMessage', 'Sumé 1250 u de Hamburguesa. Stock en Principal: 1250 u.');

        $this->assertDatabaseHas('stock_lots', [
            'company_id' => $company->id,
            'product_id' => $hamburguesa->id,
            'lot_code' => 'AJUSTE-TEST',
        ]);
        $this->assertSame(1250.0, (float) Stock::where('product_id', $hamburguesa->id)->sum('quantity'));
    }

}
