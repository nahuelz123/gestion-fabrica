<?php

namespace App\Livewire\Users;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Form extends Component
{
    public ?int $userId = null;
    public string $name = '';
    public string $email = '';
    public string $phone = '';
    public string $role = 'manager';
    public string $status = 'active';
    public string $telegram_chat_id = '';
    public string $password = '';
    public string $password_confirmation = '';

    public function mount(?int $id = null): void
    {
        Gate::authorize('manage-users');

        if (!$id) return;

        $user = User::where('company_id', auth()->user()->company_id)->findOrFail($id);
        $this->userId = $user->id;
        $this->name = (string) $user->name;
        $this->email = (string) ($user->email ?? '');
        $this->phone = (string) ($user->phone ?? '');
        $this->role = $user->role->value;
        $this->status = $user->status->value;
        $this->telegram_chat_id = (string) ($user->telegram_chat_id ?? '');
    }

    public function save(): void
    {
        Gate::authorize('manage-users');

        $companyId = auth()->user()->company_id;
        $editing = $this->userId
            ? User::where('company_id', $companyId)->findOrFail($this->userId)
            : null;

        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->userId)],
            'phone' => ['nullable', 'string', 'max:50', Rule::unique('users', 'phone')->ignore($this->userId)],
            'role' => ['required', Rule::enum(UserRole::class)],
            'status' => ['required', Rule::enum(UserStatus::class)],
            'telegram_chat_id' => ['nullable', 'string', 'max:100', Rule::unique('users', 'telegram_chat_id')->ignore($this->userId)],
            'password' => [$editing ? 'nullable' : 'required', 'string', 'min:10', 'confirmed'],
        ];

        $data = $this->validate($rules, [
            'password.min' => 'La contraseña debe tener al menos 10 caracteres.',
            'password.confirmed' => 'Las contraseñas no coinciden.',
        ]);

        if ($editing && $editing->id === auth()->id()) {
            if ($data['status'] !== UserStatus::Active->value) {
                $this->addError('status', 'No podés desactivar tu propio usuario.');
                return;
            }
            if ($data['role'] !== UserRole::Owner->value) {
                $this->addError('role', 'No podés quitarte el rol de dueño a vos mismo.');
                return;
            }
        }

        $payload = [
            'company_id' => $companyId,
            'name' => trim($data['name']),
            'email' => mb_strtolower(trim($data['email'])),
            'phone' => trim((string) ($data['phone'] ?? '')) ?: null,
            'role' => $data['role'],
            'status' => $data['status'],
            'telegram_chat_id' => trim((string) ($data['telegram_chat_id'] ?? '')) ?: null,
        ];

        if (!empty($data['password'])) {
            $payload['password'] = $data['password'];
        }

        if ($editing) {
            $revokeSessions = !empty($data['password'])
                || $data['status'] !== $editing->status->value
                || $data['role'] !== $editing->role->value;

            $editing->update($payload);

            if ($revokeSessions && $editing->id !== auth()->id()) {
                DB::table('sessions')->where('user_id', $editing->id)->delete();
            }

            $message = 'Usuario actualizado.';
        } else {
            User::create($payload);
            $message = 'Usuario creado.';
        }

        session()->flash('message', $message);
        $this->redirect(route('users.index'), navigate: true);
    }

    public function render()
    {
        return view('livewire.users.form', [
            'roles' => UserRole::cases(),
            'statuses' => UserStatus::cases(),
        ]);
    }
}
