<?php

namespace App\Providers;

use App\Models\User;
use App\Services\AuthorizedBotActionExecutor;
use App\Services\BotActionExecutor;
use App\Services\MercadoPagoVendingService;
use App\Services\ReliableMercadoPagoVendingService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(BotActionExecutor::class, AuthorizedBotActionExecutor::class);
        $this->app->bind(MercadoPagoVendingService::class, ReliableMercadoPagoVendingService::class);
    }

    public function boot(): void
    {
        if ($this->app->environment('production')) {
            // No exponer stack traces, rutas internas ni configuración sensible aunque
            // APP_DEBUG haya quedado habilitado por error en el proveedor de hosting.
            config(['app.debug' => false]);
            URL::forceScheme('https');
        }

        Gate::define('manage-users', fn (User $user) => $user->isOwner());
        Gate::define('owner-only', fn (User $user) => $user->isOwner());
        Gate::define('manage-stock', fn (User $user) => $user->canManageStock());
        Gate::define('manage-production', fn (User $user) => $user->canManageProduction());
        // Alias conservado para no romper enlaces/componentes previos.
        Gate::define('simulate-production', fn (User $user) => $user->canManageProduction());
    }
}
