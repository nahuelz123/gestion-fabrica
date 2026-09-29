<?php

use App\Livewire\Auth\Login;
use App\Livewire\Dashboard;
use App\Livewire\Products;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', Login::class)->name('login');
});

Route::middleware('auth')->group(function () {
    Route::get('/', Dashboard::class)->name('dashboard');

    Route::get('/productos', Products\Index::class)->name('products.index');
    Route::get('/productos/crear', Products\Form::class)->name('products.create');
    Route::get('/productos/{id}/editar', Products\Form::class)->name('products.edit');

    Route::get('/inventario', \App\Livewire\Inventory\StockIndex::class)->name('inventory.index');
    Route::get('/inventario/entrada', \App\Livewire\Inventory\StockEntry::class)->name('inventory.entry');
    Route::get('/inventario/salida', \App\Livewire\Inventory\StockExit::class)->name('inventory.exit');
    Route::get('/inventario/ajuste', \App\Livewire\Inventory\StockManager::class)->name('inventory.adjust');

    Route::get('/proveedores', \App\Livewire\Suppliers\Index::class)->name('suppliers.index');
    Route::get('/proveedores/crear', \App\Livewire\Suppliers\Form::class)->name('suppliers.create');
    Route::get('/proveedores/{id}/editar', \App\Livewire\Suppliers\Form::class)->name('suppliers.edit');

    Route::get('/compras', \App\Livewire\Purchases\Index::class)->name('purchases.index');
    Route::get('/compras/crear', \App\Livewire\Purchases\Form::class)->name('purchases.create');
    Route::get('/compras/{id}', \App\Livewire\Purchases\Show::class)->name('purchases.show');

    Route::get('/productos/{productId}/receta', \App\Livewire\Recipes\Manager::class)->name('recipes.manager');
    Route::get('/produccion/calculadora', \App\Livewire\Production\Calculator::class)->name('production.calculator');

    // Máquinas expendedoras
    Route::get('/maquinas', \App\Livewire\Vending\Index::class)->name('vending.index');
    Route::get('/maquinas/crear', \App\Livewire\Vending\MachineForm::class)->name('vending.machines.create');
    Route::get('/maquinas/{id}/editar', \App\Livewire\Vending\MachineForm::class)->name('vending.machines.edit');
    Route::get('/maquinas/ventas', \App\Livewire\Vending\Sales::class)->name('vending.sales');

    Route::get('/maquinas/comercios', \App\Livewire\Vending\PartnersIndex::class)->name('vending.partners.index');
    Route::get('/maquinas/comercios/crear', \App\Livewire\Vending\PartnerForm::class)->name('vending.partners.create');
    Route::get('/maquinas/comercios/{id}/editar', \App\Livewire\Vending\PartnerForm::class)->name('vending.partners.edit');
    Route::get('/maquinas/comercios/{partner}/mercadopago/conectar', [\App\Http\Controllers\Api\MercadoPagoVendingController::class, 'connect'])
        ->name('vending.mercadopago.connect');
    Route::get('/mercadopago/oauth/callback', [\App\Http\Controllers\Api\MercadoPagoVendingController::class, 'callback'])
        ->name('vending.mercadopago.callback');

    Route::post('/logout', function () {
        auth()->logout();
        session()->invalidate();
        session()->regenerateToken();
        return redirect('/');
    })->name('logout');
});

// Pantalla pública para la tablet instalada en la máquina. El token es aleatorio
// y no expone credenciales ni datos internos de comisión.
Route::get('/maquina/{token}', \App\Http\Controllers\VendingTabletController::class)
    ->name('vending.tablet');

Route::post('/telegram/webhook', [\App\Http\Controllers\Api\TelegramWebhookController::class, 'handle'])
    ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class]);

Route::post('/mercadopago/webhook', [\App\Http\Controllers\Api\MercadoPagoVendingController::class, 'webhook'])
    ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class])
    ->name('vending.mercadopago.webhook');
