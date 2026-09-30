<?php

namespace App\Livewire\Auth;

use App\Enums\UserStatus;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Rule;
use Livewire\Component;

#[Layout('layouts.guest')]
class Login extends Component
{
    #[Rule('required|string|max:255')]
    public string $login = '';

    #[Rule('required|string|max:255')]
    public string $password = '';

    public bool $remember = false;

    public function authenticate(): void
    {
        $this->validate();
        $login = trim($this->login);
        $key = 'login.' . Str::lower($login) . '|' . request()->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            $seconds = RateLimiter::availableIn($key);
            $this->addError('login', "Demasiados intentos fallidos. Intentá de nuevo en {$seconds} segundos.");
            return;
        }

        $field = filter_var($login, FILTER_VALIDATE_EMAIL) ? 'email' : 'phone';
        $credentials = [$field => $login, 'password' => $this->password];

        if (!Auth::attempt($credentials, $this->remember)) {
            RateLimiter::hit($key, 60);
            $this->addError('login', 'Las credenciales no coinciden con nuestros registros.');
            return;
        }

        $user = Auth::user();
        if ($user->status !== UserStatus::Active) {
            Auth::logout();
            session()->invalidate();
            session()->regenerateToken();
            RateLimiter::hit($key, 60);
            $this->addError('login', 'Tu cuenta está inactiva. Contactá al administrador.');
            return;
        }

        RateLimiter::clear($key);
        session()->regenerate();
        $this->redirect(route('dashboard'), navigate: true);
    }

    public function render()
    {
        return view('livewire.auth.login');
    }
}
