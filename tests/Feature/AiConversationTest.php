<?php

namespace Tests\Feature;

use App\Models\AiConversation;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiConversationTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_save_and_retrieve_json_context()
    {
        $company = Company::create(['name' => 'Test Company']);
        $user = User::factory()->create(['company_id' => $company->id]);

        $contextData = [
            'intent' => 'create_product',
            'status' => 'collecting',
            'entities' => [
                'name' => 'Papel manteca',
                'type' => 'raw_material'
            ],
            'history' => [
                ['role' => 'user', 'text' => 'Hola']
            ]
        ];

        $conversation = AiConversation::create([
            'user_id' => $user->id,
            'telegram_chat_id' => '123456',
            'pending_action' => ['type' => 'old_action'],
            'context' => $contextData
        ]);

        $this->assertDatabaseHas('ai_conversations', [
            'id' => $conversation->id,
            'telegram_chat_id' => '123456'
        ]);

        $loaded = AiConversation::find($conversation->id);
        $this->assertIsArray($loaded->context);
        $this->assertEquals('create_product', $loaded->context['intent']);
        $this->assertEquals('Papel manteca', $loaded->context['entities']['name']);
        $this->assertIsArray($loaded->pending_action);
        $this->assertEquals('old_action', $loaded->pending_action['type']);
    }
}
