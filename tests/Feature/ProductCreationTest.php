<?php

namespace Tests\Feature;

use App\Enums\ProductType;
use App\Livewire\Products\Form;
use App\Models\Company;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ProductCreationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Disable Telegram test sleep
        putenv('TEST_BOT_SLEEP=false');
        
        $company = Company::create(['name' => 'Test Factory']);
        $user = User::factory()->create(['company_id' => $company->id, 'role' => 'owner']);
        Warehouse::create(['company_id' => $company->id, 'name' => 'Main Deposito']);
        $this->actingAs($user);
    }


    public function test_can_create_raw_material()
    {
        Livewire::test(Form::class)
            ->set('type', 'raw_material')
            ->set('name', 'Papel manteca')
            ->set('presentation', 'paquete de 1000')
            ->call('save');

        $this->assertDatabaseHas('products', [
            'name' => 'Papel manteca',
            'type' => 'raw_material',
            'presentation' => 'paquete de 1000',
        ]);
        
        $product = Product::first();
        $this->assertStringStartsWith('PRD-', $product->internal_code);
        $this->assertDatabaseHas('product_presentations', [
            'product_id' => $product->id,
            'name' => 'paquete de 1000',
            'conversion_factor' => 1.0000,
        ]);
    }

    public function test_can_create_finished_product_with_barcode_and_lot()
    {
        Livewire::test(Form::class)
            ->set('type', 'finished_product')
            ->set('name', 'Galletitas')
            ->set('presentation', 'Caja x 24')
            ->set('barcode', '123456789')
            ->set('requires_lot', true)
            ->call('save');

        $this->assertDatabaseHas('products', [
            'name' => 'Galletitas',
            'type' => 'finished_product',
            'presentation' => 'Caja x 24',
            'barcode' => '123456789',
            'requires_lot' => 1,
        ]);
    }

    public function test_internal_code_is_generated_automatically_and_uniquely()
    {
        Livewire::test(Form::class)
            ->set('type', 'raw_material')
            ->set('name', 'Prod 1')
            ->set('presentation', 'u')
            ->call('save');

        Livewire::test(Form::class)
            ->set('type', 'raw_material')
            ->set('name', 'Prod 2')
            ->set('presentation', 'u')
            ->call('save');

        $products = Product::orderBy('id')->get();
        $this->assertCount(2, $products);
        $this->assertEquals('PRD-0001', $products[0]->internal_code);
        $this->assertEquals('PRD-0002', $products[1]->internal_code);
    }

    public function test_two_products_can_have_the_same_presentation()
    {
        Livewire::test(Form::class)
            ->set('type', 'raw_material')
            ->set('name', 'Papel manteca')
            ->set('presentation', 'paquete de 1000')
            ->call('save');

        Livewire::test(Form::class)
            ->set('type', 'raw_material')
            ->set('name', 'Bolsitas')
            ->set('presentation', 'paquete de 1000')
            ->call('save');

        $this->assertDatabaseHas('products', ['name' => 'Papel manteca', 'presentation' => 'paquete de 1000']);
        $this->assertDatabaseHas('products', ['name' => 'Bolsitas', 'presentation' => 'paquete de 1000']);
    }

    public function test_raw_material_does_not_save_barcode_or_lot_even_if_hacked()
    {
        // Even if we force these values, the component should clear them for raw_material
        Livewire::test(Form::class)
            ->set('type', 'raw_material')
            ->set('name', 'Harina')
            ->set('presentation', 'bolsa 50kg')
            ->set('barcode', '99999')
            ->set('requires_lot', true)
            ->call('save');

        $this->assertDatabaseHas('products', [
            'name' => 'Harina',
            'barcode' => null,
            'requires_lot' => 0,
        ]);
    }

    public function test_initial_stock_creates_stock_movement()
    {
        Livewire::test(Form::class)
            ->set('type', 'raw_material')
            ->set('name', 'Levadura')
            ->set('presentation', 'paquete de 500g')
            ->set('initial_stock', 15)
            ->call('save');

        $product = Product::first();

        // 15 units of stock movement should be created
        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $product->id,
            'type' => 'adjustment_in',
            'quantity_base' => 15.00,
        ]);

        $this->assertDatabaseHas('stock', [
            'product_id' => $product->id,
            'quantity' => 15.00,
        ]);
    }
}