<?php

namespace Tests\Feature;

use App\Models\AiConversation;
use App\Models\Company;
use App\Models\User;
use App\Services\BotAgentService;
use App\Services\GeminiService;
use App\Services\TelegramService;
use Tests\TestCase;
use Mockery;

class BotAgentServiceTest extends TestCase
{
    use \Illuminate\Foundation\Testing\RefreshDatabase;

    private function setupBaseData()
    {
        $company = Company::create(['name' => 'Test Company']);
        $user = User::create(['company_id' => $company->id, 'name' => 'Tester', 'email' => 'bot@test.com', 'password' => 'pass', 'telegram_chat_id' => '123456']);
        return [$company, $user];
    }

    // Conversación nueva -> crea contexto create_product
    public function test_new_conversation()
    {
        list($company, $user) = $this->setupBaseData();
        
        $telegramMock = Mockery::mock(TelegramService::class);
        $telegramMock->shouldReceive('sendMessage')->once()->with('123456', '¿Cuál es la presentación?');
        $this->app->instance(TelegramService::class, $telegramMock);

        $geminiMock = Mockery::mock(GeminiService::class);
        $geminiMock->shouldReceive('analyzeConversation')->once()->andReturn([
            'intent' => 'create_product',
            'reply' => '¿Cuál es la presentación?',
            'entities' => ['name' => 'Papel manteca'],
            'missing' => ['presentation'],
            'requires_confirmation' => false,
            'action' => null,
            'confidence' => 1.0
        ]);
        $this->app->instance(GeminiService::class, $geminiMock);

        app(BotAgentService::class)->processMessage($user, '123456', 'Quiero agregar papel manteca');

        $conv = AiConversation::where('user_id', $user->id)->first();
        $this->assertEquals('create_product', $conv->context['intent']);
        $this->assertEquals('collecting', $conv->context['status']);
        $this->assertEquals('Papel manteca', $conv->context['entities']['name']);
    }

    // Continuación -> conserva name y agrega presentation
    public function test_continuation_preserves_entities()
    {
        list($company, $user) = $this->setupBaseData();
        
        AiConversation::create([
            'user_id' => $user->id,
            'telegram_chat_id' => '123456',
            'context' => [
                'intent' => 'create_product',
                'status' => 'collecting',
                'entities' => ['name' => 'Papel manteca'],
            ]
        ]);

        $telegramMock = Mockery::mock(TelegramService::class);
        $telegramMock->shouldReceive('sendMessage')->once()->with('123456', '¿Confirmás?');
        $this->app->instance(TelegramService::class, $telegramMock);

        $geminiMock = Mockery::mock(GeminiService::class);
        $geminiMock->shouldReceive('analyzeConversation')->once()->andReturn([
            'intent' => 'create_product',
            'reply' => '¿Confirmás?',
            'entities' => ['presentation' => 'paquete de 1000'],
            'missing' => [],
            'requires_confirmation' => true,
            'action' => null,
            'confidence' => 1.0
        ]);
        $this->app->instance(GeminiService::class, $geminiMock);

        app(BotAgentService::class)->processMessage($user, '123456', 'paquete de 1000');

        $conv = AiConversation::where('user_id', $user->id)->first();
        $this->assertEquals('ready_for_confirmation', $conv->context['status']);
        $this->assertEquals('Papel manteca', $conv->context['entities']['name']);
        $this->assertEquals('paquete de 1000', $conv->context['entities']['presentation']);
    }

    // Corrección -> reemplaza presentation sin perder name
    public function test_correction_replaces_entities()
    {
        list($company, $user) = $this->setupBaseData();
        
        AiConversation::create([
            'user_id' => $user->id,
            'telegram_chat_id' => '123456',
            'context' => [
                'intent' => 'create_product',
                'status' => 'ready_for_confirmation',
                'entities' => ['name' => 'Papel manteca', 'presentation' => 'paquete de 1000'],
            ]
        ]);

        $telegramMock = Mockery::mock(TelegramService::class);
        $telegramMock->shouldReceive('sendMessage')->once()->with('123456', '¿Confirmás paquete de 600?');
        $this->app->instance(TelegramService::class, $telegramMock);

        $geminiMock = Mockery::mock(GeminiService::class);
        $geminiMock->shouldReceive('analyzeConversation')->once()->andReturn([
            'intent' => 'create_product',
            'reply' => '¿Confirmás paquete de 600?',
            'entities' => ['presentation' => 'paquete de 600'],
            'missing' => [],
            'requires_confirmation' => true,
            'action' => null,
            'confidence' => 1.0
        ]);
        $this->app->instance(GeminiService::class, $geminiMock);

        app(BotAgentService::class)->processMessage($user, '123456', 'me equivoqué, paquete de 600');

        $conv = AiConversation::where('user_id', $user->id)->first();
        $this->assertEquals('ready_for_confirmation', $conv->context['status']);
        $this->assertEquals('Papel manteca', $conv->context['entities']['name']);
        $this->assertEquals('paquete de 600', $conv->context['entities']['presentation']);
    }

