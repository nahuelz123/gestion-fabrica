<?php

use App\Livewire\Auth\Login;
use App\Livewire\Dashboard;
use App\Livewire\Products;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Guest routes
|--------------------------------------------------------------------------
*/
Route::middleware('guest')->group(function () {
    Route::get('/login', Login::class)->name('login');
});

/*
|--------------------------------------------------------------------------
| Authenticated routes
|--------------------------------------------------------------------------
*/
Route::middleware('auth')->group(function () {
    Route::get('/', Dashboard::class)->name('dashboard');

    // Products
    Route::get('/productos', Products\Index::class)->name('products.index');
    Route::get('/productos/crear', Products\Form::class)->name('products.create');
    Route::get('/productos/{id}/editar', Products\Form::class)->name('products.edit');

    // Inventory
    Route::get('/inventario', \App\Livewire\Inventory\StockIndex::class)->name('inventory.index');
    Route::get('/inventario/entrada', \App\Livewire\Inventory\StockEntry::class)->name('inventory.entry');
    Route::get('/inventario/salida', \App\Livewire\Inventory\StockExit::class)->name('inventory.exit');
    Route::get('/inventario/ajuste', \App\Livewire\Inventory\StockManager::class)->name('inventory.adjust');

    // Suppliers
    Route::get('/proveedores', \App\Livewire\Suppliers\Index::class)->name('suppliers.index');
    Route::get('/proveedores/crear', \App\Livewire\Suppliers\Form::class)->name('suppliers.create');
    Route::get('/proveedores/{id}/editar', \App\Livewire\Suppliers\Form::class)->name('suppliers.edit');

    // Purchases
    Route::get('/compras', \App\Livewire\Purchases\Index::class)->name('purchases.index');
    Route::get('/compras/crear', \App\Livewire\Purchases\Form::class)->name('purchases.create');
    Route::get('/compras/{id}', \App\Livewire\Purchases\Show::class)->name('purchases.show');

    // Recipes
    Route::get('/productos/{productId}/receta', \App\Livewire\Recipes\Manager::class)->name('recipes.manager');

    // Production
    Route::get('/produccion/calculadora', \App\Livewire\Production\Calculator::class)->name('production.calculator');

    Route::post('/logout', function () {
        auth()->logout();
        session()->invalidate();
        session()->regenerateToken();
        return redirect('/');
    })->name('logout');
});

Route::post('/telegram/webhook', [\App\Http\Controllers\Api\TelegramWebhookController::class, 'handle'])
    ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class]);
