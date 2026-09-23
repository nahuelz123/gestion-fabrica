<?php

namespace Tests\Feature;

use App\Services\GeminiService;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\Request;
use Tests\TestCase;

class GeminiServiceTest extends TestCase
{
    private GeminiService $service;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.gemini.api_key' => 'fake-key']);
        $this->service = app(GeminiService::class);
    }

    private function mockGeminiResponse(array $data)
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                ['text' => json_encode($data)]
                            ]
                        ]
                    ]
                ]
            ], 200)
        ]);
    }

    // 1. create_product incompleto
    public function test_create_product_incomplete()
    {
        $this->mockGeminiResponse([
            'intent' => 'create_product',
            'reply' => 'Perfecto. ¿Cuál es la presentación?',
            'entities' => ['name' => 'Papel manteca'],
            'action' => null,
            'missing' => ['presentation'],
            'requires_confirmation' => false,
            'confidence' => 0.98
        ]);

        $result = $this->service->analyzeConversation('Quiero agregar papel manteca');

        $this->assertEquals('create_product', $result['intent']);
        $this->assertEquals(['presentation'], $result['missing']);
        $this->assertNull($result['action']);
    }

    // 2. create_product completo
    public function test_create_product_complete_requires_confirmation()
    {
        $this->mockGeminiResponse([
            'intent' => 'create_product',
            'reply' => 'Voy a crear Papel manteca, paquete de 1000. ¿Confirmás?',
            'entities' => ['name' => 'Papel manteca', 'presentation' => 'paquete de 1000'],
            'action' => null,
            'missing' => [],
            'requires_confirmation' => true,
            'confidence' => 0.99
        ]);

        $result = $this->service->analyzeConversation('paquete de 1000', [
            'intent' => 'create_product',
            'entities' => ['name' => 'Papel manteca']
        ]);

        $this->assertEquals('create_product', $result['intent']);
        $this->assertTrue($result['requires_confirmation']);
        $this->assertEmpty($result['missing']);
    }

    // 3. corrección de presentation
    public function test_correction_updates_entities()
    {
        $this->mockGeminiResponse([
            'intent' => 'create_product',
            'reply' => 'Voy a corregir a paquete de 500. ¿Confirmás?',
            'entities' => ['name' => 'Papel manteca', 'presentation' => 'paquete de 500'],
            'action' => null,
            'missing' => [],
            'requires_confirmation' => true,
            'confidence' => 0.99
        ]);

        $result = $this->service->analyzeConversation('me equivoqué, era paquete de 500');

        $this->assertEquals('paquete de 500', $result['entities']['presentation']);
    }

    // 4. get_stock
    public function test_get_stock()
    {
        $this->mockGeminiResponse([
            'intent' => 'get_stock',
            'reply' => 'Consultando stock...',
            'entities' => ['name' => 'Harina'],
            'action' => ['name' => 'get_stock', 'arguments' => ['name' => 'Harina']],
            'missing' => [],
            'requires_confirmation' => false,
            'confidence' => 0.95
        ]);

        $result = $this->service->analyzeConversation('¿cuánto stock hay de harina?');
        $this->assertEquals('get_stock', $result['intent']);
        $this->assertNotNull($result['action']);
        $this->assertEquals('get_stock', $result['action']['name']);
    }

    // 5. check_production
    public function test_check_production()
    {
        $this->mockGeminiResponse([
            'intent' => 'check_production',
            'reply' => 'Verificando si podemos producir 500 unidades...',
            'entities' => ['name' => 'Pan', 'quantity' => 500],
            'action' => ['name' => 'check_production', 'arguments' => ['name' => 'Pan', 'quantity' => 500]],
            'missing' => [],
            'requires_confirmation' => false,
            'confidence' => 0.99
        ]);

        $result = $this->service->analyzeConversation('¿podemos hacer 500 panes?');
        $this->assertEquals('check_production', $result['intent']);
    }

    // 6. JSON inválido
    public function test_invalid_json_returns_fallback()
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                ['text' => 'Esto no es JSON {[']
                            ]
                        ]
                    ]
                ]
            ], 200)
        ]);

        $result = $this->service->analyzeConversation('Hola');
        $this->assertEquals('unknown', $result['intent']);
    }

    // 7. intent desconocido
    public function test_unknown_intent_is_preserved_or_fallback()
    {
        $this->mockGeminiResponse([
            'intent' => 'un_intent_inventado',
            'reply' => 'Algo inventado',
        ]);

        $result = $this->service->analyzeConversation('Hola');
        // Debe ser forzado a unknown por validateAndFormatResponse
        $this->assertEquals('unknown', $result['intent']);
    }

    // 8. action inexistente/no permitida
    public function test_unauthorized_action_is_normalized_to_null()
    {
        $this->mockGeminiResponse([
            'intent' => 'create_product',
            'reply' => 'Borrando base de datos',
            'action' => [
                'name' => 'delete_database',
                'arguments' => []
            ]
        ]);

        $result = $this->service->analyzeConversation('borra la bd');
        $this->assertEquals('create_product', $result['intent']);
        $this->assertNull($result['action']); // Invalid action name was stripped
    }

    // 9. preservación del contexto recibido
    public function test_context_is_sent_in_prompt()
    {
        $this->mockGeminiResponse([
            'intent' => 'unknown',
            'reply' => 'test'
        ]);

        $context = ['history' => ['msg1']];
        $this->service->analyzeConversation('hello', $context);

        Http::assertSent(function (Request $request) {
            $body = $request->data();
            $prompt = $body['contents'][0]['parts'][0]['text'];
            return str_contains($prompt, 'hello') && str_contains($prompt, 'msg1');
        });
    }

    // 10. error HTTP/API
    public function test_api_error_returns_fallback_safely()
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response('Server error', 500)
        ]);

        $result = $this->service->analyzeConversation('Hola');
        $this->assertEquals('unknown', $result['intent']);
        $this->assertStringContainsString('Tuve un problema procesando eso', $result['reply']);
    }
}
