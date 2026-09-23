<?php

namespace Tests\Feature;

use App\Models\AiConversation;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Stock;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\BotAgentService;
use App\Services\GeminiService;
use App\Services\TelegramService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;
use Mockery;

class TelegramBotTest extends TestCase
{
    use \Illuminate\Foundation\Testing\RefreshDatabase;

    private function setupBaseData()
    {
        $company = Company::create(['name' => 'Bot Test Company']);
        $user = User::create(['company_id' => $company->id, 'name' => 'Tester', 'email' => 'bot@test.com', 'password' => 'pass', 'telegram_chat_id' => '123456']);
        $warehouse = Warehouse::create(['company_id' => $company->id, 'name' => 'Bot Warehouse']);
        $unit = Unit::create(['name' => 'Unit', 'abbreviation' => 'u', 'type' => 'count']);
        $category = ProductCategory::create(['company_id' => $company->id, 'name' => 'Test Cat']);

        return [$company, $user, $warehouse, $unit, $category];
    }

    public function test_scenario_1_e2e_flow()
    {
        list($company, $user, $warehouse, $unit, $category) = $this->setupBaseData();
        $pan = Product::create(['company_id' => $company->id, 'category_id' => $category->id, 'name' => 'Pan Hamburguesa', 'internal_code' => 'PH', 'base_unit_id' => $unit->id, 'requires_lot' => false]);

        $telegramMock = Mockery::mock(TelegramService::class);
        $telegramMock->shouldReceive('sendMessage')->byDefault();
        $this->app->instance(TelegramService::class, $telegramMock);

        $geminiMock = Mockery::mock(GeminiService::class);
        $geminiMock->shouldReceive('analyzeText')->andReturn([
            'intent' => 'propose_stock_entry',
            'product_name' => 'Pan Hamburguesa',
            'quantity' => 100
        ]);
        $this->app->instance(GeminiService::class, $geminiMock);

        $botAgent = app(BotAgentService::class);

        // Trick to reach the legacy process message flow since we want to test execution without breaking Step 6
        AiConversation::create([
            'user_id' => $user->id,
            'telegram_chat_id' => '123456',
            'pending_action' => ['type' => 'dummy']
        ]);

        $telegramMock->shouldReceive('sendMessage')->atLeast()->once()
            ->with('123456', Mockery::on(fn($msg) => str_contains($msg, '¿Confirmo el ingreso')));

        // Propose
        $botAgent->processMessage($user, '123456', 'llegaron 100 panes');
        
        $conv = AiConversation::where('user_id', $user->id)->first();
        $this->assertNotNull($conv->pending_action);
        $this->assertArrayHasKey('quantity_base', $conv->pending_action);

        $telegramMock->shouldReceive('sendMessage')->atLeast()->once()
            ->with('123456', Mockery::on(fn($msg) => str_contains($msg, 'Ingreso registrado')));

        // Confirm
        $botAgent->processMessage($user, '123456', 'si');

        $this->assertNull($conv->fresh()->pending_action);
        $this->assertEquals(100, Stock::where('product_id', $pan->id)->sum('quantity'));
    }

    public function test_scenario_2_idempotency()
    {
        list($company, $user, $warehouse, $unit, $category) = $this->setupBaseData();

        $telegramMock = Mockery::mock(TelegramService::class);
        $telegramMock->shouldReceive('sendMessage')->byDefault();
        $this->app->instance(TelegramService::class, $telegramMock);

        $payload = [
            'update_id' => 999999,
            'message' => ['chat' => ['id' => '123456'], 'text' => 'hola']
        ];
        
        $job = new \App\Jobs\ProcessTelegramMessageJob($payload);
        $job->handle(app(BotAgentService::class), app(TelegramService::class));
        $this->assertEquals(1, DB::table('telegram_processed_updates')->where('update_id', 999999)->count());
        
        $job2 = new \App\Jobs\ProcessTelegramMessageJob($payload);
        $job2->handle(app(BotAgentService::class), app(TelegramService::class));
        $this->assertEquals(1, DB::table('telegram_processed_updates')->where('update_id', 999999)->count());
    }

