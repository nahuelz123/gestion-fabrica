<?php

namespace Tests\Feature;

use App\Models\AiConversation;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductCategory;
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

class BotAgentServiceTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role='owner'): User
    {
        $company=Company::create(['name'=>'Fábrica '.$role]);
        Warehouse::create(['company_id'=>$company->id,'name'=>'Principal']);
        $unit=Unit::firstOrCreate(['abbreviation'=>'u'],['name'=>'Unidad','type'=>'count']);
        $cat=ProductCategory::create(['company_id'=>$company->id,'name'=>'Insumos']);
        Product::create(['company_id'=>$company->id,'category_id'=>$cat->id,'name'=>'Papel','internal_code'=>'P-'.$company->id,'type'=>'raw_material','base_unit_id'=>$unit->id,'status'=>'active']);
        return User::create(['company_id'=>$company->id,'name'=>'Usuario','email'=>$role.$company->id.'@test.com','password'=>'pass','role'=>$role,'status'=>'active','telegram_chat_id'=>(string)(1000+$company->id)]);
    }

    public function test_mutation_waits_for_confirmation_and_yes_executes_once(): void
    {
        $user=$this->user('owner'); $chat=(string)$user->telegram_chat_id;
        $telegram=Mockery::mock(TelegramService::class); $telegram->shouldReceive('sendMessage')->twice();
        $gemini=Mockery::mock(GeminiService::class); $gemini->shouldReceive('analyzeConversation')->once()->andReturn([
            'intent'=>'add_stock','reply'=>'Voy a sumar','entities'=>[],'action'=>['name'=>'add_stock','arguments'=>['product_name'=>'Papel','quantity'=>10]],'missing'=>[],'requires_confirmation'=>true,'confidence'=>1,
        ]);
        $preview=Mockery::mock(BotActionPreviewService::class); $preview->shouldReceive('preview')->once()->andReturn('Confirmá sumar 10 de Papel.');
        $executor=Mockery::mock(BotActionExecutor::class); $executor->shouldReceive('execute')->once()->andReturn(['success'=>true,'message'=>'Listo']);
        $this->app->instance(TelegramService::class,$telegram); $this->app->instance(GeminiService::class,$gemini); $this->app->instance(BotActionPreviewService::class,$preview); $this->app->instance(BotActionExecutor::class,$executor);
        $agent=app(BotAgentService::class);

        $agent->processMessage($user,$chat,'sumá 10 papel');
        $conversation=AiConversation::where('user_id',$user->id)->firstOrFail();
        $this->assertSame('ready_for_confirmation',$conversation->context['status']);
        $this->assertSame('add_stock',$conversation->pending_action['name']);

        $agent->processMessage($user,$chat,'sí');
        $conversation->refresh();
        $this->assertSame('completed',$conversation->context['status']);
        $this->assertNull($conversation->pending_action);
    }

    public function test_stock_sentence_inherits_boxes_bars_and_pieces_without_leaking_to_loose_units(): void
    {
        $user=$this->user('owner'); $chat=(string)$user->telegram_chat_id;
        $telegram=Mockery::mock(TelegramService::class); $telegram->shouldReceive('sendMessage')->once();
        $gemini=Mockery::mock(GeminiService::class); $gemini->shouldReceive('analyzeConversation')->once()->andReturn([
            'intent'=>'add_stock','reply'=>'','entities'=>[],
            'action'=>['name'=>'add_stock','arguments'=>['items'=>[
                ['product_name'=>'Medallón de carne','quantity'=>60,'presentation_name'=>'cajas'],
                ['product_name'=>'Pan','quantity'=>48,'presentation_name'=>'cajas'],
                ['product_name'=>'queso chedar','quantity'=>10,'presentation_name'=>null],
                ['product_name'=>'jamón','quantity'=>4,'presentation_name'=>null],
                ['product_name'=>'queso','quantity'=>5,'presentation_name'=>null],
                ['product_name'=>'Bacon','quantity'=>15,'presentation_name'=>'piezas'],
                ['product_name'=>'Lomito','quantity'=>10,'presentation_name'=>null],
                ['product_name'=>'bolsitas','quantity'=>2000,'presentation_name'=>null],
                ['product_name'=>'papel manteca','quantity'=>1000,'presentation_name'=>'unidad'],
            ]]],
            'missing'=>[],'requires_confirmation'=>true,'confidence'=>1,
        ]);

        $preview=Mockery::mock(BotActionPreviewService::class);
        $preview->shouldReceive('preview')->once()->with($user,Mockery::on(function($action){
            $items=$action['arguments']['items'] ?? [];
            return ($items[0]['presentation_name'] ?? null)==='cajas'
                && ($items[1]['presentation_name'] ?? null)==='cajas'
                && ($items[2]['presentation_name'] ?? null)==='barra'
                && ($items[3]['presentation_name'] ?? null)==='barra'
                && ($items[4]['presentation_name'] ?? null)==='barra'
                && ($items[5]['presentation_name'] ?? null)==='piezas'
                && ($items[6]['presentation_name'] ?? null)==='pieza'
                && empty($items[7]['presentation_name'])
                && ($items[8]['presentation_name'] ?? null)==='unidad';
        }))->andReturn('Confirmación');

        $executor=Mockery::mock(BotActionExecutor::class); $executor->shouldNotReceive('execute');
        $this->app->instance(TelegramService::class,$telegram);
        $this->app->instance(GeminiService::class,$gemini);
        $this->app->instance(BotActionPreviewService::class,$preview);
        $this->app->instance(BotActionExecutor::class,$executor);

        app(BotAgentService::class)->processMessage(
            $user,
            $chat,
            'Ayer ingresaron 60 cajas de medallón de carne, tenemos 48 cajas de pan 10 barras de queso cheddar 4 de jamón 5 de queso 15 piezas de bacon y 10 de lomito 2000 bolsitas y 1000 papel manteca'
        );

        $pending=AiConversation::where('user_id',$user->id)->firstOrFail()->pending_action;
        $this->assertSame('barra',$pending['arguments']['items'][2]['presentation_name']);
        $this->assertSame('barra',$pending['arguments']['items'][3]['presentation_name']);
        $this->assertSame('barra',$pending['arguments']['items'][4]['presentation_name']);
        $this->assertSame('pieza',$pending['arguments']['items'][6]['presentation_name']);
        $this->assertArrayNotHasKey('presentation_name',$pending['arguments']['items'][7]);
    }

    public function test_pending_action_can_only_be_claimed_once(): void
    {
        $user=$this->user('owner'); $chat=(string)$user->telegram_chat_id;
        $conversation=AiConversation::create(['user_id'=>$user->id,'telegram_chat_id'=>$chat,'pending_action'=>['name'=>'add_stock','arguments'=>['product_name'=>'Papel','quantity'=>1]],'context'=>['status'=>'ready_for_confirmation','pending_action'=>['name'=>'add_stock','arguments'=>['product_name'=>'Papel','quantity'=>1]],'recent_messages'=>[]]]);
        $agent=new BotAgentService(Mockery::mock(TelegramService::class),Mockery::mock(GeminiService::class),app(\App\Services\StockService::class),app(\App\Services\ProductionCalculatorService::class),Mockery::mock(BotActionPreviewService::class));
        $method=new ReflectionMethod(BotAgentService::class,'claimPendingAction'); $method->setAccessible(true);
        $first=$method->invoke($agent,$conversation); $second=$method->invoke($agent,$conversation);
        $this->assertSame('add_stock',$first['action']['name']); $this->assertNull($second);
        $this->assertSame('executing',$conversation->fresh()->context['status']);
    }

    public function test_manager_is_blocked_from_non_stock_action_before_preview_or_execution(): void
    {
        $user=$this->user('manager'); $chat=(string)$user->telegram_chat_id;
        $telegram=Mockery::mock(TelegramService::class); $telegram->shouldReceive('sendMessage')->once()->with($chat,Mockery::on(fn($text)=>str_contains($text,'encargado')));
        $gemini=Mockery::mock(GeminiService::class); $gemini->shouldReceive('analyzeConversation')->once()->andReturn(['intent'=>'create_product','reply'=>'','entities'=>[],'action'=>['name'=>'create_product','arguments'=>['name'=>'Harina']],'missing'=>[],'requires_confirmation'=>true,'confidence'=>1]);
        $preview=Mockery::mock(BotActionPreviewService::class); $preview->shouldNotReceive('preview');
        $executor=Mockery::mock(BotActionExecutor::class); $executor->shouldNotReceive('execute');
        $this->app->instance(TelegramService::class,$telegram); $this->app->instance(GeminiService::class,$gemini); $this->app->instance(BotActionPreviewService::class,$preview); $this->app->instance(BotActionExecutor::class,$executor);
        app(BotAgentService::class)->processMessage($user,$chat,'creá harina');
        $this->assertSame('idle',AiConversation::where('user_id',$user->id)->firstOrFail()->context['status']);
    }

    public function test_read_action_executes_without_confirmation_and_cancel_clears_pending(): void
    {
        $user=$this->user('owner'); $chat=(string)$user->telegram_chat_id;
        $telegram=Mockery::mock(TelegramService::class); $telegram->shouldReceive('sendMessage')->twice();
        $gemini=Mockery::mock(GeminiService::class); $gemini->shouldReceive('analyzeConversation')->once()->andReturn(['intent'=>'get_stock','reply'=>'','entities'=>[],'action'=>['name'=>'get_stock','arguments'=>['product_name'=>'Papel']],'missing'=>[],'requires_confirmation'=>false,'confidence'=>1]);
        $preview=Mockery::mock(BotActionPreviewService::class); $preview->shouldNotReceive('preview');
        $executor=Mockery::mock(BotActionExecutor::class); $executor->shouldReceive('execute')->once()->andReturn(['success'=>true,'message'=>'Tenemos 5']);
        $this->app->instance(TelegramService::class,$telegram); $this->app->instance(GeminiService::class,$gemini); $this->app->instance(BotActionPreviewService::class,$preview); $this->app->instance(BotActionExecutor::class,$executor);
        $agent=app(BotAgentService::class); $agent->processMessage($user,$chat,'stock papel');
        $conv=AiConversation::where('user_id',$user->id)->firstOrFail();
        $conv->update(['pending_action'=>['name'=>'add_stock','arguments'=>['product_name'=>'Papel','quantity'=>2]],'context'=>['status'=>'ready_for_confirmation','pending_action'=>['name'=>'add_stock','arguments'=>['product_name'=>'Papel','quantity'=>2]],'recent_messages'=>[]]]);
        $agent->processMessage($user,$chat,'no');
        $this->assertSame('cancelled',$conv->fresh()->context['status']); $this->assertNull($conv->fresh()->pending_action);
    }
}
