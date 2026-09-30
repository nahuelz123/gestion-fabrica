<?php

namespace Tests\Feature;

use App\Jobs\ProcessTelegramMessageJob;
use App\Models\Company;
use App\Models\User;
use App\Services\BotAgentService;
use App\Services\TelegramService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class TelegramBotTest extends TestCase
{
    use RefreshDatabase;

    public function test_webhook_is_fail_closed_and_valid_secret_enqueues_update(): void
    {
        config(['services.telegram.webhook_secret'=>'secret-test']); Queue::fake();
        $this->postJson('/telegram/webhook',['update_id'=>1])->assertForbidden();
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token','wrong')->postJson('/telegram/webhook',['update_id'=>2])->assertForbidden();
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token','secret-test')->postJson('/telegram/webhook',['foo'=>'bar'])->assertStatus(422);
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token','secret-test')->postJson('/telegram/webhook',['update_id'=>3,'message'=>['text'=>'hola','chat'=>['id'=>10,'type'=>'private']]])->assertOk();
        Queue::assertPushed(ProcessTelegramMessageJob::class,fn($job)=>$job->payload['update_id']===3);
    }

    public function test_job_is_idempotent_for_same_update_and_unknown_users_are_not_processed(): void
    {
        $telegram=Mockery::mock(TelegramService::class); $telegram->shouldReceive('sendMessage')->once()->with('999','No estás registrado para usar este asistente.');
        $agent=Mockery::mock(BotAgentService::class); $agent->shouldNotReceive('processMessage');
        $payload=['update_id'=>50,'message'=>['text'=>'hola','chat'=>['id'=>999,'type'=>'private']]];
        $job=new ProcessTelegramMessageJob($payload); $job->handle($agent,$telegram); $job->handle($agent,$telegram);
        $this->assertDatabaseCount('telegram_processed_updates',1);
    }

    public function test_job_processes_only_active_registered_private_user(): void
    {
        $company=Company::create(['name'=>'Fábrica']);
        $user=User::create(['company_id'=>$company->id,'name'=>'Encargado','email'=>'m@test.com','password'=>'pass','role'=>'manager','status'=>'active','telegram_chat_id'=>'123']);
        $telegram=Mockery::mock(TelegramService::class); $telegram->shouldNotReceive('sendMessage');
        $agent=Mockery::mock(BotAgentService::class); $agent->shouldReceive('processMessage')->once()->with(Mockery::on(fn($u)=>$u->id===$user->id),'123','stock');
        (new ProcessTelegramMessageJob(['update_id'=>60,'message'=>['text'=>'stock','chat'=>['id'=>123,'type'=>'private']]]))->handle($agent,$telegram);
    }
}
