<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }

        Gate::define('manage-users', function (User $user) {
            return $user->isOwner();
        });

        // Restringe pantallas y acciones exclusivas del dueño (Productos, Proveedores, Compras, Recetas, Confirmar Producción)
        Gate::define('owner-only', function (User $user) {
            return $user->isOwner();
        });
    }
}
