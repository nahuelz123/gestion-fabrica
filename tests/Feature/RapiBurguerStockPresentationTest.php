<?php

namespace Tests\Feature;

use App\Enums\Channel;
use App\Enums\MovementType;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Recipe;
use App\Models\Stock;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\BotActionPreviewService;
use App\Services\ProductService;
use App\Services\ProductionOrderService;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RapiBurguerStockPresentationTest extends TestCase
{
    use RefreshDatabase;

    public function test_real_factory_presentations_convert_boxes_and_bars_to_base_units(): void
    {
        [$company, $user, $warehouse, $category, $unit] = $this->baseData();

        $pan = $this->product($company->id, $category->id, $unit->id, 'Pan');
        $medallon = $this->product($company->id, $category->id, $unit->id, 'Medallón de carne');
        $cheddar = $this->product($company->id, $category->id, $unit->id, 'Chedar');
        $queso = $this->product($company->id, $category->id, $unit->id, 'Queso');
        $jamon = $this->product($company->id, $category->id, $unit->id, 'Jamón');

        $migration = require database_path('migrations/2026_10_02_083000_apply_rapi_burguer_stock_presentations.php');
        $migration->up();

        $this->assertSame(36.0, (float) $pan->fresh()->presentations()->where('is_purchase_default', true)->value('conversion_factor'));
        $this->assertSame(60.0, (float) $medallon->fresh()->presentations()->where('is_purchase_default', true)->value('conversion_factor'));
        $this->assertSame(200.0, (float) $cheddar->fresh()->presentations()->where('is_purchase_default', true)->value('conversion_factor'));
        $this->assertSame(200.0, (float) $queso->fresh()->presentations()->where('is_purchase_default', true)->value('conversion_factor'));
        $this->assertSame(240.0, (float) $jamon->fresh()->presentations()->where('is_purchase_default', true)->value('conversion_factor'));

        $preview = app(BotActionPreviewService::class)->preview($user, [
            'name' => 'add_stock',
            'arguments' => [
                'items' => [
                    ['product_name' => 'Medallón de carne', 'quantity' => 60, 'presentation_name' => 'cajas'],
                    ['product_name' => 'Pan', 'quantity' => 48, 'presentation_name' => 'cajas'],
                    ['product_name' => 'Chedar', 'quantity' => 10, 'presentation_name' => 'barras'],
                    ['product_name' => 'Jamón', 'quantity' => 4, 'presentation_name' => 'barras'],
                    ['product_name' => 'Queso', 'quantity' => 5, 'presentation_name' => 'barras'],
                ],
            ],
        ]);

        $this->assertStringContainsString('60 caja de 60 = 3600 u', $preview);
        $this->assertStringContainsString('48 caja de 36 = 1728 u', $preview);
        $this->assertStringContainsString('10 barra de 200 = 2000 u', $preview);
        $this->assertStringContainsString('4 barra de 240 = 960 u', $preview);
        $this->assertStringContainsString('5 barra de 200 = 1000 u', $preview);
    }

    public function test_partial_bar_remainder_stays_available_for_next_production(): void
    {
        [$company, $user, $warehouse, $category, $unit] = $this->baseData();
        $queso = $this->product($company->id, $category->id, $unit->id, 'Queso');

        $migration = require database_path('migrations/2026_10_02_083000_apply_rapi_burguer_stock_presentations.php');
        $migration->up();

        $presentation = $queso->fresh()->presentations()->where('is_purchase_default', true)->firstOrFail();
        $productService = app(ProductService::class);
        $stockService = app(StockService::class);

        $threeBars = $productService->resolveToBaseUnit($queso, $presentation, 3);
        $stockService->registerMovement([
            'company_id' => $company->id,
            'product_id' => $queso->id,
            'warehouse_id' => $warehouse->id,
            'type' => MovementType::AdjustmentIn,
            'quantity_base' => $threeBars['quantity_base'],
            'presentation_id' => $presentation->id,
            'presentation_quantity' => 3,
            'user_id' => $user->id,
            'channel' => Channel::Web,
        ]);

        // Se consumen 2,5 barras equivalentes = 500 porciones.
        $stockService->registerMovement([
            'company_id' => $company->id,
            'product_id' => $queso->id,
            'warehouse_id' => $warehouse->id,
            'type' => MovementType::ProductionConsumption,
            'quantity_base' => 500,
            'user_id' => $user->id,
            'channel' => Channel::Web,
        ]);

        $this->assertSame(100.0, (float) Stock::where('product_id', $queso->id)->value('quantity'));

        // Al día siguiente entran/abrimos sólo 2 barras nuevas: 400 + las 100 que sobraron = 500.
        $twoBars = $productService->resolveToBaseUnit($queso, $presentation, 2);
        $stockService->registerMovement([
            'company_id' => $company->id,
            'product_id' => $queso->id,
            'warehouse_id' => $warehouse->id,
            'type' => MovementType::AdjustmentIn,
            'quantity_base' => $twoBars['quantity_base'],
            'presentation_id' => $presentation->id,
            'presentation_quantity' => 2,
            'user_id' => $user->id,
            'channel' => Channel::Web,
        ]);

        $this->assertSame(500.0, (float) Stock::where('product_id', $queso->id)->value('quantity'));
    }

    public function test_fractional_bacon_consumption_keeps_half_piece_for_next_day(): void
    {
        [$company, $user, $warehouse, $category, $unit] = $this->baseData();

        $bacon = $this->product($company->id, $category->id, $unit->id, 'Bacon');
        $finished = Product::create([
            'company_id' => $company->id,
            'category_id' => $category->id,
            'type' => 'finished_product',
            'name' => 'Hamburguesa bacon',
            'presentation' => 'unidad',
            'base_unit_id' => $unit->id,
            'cost' => 0,
            'price' => 0,
            'status' => 'active',
        ]);

        $migration = require database_path('migrations/2026_10_02_090000_finalize_rapi_burguer_material_presentations.php');
        $migration->up();

        $recipe = Recipe::create([
            'company_id' => $company->id,
            'product_id' => $finished->id,
            'yield_quantity' => 288,
        ]);
        $recipe->items()->create([
            'product_id' => $bacon->id,
            'quantity_base' => 3.5,
        ]);

        Stock::create([
            'company_id' => $company->id,
            'product_id' => $bacon->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => 8,
        ]);

        $result = app(ProductionOrderService::class)->registerProduction(
            $user,
            $finished,
            864,
            [$bacon->id => 7.5],
            Channel::Telegram,
        );

        $this->assertNotNull($result['order']);
        $this->assertSame(0.5, (float) Stock::where('product_id', $bacon->id)->sum('quantity'));
        $this->assertDatabaseHas('production_order_items', [
            'production_order_id' => $result['order']->id,
            'product_id' => $bacon->id,
            'consumed_quantity' => 7.5,
        ]);

        $preview = app(BotActionPreviewService::class)->preview($user, [
            'name' => 'register_production',
            'arguments' => [
                'product_name' => 'Hamburguesa bacon',
                'carros' => 3,
                'actual_consumptions' => [
                    ['product_name' => 'Bacon', 'quantity' => 7.5, 'presentation_name' => 'pieza'],
                ],
            ],
        ]);

        $this->assertStringContainsString('Bacon: 7.5 pieza', $preview);
        $this->assertStringContainsString('Los demás insumos se descontarán según la receta.', $preview);
    }

    private function baseData(): array
    {
        $company = Company::create(['name' => 'Rapi Burguer']);
        $unit = Unit::create(['name' => 'Unidad', 'abbreviation' => 'u', 'type' => 'count']);
        $category = ProductCategory::create(['company_id' => $company->id, 'name' => 'Materias Primas']);
        $warehouse = Warehouse::create(['company_id' => $company->id, 'name' => 'Depósito Principal']);
        $user = User::create([
            'company_id' => $company->id,
            'name' => 'Encargado',
            'email' => 'encargado-stock@rapiburguer.test',
            'password' => 'secret123',
            'role' => 'manager',
            'status' => 'active',
        ]);

        return [$company, $user, $warehouse, $category, $unit];
    }

    private function product(int $companyId, int $categoryId, int $unitId, string $name): Product
    {
        return Product::create([
            'company_id' => $companyId,
            'category_id' => $categoryId,
            'type' => 'raw_material',
            'name' => $name,
            'presentation' => 'unidad',
            'base_unit_id' => $unitId,
            'cost' => 0,
            'price' => 0,
            'status' => 'active',
        ]);
    }
}
