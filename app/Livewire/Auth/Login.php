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
    #[Rule('required|string')]
    public string $login = '';

    #[Rule('required|string')]
    public string $password = '';

    public bool $remember = false;

    public function authenticate(): void
    {
        $this->validate();

        // Rate limit: max 5 attempts per login+IP per minute
        $key = 'login.' . Str::lower($this->login) . '|' . request()->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            $seconds = RateLimiter::availableIn($key);
            $this->addError('login', "Demasiados intentos fallidos. Intentá de nuevo en {$seconds} segundos.");
            return;
        }

        // Determine if login is email or phone
        $field = filter_var($this->login, FILTER_VALIDATE_EMAIL) ? 'email' : 'phone';

        $credentials = [
            $field => $this->login,
            'password' => $this->password,
        ];

        if (!Auth::attempt($credentials, $this->remember)) {
            RateLimiter::hit($key, 60); // decay of 60 seconds
            $this->addError('login', 'Las credenciales no coinciden con nuestros registros.');
            return;
        }

        $user = Auth::user();

        if ($user->status !== UserStatus::Active) {
            Auth::logout();
            $this->addError('login', 'Tu cuenta está inactiva. Contactá al administrador.');
            return;
        }

        RateLimiter::clear($key); // reset counter on successful login

        session()->regenerate();

        $this->redirect(route('dashboard'), navigate: true);
    }

    public function render()
    {
        return view('livewire.auth.login');
    }
}