    // Confirmación -> ejecuta y cambia estado a completed
    public function test_confirmation_executes_and_completes()
    {
        list($company, $user) = $this->setupBaseData();
        
        AiConversation::create([
            'user_id' => $user->id,
            'telegram_chat_id' => '123456',
            'context' => [
                'intent' => 'create_product',
                'status' => 'ready_for_confirmation',
                'entities' => ['name' => 'Pap'],
                'pending_action' => ['name' => 'create_product', 'arguments' => ['name' => 'Pap', 'presentation' => 'Bolsa']],
            ]
        ]);

        $telegramMock = Mockery::mock(TelegramService::class);
        $telegramMock->shouldReceive('sendMessage')->once()->with('123456', Mockery::on(fn($msg) => str_contains($msg, 'Creé Pap')));
        $this->app->instance(TelegramService::class, $telegramMock);

        $geminiMock = Mockery::mock(GeminiService::class);
        $geminiMock->shouldReceive('analyzeConversation')->once()->andReturn([
            'intent' => 'create_product',
            'reply' => 'OK',
            'entities' => [],
            'missing' => [],
            'requires_confirmation' => false,
            'action' => ['name' => 'create_product', 'arguments' => ['name' => 'Pap', 'presentation' => 'Bolsa']],
            'confidence' => 1.0
        ]);
        $this->app->instance(GeminiService::class, $geminiMock);

        app(BotAgentService::class)->processMessage($user, '123456', 'sí, dale');

        $conv = AiConversation::where('user_id', $user->id)->first();
        $this->assertEquals('completed', $conv->context['status']);
        $this->assertNull($conv->context['pending_action']);
    }

    // 10. Idempotencia real: un segundo "sí" no duplica el stock
    public function test_double_confirmation_idempotency()
    {
        list($company, $user) = $this->setupBaseData();
        
        // Setup existing product and warehouse
        $unit = \App\Models\Unit::create(['name' => 'u', 'abbreviation' => 'u', 'type' => 'count']);
        $cat = \App\Models\ProductCategory::create(['company_id' => $company->id, 'name' => 'Cat']);
        $prod = \App\Models\Product::create(['company_id' => $company->id, 'category_id' => $cat->id, 'name' => 'Harina', 'internal_code' => 'HA', 'base_unit_id' => $unit->id, 'requires_lot' => false]);
        \App\Models\Warehouse::create(['company_id' => $company->id, 'name' => 'Depo']);
        
        $this->assertEquals(0, \App\Models\Stock::where('product_id', $prod->id)->sum('quantity'));

        AiConversation::create([
            'user_id' => $user->id,
            'telegram_chat_id' => '123456',
            'context' => [
                'intent' => 'register_stock',
                'status' => 'ready_for_confirmation',
                'entities' => [],
                'pending_action' => ['name' => 'register_stock', 'arguments' => ['product_name' => 'Harina', 'quantity' => 20]],
            ]
        ]);

        $telegramMock = Mockery::mock(TelegramService::class);
        $telegramMock->shouldReceive('sendMessage')->twice();
        $this->app->instance(TelegramService::class, $telegramMock);

        $geminiMock = Mockery::mock(GeminiService::class);
        $geminiMock->shouldReceive('analyzeConversation')->twice()->andReturn([
            'intent' => 'register_stock',
            'reply' => 'OK',
            'entities' => [],
            'missing' => [],
            'requires_confirmation' => false, // User said "Sí"
            'action' => ['name' => 'register_stock', 'arguments' => ['product_name' => 'Harina', 'quantity' => 20]],
            'confidence' => 1.0
        ]);
        $this->app->instance(GeminiService::class, $geminiMock);

        // Primer Sí -> Ejecuta y suma 20
        app(BotAgentService::class)->processMessage($user, '123456', 'sí');
        $this->assertEquals(20, \App\Models\Stock::where('product_id', $prod->id)->sum('quantity'));
        
        $conv = AiConversation::where('user_id', $user->id)->first();
        $this->assertEquals('completed', $conv->context['status']);
        $this->assertNull($conv->context['pending_action']);

        // Segundo Sí (Retry de Telegram u otro) -> NO suma nada porque ya no está ready_for_confirmation
        app(BotAgentService::class)->processMessage($user, '123456', 'sí');
        $this->assertEquals(20, \App\Models\Stock::where('product_id', $prod->id)->sum('quantity'));
    }