    public function test_scenario_3_concurrency()
    {
        list($company, $user, $warehouse, $unit, $category) = $this->setupBaseData();
        $pan = Product::create(['company_id' => $company->id, 'category_id' => $category->id, 'name' => 'Pan Hamburguesa', 'internal_code' => 'PH', 'base_unit_id' => $unit->id, 'requires_lot' => false]);
        
        // Give 100 initial stock
        app(\App\Services\StockService::class)->registerMovement([
            'company_id' => $company->id,
            'product_id' => $pan->id,
            'warehouse_id' => $warehouse->id,
            'type' => \App\Enums\MovementType::AdjustmentIn,
            'quantity_base' => 100,
            'user_id' => $user->id,
            'channel' => \App\Enums\Channel::Web,
        ]);

        AiConversation::updateOrCreate(
            ['user_id' => $user->id],
            ['telegram_chat_id' => '123456', 'pending_action' => [
                'company_id' => $company->id,
                'product_id' => $pan->id,
                'warehouse_id' => $warehouse->id,
                'type' => 'adjustment_in',
                'quantity_base' => 50,
                'user_id' => $user->id,
                'channel' => 'telegram'
            ]]
        );

        $script = <<<PHP
        try {
            app(\App\Services\BotAgentService::class)->processMessage(\App\Models\User::find({$user->id}), '123456', 'si');
        } catch (\Exception \$e) {
            echo "EXCEPTION: " . \$e->getMessage();
        }
        PHP;

        $tinkerCmd = "php artisan tinker --execute=\"" . str_replace('"', '\"', $script) . "\"";
        
        // Add TEST_BOT_SLEEP=true to force transaction lock collision
        $process1 = Process::env(['APP_ENV' => 'testing', 'DB_DATABASE' => 'gestion_fabrica_testing', 'TEST_BOT_SLEEP' => 'true'])->start($tinkerCmd);
        $process2 = Process::env(['APP_ENV' => 'testing', 'DB_DATABASE' => 'gestion_fabrica_testing', 'TEST_BOT_SLEEP' => 'true'])->start($tinkerCmd);
        
        $process1->wait();
        $process2->wait();

        // 100 original + 50 from ONE process (the other should bounce due to null pending_action)
        $this->assertEquals(150, Stock::where('product_id', $pan->id)->sum('quantity'));
    }

    public function test_scenario_4_ambiguous_product()
    {
        list($company, $user, $warehouse, $unit, $category) = $this->setupBaseData();
        Product::create(['company_id' => $company->id, 'category_id' => $category->id, 'name' => 'Queso Cheddar', 'internal_code' => 'QC', 'base_unit_id' => $unit->id, 'requires_lot' => false]);
        Product::create(['company_id' => $company->id, 'category_id' => $category->id, 'name' => 'Queso Mozzarella', 'internal_code' => 'QM', 'base_unit_id' => $unit->id, 'requires_lot' => false]);

        $telegramMock = Mockery::mock(TelegramService::class);
        $telegramMock->shouldReceive('sendMessage')->byDefault();
        $telegramMock->shouldReceive('sendMessage')->atLeast()->once()
            ->with('123456', Mockery::on(fn($msg) => str_contains($msg, 'Encontré varios productos que coinciden')));
        $this->app->instance(TelegramService::class, $telegramMock);

        $geminiMock = Mockery::mock(GeminiService::class);
        $geminiMock->shouldReceive('analyzeText')->andReturn([
            'intent' => 'check_stock',
            'product_name' => 'Queso'
        ]);
        $this->app->instance(GeminiService::class, $geminiMock);

        // TRICK FOR LEGACY FLOW
        AiConversation::create([
            'user_id' => $user->id,
            'telegram_chat_id' => '123456',
            'pending_action' => ['type' => 'dummy']
        ]);

        $botAgent = app(BotAgentService::class);
        $botAgent->processMessage($user, '123456', 'cuanto queso hay');
    }
}