<?php

namespace Tests\Feature;

use App\Models\AiConversation;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductionOrder;
use App\Models\Recipe;
use App\Models\Stock;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\BotActionExecutor;
use App\Services\BotActionPreviewService;
use App\Services\BotAgentService;
use App\Services\GeminiService;
use App\Services\TelegramService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use ReflectionMethod;
use Tests\TestCase;

class TelegramProductionConversationRegressionTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $company = Company::create(['name' => 'Rapi Burguer']);
        $warehouse = Warehouse::create(['company_id' => $company->id, 'name' => 'Principal']);
        $user = User::create([
            'company_id' => $company->id, 'name' => 'Dueño', 'email' => 'correcciones@test.com',
            'password' => 'pass', 'role' => 'owner', 'status' => 'active',
            'telegram_chat_id' => '4321',
        ]);
        $unit = Unit::create(['name' => 'Unidad', 'abbreviation' => 'u', 'type' => 'count']);
        $category = ProductCategory::create(['company_id' => $company->id, 'name' => 'General']);

        $bread = Product::create([
            'company_id' => $company->id, 'category_id' => $category->id,
            'name' => 'Caja de pan', 'internal_code' => 'PAN-REG',
            'type' => 'raw_material', 'base_unit_id' => $unit->id, 'status' => 'active',
        ]);
        Stock::create([
            'company_id' => $company->id, 'warehouse_id' => $warehouse->id,
            'product_id' => $bread->id, 'quantity' => 432,
        ]);

        $finishedProducts = [];
        foreach (['bacon', 'cheddar'] as $flavour) {
            $product = Product::create([
                'company_id' => $company->id, 'category_id' => $category->id,
                'name' => 'Hamburguesa ' . $flavour,
                'internal_code' => strtoupper($flavour) . '-REG',
                'type' => 'finished_product', 'base_unit_id' => $unit->id,
                'status' => 'active',
            ]);
            $recipe = Recipe::create([
                'company_id' => $company->id, 'product_id' => $product->id,
                'yield_quantity' => 24,
            ]);
            $recipe->items()->create([
                'product_id' => $bread->id, 'quantity_base' => 24,
            ]);
            $finishedProducts[$flavour] = $product;
        }

        return compact('company', 'warehouse', 'user', 'bread', 'finishedProducts');
    }

    public function test_production_preview_shows_equivalence_not_double_counting(): void
    {
        $d = $this->fixture();
        $preview = app(BotActionPreviewService::class);

        $full = $preview->preview($d['user'], [
            'name' => 'register_production',
            'arguments' => ['product_name' => 'Hamburguesa bacon', 'carros' => 1, 'quantity' => 288],
        ]);
        $this->assertStringContainsString('1 carro = 288 unidades', $full);
        $this->assertStringNotContainsString('+ 288', $full);

        $half = $preview->preview($d['user'], [
            'name' => 'register_production',
            'arguments' => ['product_name' => 'Hamburguesa bacon', 'carros' => 0.5, 'quantity' => 144],
        ]);
        $this->assertStringContainsString('medio carro = 144 unidades', $half);
    }

    public function test_phrases_with_medio_carrito_are_parsed_as_half_a_car(): void
    {
        $d = $this->fixture();
        $agent = app(BotAgentService::class);
        $parser = new ReflectionMethod($agent, 'parseProductionPhrase');
        $parser->setAccessible(true);

        $parsed = $parser->invoke($agent, 'hicimos medio carrito de bacon');
        $this->assertSame(0.5, (float) $parsed['carros']);
        $this->assertSame(144.0, (float) $parsed['quantity']);
        $this->assertSame('bacon', $parsed['product_name']);
    }

    public function test_clarifying_completed_order_does_not_create_another_production(): void
    {
        $d = $this->fixture();

        foreach ([288, 144] as $quantity) {
            ProductionOrder::create([
                'company_id' => $d['company']->id,
                'product_id' => $d['finishedProducts']['bacon']->id,
                'warehouse_id' => $d['warehouse']->id,
                'target_quantity' => $quantity, 'status' => 'completed',
                'user_id' => $d['user']->id,
            ]);
        }

        $gemini = Mockery::mock(GeminiService::class);
        $gemini->shouldNotReceive('analyzeConversation');
        $telegram = Mockery::mock(TelegramService::class);
        $telegram->shouldReceive('sendMessage')->once()->with(
            '4321',
            Mockery::on(fn ($reply) =>
                str_contains($reply, 'Orden #1')
                && str_contains($reply, 'Orden #2')
                && str_contains($reply, 'No desconté ni devolví')
            )
        );
        $executor = Mockery::mock(BotActionExecutor::class);
        $executor->shouldNotReceive('execute');
        $this->app->instance(GeminiService::class, $gemini);
        $this->app->instance(TelegramService::class, $telegram);
        $this->app->instance(BotActionExecutor::class, $executor);

        app(BotAgentService::class)->processMessage(
            $d['user'], '4321',
            'Es que en vez de producir un carrito como te había confirmado, producimos solo medio carrito de bacon'
        );

        $this->assertDatabaseCount('production_orders', 2);
        $this->assertEquals(
            432,
            ProductionOrder::where('company_id', $d['company']->id)->sum('target_quantity')
        );
        $this->assertSame('idle', AiConversation::where('user_id', $d['user']->id)->firstOrFail()->context['status']);
    }

    public function test_multiple_flavour_question_routes_to_comparison_instead_of_last_flavour_only(): void
    {
        $d = $this->fixture();
        $gemini = Mockery::mock(GeminiService::class);
        $gemini->shouldReceive('analyzeConversation')->once()->andReturn([
            'intent' => 'get_max_production',
            'reply' => 'Calculé cheddar',
            'entities' => [],
            'action' => [
                'name' => 'get_max_production',
                'arguments' => ['product_name' => 'cheddar'],
            ],
            'missing' => [], 'confidence' => 0.95,
        ]);

        $telegram = Mockery::mock(TelegramService::class);
        $telegram->shouldReceive('sendMessage')->once()->with('4321', 'Comparación correcta');
        $executor = Mockery::mock(BotActionExecutor::class);
        $executor->shouldReceive('execute')->once()->with(
            $d['user'], '4321',
            Mockery::on(fn ($action) =>
                ($action['name'] ?? null) === 'compare_max_production'
                && ($action['arguments']['product_names'] ?? []) === ['bacon', 'cheddar']
            ),
            Mockery::type('array')
        )->andReturn(['success' => true, 'message' => 'Comparación correcta']);
        $this->app->instance(GeminiService::class, $gemini);
        $this->app->instance(TelegramService::class, $telegram);
        $this->app->instance(BotActionExecutor::class, $executor);

        app(BotAgentService::class)->processMessage(
            $d['user'], '4321',
            'Cuántos carritos de bacon y cuántos de cheddar puedo hacer?'
        );
    }

    public function test_comparison_has_both_varieties_and_warns_about_shared_ingredients(): void
    {
        $d = $this->fixture();
        $reply = app(BotActionExecutor::class)->execute(
            $d['user'], '4321',
            ['name' => 'compare_max_production', 'arguments' => [
                'product_names' => ['bacon', 'cheddar'],
            ]],
            []
        );
        $this->assertTrue($reply['success'], $reply['message']);
        $this->assertStringContainsString('Hamburguesa bacon', $reply['message']);
        $this->assertStringContainsString('Hamburguesa cheddar', $reply['message']);
        $this->assertStringContainsString('432 unidades', $reply['message']);
        $this->assertStringContainsString('no podés sumar', $reply['message']);
    }

    public function test_inconsistent_units_and_cars_are_rejected_before_confirmation(): void
    {
        $d = $this->fixture();
        $gemini = Mockery::mock(GeminiService::class);
        $gemini->shouldReceive('analyzeConversation')->once()->andReturn([
            'intent' => 'register_production',
            'reply' => 'Voy a registrar',
            'entities' => [], 'missing' => [], 'confidence' => 0.9,
            'action' => ['name' => 'register_production', 'arguments' => [
                'product_name' => 'Hamburguesa bacon', 'carros' => 1, 'quantity' => 144,
            ]],
        ]);
        $telegram = Mockery::mock(TelegramService::class);
        $telegram->shouldReceive('sendMessage')->once()->with(
            '4321',
            Mockery::on(fn ($reply) =>
                str_contains($reply, 'no coincide')
                && str_contains($reply, 'No registré ninguna producción')
            )
        );
        $executor = Mockery::mock(BotActionExecutor::class);
        $executor->shouldNotReceive('execute');
        $this->app->instance(GeminiService::class, $gemini);
        $this->app->instance(TelegramService::class, $telegram);
        $this->app->instance(BotActionExecutor::class, $executor);

        app(BotAgentService::class)->processMessage($d['user'], '4321', 'registrá esa producción ahora');
        $this->assertDatabaseCount('production_orders', 0);
    }
}