    // NEW 6. create_product antes de confirmar
    public function test_create_product_requires_confirmation_flow()
    {
        list($company, $user) = $this->setupBaseData();

        $telegramMock = Mockery::mock(TelegramService::class);
        $telegramMock->shouldReceive('sendMessage')->twice();
        $this->app->instance(TelegramService::class, $telegramMock);

        $geminiMock = Mockery::mock(GeminiService::class);
        // First turn: Gemini sends requires_confirmation = true
        $geminiMock->shouldReceive('analyzeConversation')->once()->andReturn([
            'intent' => 'create_product',
            'reply' => 'Voy a crear Harina. ¿Confirmas?',
            'entities' => [],
            'missing' => [],
            'requires_confirmation' => true,
            'action' => ['name' => 'create_product', 'arguments' => ['name' => 'Harina', 'presentation' => 'Bolsa 50kg']],
            'confidence' => 1.0
        ]);
        $this->app->instance(GeminiService::class, $geminiMock);

        app(BotAgentService::class)->processMessage($user, '123456', 'Quiero crear Harina, bolsa de 50kg, como insumo.');

        // Verify NOT executed yet
        $this->assertFalse(\App\Models\Product::where('name', 'Harina')->exists());
        
        $conv = AiConversation::where('user_id', $user->id)->first();
        $this->assertEquals('ready_for_confirmation', $conv->context['status']);
        $this->assertNotNull($conv->context['pending_action']);

        // Second turn: User says Sí
        $geminiMock = Mockery::mock(GeminiService::class);
        $geminiMock->shouldReceive('analyzeConversation')->once()->andReturn([
            'intent' => 'create_product',
            'reply' => 'OK',
            'entities' => [],
            'missing' => [],
            'requires_confirmation' => false,
            'action' => ['name' => 'create_product', 'arguments' => ['name' => 'Harina', 'presentation' => 'Bolsa 50kg']],
            'confidence' => 1.0
        ]);
        $this->app->instance(GeminiService::class, $geminiMock);

        app(BotAgentService::class)->processMessage($user, '123456', 'Sí');

        // Verify executed
        $this->assertTrue(\App\Models\Product::where('name', 'Harina')->exists());
        $conv->refresh();
        $this->assertEquals('completed', $conv->context['status']);
    }

    // NEW 7. register_stock antes de confirmar
    public function test_register_stock_requires_confirmation_flow()
    {
        list($company, $user) = $this->setupBaseData();
        $unit = \App\Models\Unit::create(['name' => 'u', 'abbreviation' => 'u', 'type' => 'count']);
        $cat = \App\Models\ProductCategory::create(['company_id' => $company->id, 'name' => 'Cat']);
        $prod = \App\Models\Product::create(['company_id' => $company->id, 'category_id' => $cat->id, 'name' => 'Harina', 'internal_code' => 'HA', 'base_unit_id' => $unit->id, 'requires_lot' => false]);
        \App\Models\Warehouse::create(['company_id' => $company->id, 'name' => 'Depo']);

        $telegramMock = Mockery::mock(TelegramService::class);
        $telegramMock->shouldReceive('sendMessage')->twice();
        $this->app->instance(TelegramService::class, $telegramMock);

        $geminiMock = Mockery::mock(GeminiService::class);
        $geminiMock->shouldReceive('analyzeConversation')->once()->andReturn([
            'intent' => 'register_stock',
            'reply' => 'Voy a agregar 20 paquetes. ¿Confirmas?',
            'entities' => [],
            'missing' => [],
            'requires_confirmation' => true,
            'action' => ['name' => 'register_stock', 'arguments' => ['product_name' => 'Harina', 'quantity' => 20]],
            'confidence' => 1.0
        ]);
        $this->app->instance(GeminiService::class, $geminiMock);

        app(BotAgentService::class)->processMessage($user, '123456', 'Agrega 20 paquetes de Harina.');

        // Verify NOT executed yet
        $this->assertEquals(0, \App\Models\Stock::where('product_id', $prod->id)->sum('quantity'));
        
        $conv = AiConversation::where('user_id', $user->id)->first();
        $this->assertEquals('ready_for_confirmation', $conv->context['status']);

        // Second turn
        $geminiMock = Mockery::mock(GeminiService::class);
        $geminiMock->shouldReceive('analyzeConversation')->once()->andReturn([
            'intent' => 'register_stock',
            'reply' => 'OK',
            'entities' => [],
            'missing' => [],
            'requires_confirmation' => false,
            'action' => ['name' => 'register_stock', 'arguments' => ['product_name' => 'Harina', 'quantity' => 20]],
            'confidence' => 1.0
        ]);
        $this->app->instance(GeminiService::class, $geminiMock);

        app(BotAgentService::class)->processMessage($user, '123456', 'Sí');

        // Verify executed
        $this->assertEquals(20, \App\Models\Stock::where('product_id', $prod->id)->sum('quantity'));
        $conv->refresh();
        $this->assertEquals('completed', $conv->context['status']);
        $this->assertNull($conv->context['pending_action']);
    }

