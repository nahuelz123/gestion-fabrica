<?php

namespace Tests\Feature;

use App\Services\GeminiService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GeminiServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.gemini.api_key'=>'test-key','services.gemini.model'=>'gemini-primary','services.gemini.fallback_model'=>null]);
    }

    private function ok(array $payload): array
    {
        return ['choices'=>[['message'=>['content'=>json_encode($payload,JSON_UNESCAPED_UNICODE)]]]];
    }

    public function test_parses_structured_action_and_sends_context(): void
    {
        Http::fake(['*'=>Http::response($this->ok(['intent'=>'add_stock','reply'=>'Voy a sumar','entities'=>[],'action'=>['name'=>'add_stock','arguments'=>['product_name'=>'Papel','quantity'=>10]],'missing'=>[],'requires_confirmation'=>true,'confidence'=>0.99]),200)]);
        $result=app(GeminiService::class)->analyzeConversation('sumá 10 papel',['last_product_name'=>'Papel']);
        $this->assertSame('add_stock',$result['intent']); $this->assertSame('add_stock',$result['action']['name']); $this->assertTrue($result['requires_confirmation']);
        Http::assertSent(function ($request) {
            $body=$request->data();
            return $body['model']==='gemini-primary' && str_contains($body['messages'][1]['content'],'last_product_name');
        });
    }

    public function test_invalid_action_is_not_executable(): void
    {
        Http::fake(['*'=>Http::response($this->ok(['intent'=>'unknown','reply'=>'No','entities'=>[],'action'=>['name'=>'drop_database','arguments'=>[]],'missing'=>[],'requires_confirmation'=>false,'confidence'=>0.1]),200)]);
        $result=app(GeminiService::class)->analyzeConversation('hacé algo peligroso');
        $this->assertNull($result['action']); $this->assertSame('unknown',$result['intent']);
    }

    public function test_429_returns_quota_message_without_transient_retries(): void
    {
        Http::fake(['*'=>Http::response(['error'=>['details'=>[['retryDelay'=>'3.2s']]]],429)]);
        $result=app(GeminiService::class)->analyzeConversation('hola');
        $this->assertStringContainsString('4 segundos',$result['reply']);
        Http::assertSentCount(1);
    }

    public function test_503_retries_primary_then_optional_fallback(): void
    {
        config(['services.gemini.fallback_model'=>'gemini-fallback']);
        Http::fake(['*'=>Http::sequence()
            ->push(['error'=>'busy'],503)
            ->push(['error'=>'busy'],503)
            ->push(['error'=>'busy'],503)
            ->push($this->ok(['intent'=>'get_stock','reply'=>'ok','entities'=>[],'action'=>['name'=>'get_stock','arguments'=>['product_name'=>'all']],'missing'=>[],'requires_confirmation'=>false,'confidence'=>1]),200)]);
        $result=app(GeminiService::class)->analyzeConversation('stock');
        $this->assertSame('get_stock',$result['intent']); Http::assertSentCount(4);
        $models=collect(Http::recorded())->map(fn($pair)=>$pair[0]->data()['model'] ?? null)->values()->all();
        $this->assertSame(['gemini-primary','gemini-primary','gemini-primary','gemini-fallback'],$models);
    }

    public function test_invalid_json_falls_back_safely(): void
    {
        Http::fake(['*'=>Http::response(['choices'=>[['message'=>['content'=>'not-json']]]],200)]);
        $result=app(GeminiService::class)->analyzeConversation('hola');
        $this->assertSame('unknown',$result['intent']); $this->assertNull($result['action']);
    }
}
