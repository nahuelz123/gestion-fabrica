<?php

namespace App\Livewire\Auth;

use App\Enums\UserStatus;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Rule;
use Livewire\Component;

#[Layout('layouts.guest')]
class Login extends Component
{
    #[Rule('required|string')]
    public string $login = '';

    #[Rule('required|string')]
    public string $password = '';

    public bool $remember = false;

    public function authenticate(): void
    {
        $this->validate();

        // Determine if login is email or phone
        $field = filter_var($this->login, FILTER_VALIDATE_EMAIL) ? 'email' : 'phone';

        $credentials = [
            $field => $this->login,
            'password' => $this->password,
        ];

        if (!Auth::attempt($credentials, $this->remember)) {
            $this->addError('login', 'Las credenciales no coinciden con nuestros registros.');
            return;
        }

        $user = Auth::user();

        if ($user->status !== UserStatus::Active) {
            Auth::logout();
            $this->addError('login', 'Tu cuenta está inactiva. Contactá al administrador.');
            return;
        }

        session()->regenerate();

        $this->redirect(route('dashboard'), navigate: true);
    }

    public function render()
    {
        return view('livewire.auth.login');
    }
}