    // Cancelación -> cambia estado a cancelled
    public function test_cancellation()
    {
        list($company, $user) = $this->setupBaseData();
        
        AiConversation::create([
            'user_id' => $user->id,
            'telegram_chat_id' => '123456',
            'context' => ['intent' => 'create_product', 'status' => 'collecting']
        ]);

        $telegramMock = Mockery::mock(TelegramService::class);
        $telegramMock->shouldReceive('sendMessage')->once()->with('123456', 'Operación cancelada.');
        $this->app->instance(TelegramService::class, $telegramMock);

        app(BotAgentService::class)->processMessage($user, '123456', 'cancelar');

        $conv = AiConversation::where('user_id', $user->id)->first();
        $this->assertEquals('cancelled', $conv->context['status']);
    }

    // Corrección que empieza con "no" NO debe convertirse automáticamente en cancelled
    public function test_correction_starting_with_no_does_not_cancel()
    {
        list($company, $user) = $this->setupBaseData();
        
        AiConversation::create([
            'user_id' => $user->id,
            'telegram_chat_id' => '123456',
            'context' => ['intent' => 'create_product', 'status' => 'ready_for_confirmation']
        ]);

        $telegramMock = Mockery::mock(TelegramService::class);
        $telegramMock->shouldReceive('sendMessage')->once()->with('123456', 'Corregido.');
        $this->app->instance(TelegramService::class, $telegramMock);

        $geminiMock = Mockery::mock(GeminiService::class);
        $geminiMock->shouldReceive('analyzeConversation')->once()->andReturn([
            'intent' => 'create_product',
            'reply' => 'Corregido.',
            'entities' => [],
            'missing' => [],
            'requires_confirmation' => true,
            'action' => null,
            'confidence' => 1.0
        ]);
        $this->app->instance(GeminiService::class, $geminiMock);

        // "no, eran 25 paquetes" is not strictly "no"
        app(BotAgentService::class)->processMessage($user, '123456', 'no, eran 25 paquetes');

        $conv = AiConversation::where('user_id', $user->id)->first();
        $this->assertNotEquals('cancelled', $conv->context['status']);
        $this->assertEquals('ready_for_confirmation', $conv->context['status']);
    }

    // Contexto limitado -> no más de 10 mensajes
    public function test_context_limits_recent_messages()
    {
        list($company, $user) = $this->setupBaseData();
        
        $messages = [];
        for ($i = 0; $i < 10; $i++) {
            $messages[] = ['role' => 'user', 'text' => "msg $i"];
        }

        AiConversation::create([
            'user_id' => $user->id,
            'telegram_chat_id' => '123456',
            'context' => ['recent_messages' => $messages]
        ]);

        $telegramMock = Mockery::mock(TelegramService::class);
        $telegramMock->shouldReceive('sendMessage')->byDefault();
        $this->app->instance(TelegramService::class, $telegramMock);

        $geminiMock = Mockery::mock(GeminiService::class);
        $geminiMock->shouldReceive('analyzeConversation')->andReturn([
            'intent' => 'unknown', 'reply' => 'test', 'entities' => [], 'missing' => [], 'requires_confirmation' => false, 'action' => null, 'confidence' => 1.0
        ]);
        $this->app->instance(GeminiService::class, $geminiMock);

        app(BotAgentService::class)->processMessage($user, '123456', 'new message');

        $conv = AiConversation::where('user_id', $user->id)->first();
        $this->assertCount(10, $conv->context['recent_messages']);
        $this->assertEquals('new message', $conv->context['recent_messages'][8]['text']); // Wait, 8 is user, 9 is assistant
    }

