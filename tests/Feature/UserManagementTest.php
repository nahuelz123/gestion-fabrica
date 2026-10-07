<?php

namespace Tests\Feature;

use App\Livewire\Users\Form;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $owner;
    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        $this->company = Company::create(['name' => 'Rapi Burguer']);

        $this->owner = User::create([
            'company_id' => $this->company->id,
            'name' => 'Dueño',
            'email' => 'dueno@rapiburguer.test',
            'password' => 'clave-segura-123',
            'role' => 'owner',
            'status' => 'active',
        ]);

        $this->manager = User::create([
            'company_id' => $this->company->id,
            'name' => 'Encargado',
            'email' => 'encargado@rapiburguer.test',
            'password' => 'clave-segura-123',
            'role' => 'manager',
            'status' => 'active',
        ]);
    }

    public function test_only_owner_can_open_user_management(): void
    {
        $this->actingAs($this->owner)
            ->get(route('users.index'))
            ->assertOk();

        $this->actingAs($this->manager)
            ->get(route('users.index'))
            ->assertForbidden();
    }

    public function test_owner_can_create_manager_with_secure_password(): void
    {
        Livewire::actingAs($this->owner)
            ->test(Form::class)
            ->set('name', 'Encargado Turno Tarde')
            ->set('email', 'turnotarde@rapiburguer.test')
            ->set('role', 'manager')
            ->set('status', 'active')
            ->set('telegram_chat_id', '123456789')
            ->set('password', 'una-clave-segura-2026')
            ->set('password_confirmation', 'una-clave-segura-2026')
            ->call('save')
            ->assertHasNoErrors();

        $created = User::where('email', 'turnotarde@rapiburguer.test')->firstOrFail();
        $this->assertTrue($created->isManager());
        $this->assertTrue($created->isActive());
        $this->assertSame('123456789', $created->telegram_chat_id);
        $this->assertNotSame('una-clave-segura-2026', $created->password);
    }

    public function test_owner_cannot_demote_or_disable_self(): void
    {
        Livewire::actingAs($this->owner)
            ->test(Form::class, ['id' => $this->owner->id])
            ->set('role', 'manager')
            ->call('save')
            ->assertHasErrors(['role']);

        Livewire::actingAs($this->owner)
            ->test(Form::class, ['id' => $this->owner->id])
            ->set('status', 'inactive')
            ->call('save')
            ->assertHasErrors(['status']);

        $this->assertTrue($this->owner->fresh()->isOwner());
        $this->assertTrue($this->owner->fresh()->isActive());
    }

    public function test_disabling_manager_revokes_existing_sessions(): void
    {
        DB::table('sessions')->insert([
            'id' => 'session-manager-test',
            'user_id' => $this->manager->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'test',
            'payload' => 'test',
            'last_activity' => now()->timestamp,
        ]);

        Livewire::actingAs($this->owner)
            ->test(Form::class, ['id' => $this->manager->id])
            ->set('status', 'inactive')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertFalse($this->manager->fresh()->isActive());
        $this->assertDatabaseMissing('sessions', ['id' => 'session-manager-test']);
    }
}
