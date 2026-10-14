<?php

namespace Tests\Feature;

use App\Enums\Channel;
use App\Enums\MovementType;
use App\Models\AiConversation;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Stock;
use App\Models\StockLot;
use App\Models\StockMovement;
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
use Tests\TestCase;

class BotConversationalAssistantTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $company = Company::create(['name' => 'Rapi Burguer']);
        $warehouse = Warehouse::create(['company_id' => $company->id, 'name' => 'Principal']);
        $unit = Unit::create(['name' => 'Unidad', 'abbreviation' => 'u', 'type' => 'count']);
        $category = ProductCategory::create(['company_id' => $company->id, 'name' => 'Insumos']);
        $product = Product::create([
            'company_id' => $company->id, 'category_id' => $category->id,
            'name' => 'Papel manteca', 'internal_code' => 'PAPEL-1',
            'type' => 'raw_material', 'base_unit_id' => $unit->id, 'status' => 'active',
            'min_stock' => 10,
        ]);
        $owner = User::create([
            'company_id' => $company->id, 'name' => 'Dueño', 'email' => 'owner-chat@test.com',
            'password' => 'pass', 'role' => 'owner', 'status' => 'active', 'telegram_chat_id' => '4444',
        ]);
        $manager = User::create([
            'company_id' => $company->id, 'name' => 'Encargado', 'email' => 'manager-chat@test.com',
            'password' => 'pass', 'role' => 'manager', 'status' => 'active', 'telegram_chat_id' => '5555',
        ]);
        return compact('company', 'warehouse', 'unit', 'category', 'product', 'owner', 'manager');
    }

    private function attachMocks(GeminiService $gemini, BotActionPreviewService $preview, BotActionExecutor $executor, TelegramService $telegram): void
    {
        $this->app->instance(GeminiService::class, $gemini);
        $this->app->instance(BotActionPreviewService::class, $preview);
        $this->app->instance(BotActionExecutor::class, $executor);
        $this->app->instance(TelegramService::class, $telegram);
    }

    public function test_correction_of_pending_quantity_requests_confirmation_again_without_writing(): void
    {
        $d = $this->fixture();
        $gemini = Mockery::mock(GeminiService::class);
        $gemini->shouldReceive('analyzeConversation')->once()->andReturn([
            'intent' => 'add_stock', 'reply' => '', 'entities' => [],
            'action' => ['name' => 'add_stock', 'arguments' => ['product_name' => 'Papel manteca', 'quantity' => 10]],
            'missing' => [], 'confidence' => 0.99,
        ]);
        $preview = Mockery::mock(BotActionPreviewService::class);
        $preview->shouldReceive('preview')->twice()->andReturnUsing(
            fn ($user, $action) => 'Confirmar ' . $action['arguments']['quantity']
        );
        $executor = Mockery::mock(BotActionExecutor::class);
        $executor->shouldNotReceive('execute');
        $telegram = Mockery::mock(TelegramService::class);
        $telegram->shouldReceive('sendMessage')->twice();
        $this->attachMocks($gemini, $preview, $executor, $telegram);

        $agent = app(BotAgentService::class);
        $agent->processMessage($d['owner'], '4444', 'sumá 10 papel manteca');
        $agent->processMessage($d['owner'], '4444', 'no, eran 25');

        $conversation = AiConversation::where('user_id', $d['owner']->id)->firstOrFail();
        $this->assertSame('ready_for_confirmation', $conversation->context['status']);
        $this->assertEquals(25, $conversation->pending_action['arguments']['quantity']);
    }

    public function test_followup_stock_question_reuses_confirmed_product_reference(): void
    {
        $d = $this->fixture();
        $gemini = Mockery::mock(GeminiService::class);
        $gemini->shouldReceive('analyzeConversation')->twice()->andReturn(
            [
                'intent' => 'add_stock', 'reply' => '', 'entities' => [],
                'action' => ['name' => 'add_stock', 'arguments' => ['product_name' => 'Papel manteca', 'quantity' => 10]],
                'missing' => [], 'confidence' => 0.99,
            ],
            [
                'intent' => 'get_stock', 'reply' => '', 'entities' => [],
                'action' => ['name' => 'get_stock', 'arguments' => ['product_name' => null]],
                'missing' => [], 'confidence' => 0.99,
            ],
        );
        $preview = Mockery::mock(BotActionPreviewService::class);
        $preview->shouldReceive('preview')->once()->andReturn('¿Confirmás?');
        $captured = [];
        $executor = Mockery::mock(BotActionExecutor::class);
        $executor->shouldReceive('execute')->twice()->andReturnUsing(
            function ($user, $chat, $action) use (&$captured) {
                $captured[] = $action;
                return ['success' => true, 'message' => 'Consulta realizada'];
            }
        );
        $telegram = Mockery::mock(TelegramService::class);
        $telegram->shouldReceive('sendMessage')->times(3);
        $this->attachMocks($gemini, $preview, $executor, $telegram);

        $agent = app(BotAgentService::class);
        $agent->processMessage($d['owner'], '4444', 'sumá 10 papel manteca');
        $agent->processMessage($d['owner'], '4444', 'sí');
        $agent->processMessage($d['owner'], '4444', '¿y cuánto queda?');

        $this->assertSame('get_stock', $captured[1]['name']);
        $this->assertSame('Papel manteca', $captured[1]['arguments']['product_name']);
    }

    public function test_inventory_and_expiration_queries_use_company_records(): void
    {
        $d = $this->fixture();
        Stock::create(['company_id' => $d['company']->id, 'product_id' => $d['product']->id,
            'warehouse_id' => $d['warehouse']->id, 'quantity' => 3]);
        $executor = app(BotActionExecutor::class);
        $stock = $executor->execute($d['manager'], '5555', ['name' => 'get_stock', 'arguments' => ['product_name' => 'all']], []);
        $this->assertTrue($stock['success']);
        $this->assertStringContainsString('Papel manteca', $stock['message']);

        $low = $executor->execute($d['manager'], '5555', ['name' => 'get_low_stock', 'arguments' => []], []);
        $this->assertTrue($low['success']);
        $this->assertStringContainsString('Papel manteca', $low['message']);

        $lot = StockLot::create([
            'company_id' => $d['company']->id, 'product_id' => $d['product']->id,
            'lot_code' => 'VENCE-01', 'entry_date' => now()->toDateString(),
            'expiration_date' => now()->addDay()->toDateString(), 'initial_quantity' => 2,
        ]);
        Stock::create(['company_id' => $d['company']->id, 'product_id' => $d['product']->id,
            'warehouse_id' => $d['warehouse']->id, 'lot_id' => $lot->id, 'quantity' => 2]);

        $expiry = $executor->execute($d['manager'], '5555', ['name' => 'get_expiring_products', 'arguments' => ['days' => 2]], []);
        $this->assertTrue($expiry['success']);
        $this->assertStringContainsString('VENCE-01', $expiry['message']);

        StockMovement::create([
            'company_id' => $d['company']->id, 'product_id' => $d['product']->id,
            'warehouse_id' => $d['warehouse']->id, 'quantity_base' => 3,
            'type' => MovementType::AdjustmentIn, 'channel' => Channel::Web,
            'user_id' => $d['owner']->id, 'reason' => 'Ingreso de prueba',
        ]);
        $movements = $executor->execute($d['manager'], '5555', ['name' => 'get_stock_movements', 'arguments' => ['limit' => 5]], []);
        $this->assertTrue($movements['success']);
        $this->assertStringContainsString('Papel manteca', $movements['message']);
    }

    public function test_business_information_is_only_accessible_to_owner(): void
    {
        $d = $this->fixture();
        $executor = app(BotActionExecutor::class);
        $denied = $executor->execute($d['manager'], '5555', ['name' => 'get_business_summary', 'arguments' => ['days' => 7]], []);
        $this->assertFalse($denied['success']);
        $this->assertStringContainsString('permiso', $denied['message']);

        $owner = $executor->execute($d['owner'], '4444', ['name' => 'get_business_summary', 'arguments' => ['days' => 7]], []);
        $this->assertTrue($owner['success']);
        $this->assertStringContainsString('Resumen de la fábrica', $owner['message']);
    }
}