    // Conversación antigua -> compatible con pending_action
    public function test_legacy_conversation_fallback()
    {
        list($company, $user) = $this->setupBaseData();
        
        AiConversation::create([
            'user_id' => $user->id,
            'telegram_chat_id' => '123456',
            'pending_action' => ['type' => 'adjustment_in'] // Simulates old state
        ]);

        $telegramMock = Mockery::mock(TelegramService::class);
        $telegramMock->shouldReceive('sendMessage')->once()->with('123456', '❌ Operación cancelada.');
        $this->app->instance(TelegramService::class, $telegramMock);

        app(BotAgentService::class)->processMessage($user, '123456', 'no');

        $conv = AiConversation::where('user_id', $user->id)->first();
        $this->assertNull($conv->pending_action);
    }

    // Error Gemini -> el contexto anterior debe conservarse
    public function test_gemini_unknown_preserves_context()
    {
        list($company, $user) = $this->setupBaseData();
        
        AiConversation::create([
            'user_id' => $user->id,
            'telegram_chat_id' => '123456',
            'context' => ['intent' => 'create_product', 'status' => 'collecting', 'entities' => ['name' => 'Harina']]
        ]);

        $telegramMock = Mockery::mock(TelegramService::class);
        $telegramMock->shouldReceive('sendMessage')->once()->with('123456', 'Error');
        $this->app->instance(TelegramService::class, $telegramMock);

        $geminiMock = Mockery::mock(GeminiService::class);
        $geminiMock->shouldReceive('analyzeConversation')->once()->andReturn([
            'intent' => 'unknown', 'reply' => 'Error', 'entities' => [], 'missing' => [], 'requires_confirmation' => false, 'action' => null, 'confidence' => 1.0
        ]);
        $this->app->instance(GeminiService::class, $geminiMock);

        app(BotAgentService::class)->processMessage($user, '123456', 'adasdas');

        $conv = AiConversation::where('user_id', $user->id)->first();
        $this->assertEquals('Harina', $conv->context['entities']['name']);
        $this->assertEquals('collecting', $conv->context['status']);
    }

    // Multiempresa -> no se envía otro company_id ni se cambia
    public function test_multi_company_isolation()
    {
        list($company, $user) = $this->setupBaseData();
        $telegramMock = Mockery::mock(TelegramService::class);
        $telegramMock->shouldReceive('sendMessage')->byDefault();
        $this->app->instance(TelegramService::class, $telegramMock);

        $geminiMock = Mockery::mock(GeminiService::class);
        $geminiMock->shouldReceive('analyzeConversation')->once()->andReturn([
            'intent' => 'create_product', 'reply' => 'ok', 'entities' => ['company_id' => 999], 'missing' => [], 'requires_confirmation' => false, 'action' => null, 'confidence' => 1.0
        ]);
        $this->app->instance(GeminiService::class, $geminiMock);

        app(BotAgentService::class)->processMessage($user, '123456', 'agregalo a la empresa 999');

        $conv = AiConversation::where('user_id', $user->id)->first();
        $this->assertEquals($user->id, $conv->user_id); // Did not mutate database user binding
    }

    // 16. Contexto: "Quiero producir galletitas" -> "¿Cuánto necesito para 500?"
    public function test_context_for_production()
    {
        list($company, $user) = $this->setupBaseData();
        
        $telegramMock = Mockery::mock(TelegramService::class);
        $telegramMock->shouldReceive('sendMessage')->twice();
        $this->app->instance(TelegramService::class, $telegramMock);

        $geminiMock = Mockery::mock(GeminiService::class);
        // Turn 1
        $geminiMock->shouldReceive('analyzeConversation')->once()->andReturn([
            'intent' => 'calculate_production',
            'reply' => '¿Qué cantidad de Galletita querés producir?',
            'entities' => ['name' => 'Galletita'],
            'missing' => ['quantity'],
            'requires_confirmation' => false,
            'action' => null,
            'confidence' => 1.0
        ]);
        
        $this->app->instance(GeminiService::class, $geminiMock);
        app(BotAgentService::class)->processMessage($user, '123456', 'Quiero producir galletitas.');
        $conv = AiConversation::where('user_id', $user->id)->first();
        $this->assertEquals('Galletita', $conv->context['entities']['name']);
        
        // Turn 2
        $geminiMock = Mockery::mock(GeminiService::class);
        $geminiMock->shouldReceive('analyzeConversation')->once()->andReturn([
            'intent' => 'calculate_production',
            'reply' => 'OK',
            'entities' => ['name' => 'Galletita', 'quantity' => 500],
            'missing' => [],
            'requires_confirmation' => false,
            'action' => ['name' => 'calculate_production', 'arguments' => ['product_name' => 'Galletita', 'quantity' => 500]],
            'confidence' => 1.0
        ]);
        $this->app->instance(GeminiService::class, $geminiMock);

        $executorMock = Mockery::mock(\App\Services\BotActionExecutor::class);
        $executorMock->shouldReceive('execute')->once()->andReturn(['success' => true, 'message' => 'Cálculo exitoso']);
        $this->app->instance(\App\Services\BotActionExecutor::class, $executorMock);

        app(BotAgentService::class)->processMessage($user, '123456', 'Para 500 unidades.');
        $conv->refresh();
        $this->assertEquals('completed', $conv->context['status']);
    }

    // 17. Referencia ambigua solicita aclaración
    public function test_ambiguous_reference_context()
    {
        list($company, $user) = $this->setupBaseData();
        
        $telegramMock = Mockery::mock(TelegramService::class);
        $telegramMock->shouldReceive('sendMessage')->once();
        $this->app->instance(TelegramService::class, $telegramMock);

        $geminiMock = Mockery::mock(GeminiService::class);
        $geminiMock->shouldReceive('analyzeConversation')->once()->andReturn([
            'intent' => 'calculate_production',
            'reply' => 'No me queda claro qué producto querés calcular. ¿Será Harina o Galletitas?',
            'entities' => [],
            'missing' => ['product_name', 'quantity'],
            'requires_confirmation' => false,
            'action' => null,
            'confidence' => 1.0
        ]);
        
        $this->app->instance(GeminiService::class, $geminiMock);
        app(BotAgentService::class)->processMessage($user, '123456', '¿Cuánto necesito para 500?');
        
        $conv = AiConversation::where('user_id', $user->id)->first();
        $this->assertEquals('collecting', $conv->context['status']);
    }
    public function test_idempotency_after_completed_action()
    {
        list($company, $user) = $this->setupBaseData();
        
        $conv = AiConversation::create([
            'user_id' => $user->id,
            'telegram_chat_id' => '123456',
            'context' => [
                'status' => 'completed',
                'pending_action' => null,
                'entities' => ['product_name' => 'Galletita'],
                'recent_messages' => []
            ]
        ]);
        
        $telegramMock = Mockery::mock(TelegramService::class);
        $telegramMock->shouldReceive('sendMessage')->once();
        $this->app->instance(TelegramService::class, $telegramMock);

        $geminiMock = Mockery::mock(GeminiService::class);
        // Gemini hallucinates that it wants to execute something without asking confirmation again
        // just because the user said "si"
        $geminiMock->shouldReceive('analyzeConversation')->once()->andReturn([
            'intent' => 'register_production',
            'reply' => 'Listo, lo anoto.',
            'entities' => ['product_name' => 'Galletita', 'quantity' => 10],
            'missing' => [],
            'requires_confirmation' => false,
            'action' => ['name' => 'register_production', 'arguments' => ['product_name' => 'Galletita', 'quantity' => 10]],
            'confidence' => 1.0
        ]);
        
        $this->app->instance(GeminiService::class, $geminiMock);
        app(BotAgentService::class)->processMessage($user, '123456', 'Sí');
        
        $conv->refresh();
        // It must NOT execute and complete, it must demote to ready_for_confirmation
        // because the previous state was completed, not ready_for_confirmation.
        $this->assertEquals('ready_for_confirmation', $conv->context['status']);
        $this->assertNotNull($conv->context['pending_action']);
    }
}