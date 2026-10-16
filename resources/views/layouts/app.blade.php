<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'Rapi Burguer' }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="min-h-screen bg-gray-50" x-data="{ sidebarOpen: false }" @keydown.escape.window="sidebarOpen = false">
<nav class="relative z-20 bg-red-600 text-white shadow-sm border-b border-red-700">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex min-w-0 items-center justify-between gap-2 h-16">
            <div class="flex min-w-0 items-center">
                <button type="button" @click="sidebarOpen = !sidebarOpen" :aria-expanded="sidebarOpen.toString()" aria-controls="app-sidebar" class="md:hidden flex min-h-11 min-w-11 shrink-0 items-center justify-center rounded-md text-white/90 hover:bg-red-700" aria-label="Abrir o cerrar menú">☰</button>
                <a href="{{ route('dashboard') }}" wire:navigate class="ml-2 min-w-0 truncate text-base sm:text-xl font-black tracking-tight">🍔 Rapi Burguer</a>
                <span class="hidden sm:inline-flex ml-3 px-2 py-1 rounded-full bg-yellow-400 text-red-900 text-[10px] font-bold uppercase tracking-wider">Gestión</span>
            </div>
            <div class="flex shrink-0 items-center gap-1 sm:gap-4">
                <div class="max-w-24 truncate text-xs sm:max-w-none sm:text-sm text-white/90">
                    <span class="font-medium">{{ auth()->user()->name }}</span>
                    <span class="hidden sm:inline-flex ml-1 items-center px-2 py-0.5 rounded text-xs font-semibold {{ auth()->user()->isOwner() ? 'bg-white text-red-700' : 'bg-yellow-400 text-red-900' }}">{{ auth()->user()->role->label() }}</span>
                </div>
                <form method="POST" action="{{ route('logout') }}">@csrf<button type="submit" class="min-h-11 rounded-md px-2 text-sm text-white/90 hover:bg-red-700 hover:text-white">Salir</button></form>
            </div>
        </div>
    </div>
</nav>
<div x-cloak x-show="sidebarOpen" x-transition.opacity @click="sidebarOpen = false" class="fixed inset-0 z-30 bg-gray-950/60 md:hidden" aria-hidden="true"></div>
<div class="mx-auto w-full max-w-screen-2xl md:flex">
    <aside x-cloak id="app-sidebar" aria-label="Menú principal" class="fixed inset-y-0 left-0 z-40 flex h-[100dvh] w-[min(19rem,85vw)] flex-col overflow-y-auto border-r border-gray-200 bg-white shadow-xl transition-transform duration-200 ease-out md:sticky md:top-0 md:h-[calc(100dvh-4rem)] md:w-64 md:shrink-0 md:translate-x-0 md:shadow-none" :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full'">
        <div class="flex items-center justify-between border-b px-4 py-2 md:hidden">
            <strong class="text-sm text-gray-700">Navegación</strong>
            <button type="button" @click="sidebarOpen = false" class="min-h-11 min-w-11 rounded-lg text-gray-700 hover:bg-gray-100" aria-label="Cerrar menú">✕</button>
        </div>
        <nav class="flex-1 mt-4 px-3 space-y-1" @click="if ($event.target.closest('a')) sidebarOpen = false">
            <a href="{{ route('dashboard') }}" class="flex items-center px-3 py-2 text-sm font-medium rounded-lg {{ request()->routeIs('dashboard') ? 'bg-red-50 text-red-700' : 'text-gray-600 hover:bg-gray-50' }}">🏠 <span class="ml-3">Inicio</span></a>
            @can('manage-stock')
            <a href="{{ route('inventory.index') }}" class="flex items-center px-3 py-2 text-sm font-medium rounded-lg {{ request()->routeIs('inventory.index') || request()->routeIs('inventory.entry') || request()->routeIs('inventory.exit') || request()->routeIs('inventory.adjust') ? 'bg-red-50 text-red-700' : 'text-gray-600 hover:bg-gray-50' }}">📦 <span class="ml-3">Stock</span></a>
            <a href="{{ route('inventory.import') }}" class="flex items-center px-3 py-2 text-sm font-medium rounded-lg {{ request()->routeIs('inventory.import') ? 'bg-red-50 text-red-700' : 'text-gray-600 hover:bg-gray-50' }}">📊 <span class="ml-3">Importar Excel</span></a>
            <a href="{{ route('inventory.movements') }}" class="flex items-center px-3 py-2 text-sm font-medium rounded-lg {{ request()->routeIs('inventory.movements') ? 'bg-red-50 text-red-700' : 'text-gray-600 hover:bg-gray-50' }}">🧾 <span class="ml-3">Movimientos</span></a>

            @endcan

            @can('manage-production')
                <a href="{{ route('production.calculator') }}" class="flex items-center px-3 py-2 text-sm font-medium rounded-lg {{ request()->routeIs('production.*') ? 'bg-red-50 text-red-700' : 'text-gray-600 hover:bg-gray-50' }}">🏭 <span class="ml-3">Producción</span></a>
            @endcan

            @can('owner-only')
                <div class="pt-4 pb-2"><p class="px-3 text-xs font-semibold text-gray-400 uppercase tracking-wider">Gestión del negocio</p></div>
                <a href="{{ route('purchases.index') }}" class="flex items-center px-3 py-2 text-sm font-medium rounded-lg {{ request()->routeIs('purchases.*') ? 'bg-red-50 text-red-700' : 'text-gray-600 hover:bg-gray-50' }}">🛒 <span class="ml-3">Compras</span></a>
                <a href="{{ route('products.index') }}" class="flex items-center px-3 py-2 text-sm font-medium rounded-lg {{ request()->routeIs('products.*') || request()->routeIs('recipes.*') ? 'bg-red-50 text-red-700' : 'text-gray-600 hover:bg-gray-50' }}">🍔 <span class="ml-3">Productos y recetas</span></a>
                <a href="{{ route('suppliers.index') }}" class="flex items-center px-3 py-2 text-sm font-medium rounded-lg {{ request()->routeIs('suppliers.*') ? 'bg-red-50 text-red-700' : 'text-gray-600 hover:bg-gray-50' }}">👥 <span class="ml-3">Proveedores</span></a>
                <a href="{{ route('vending.index') }}" class="flex items-center px-3 py-2 text-sm font-medium rounded-lg {{ request()->routeIs('vending.*') ? 'bg-red-50 text-red-700' : 'text-gray-600 hover:bg-gray-50' }}">🏪 <span class="ml-3">Máquinas</span></a>
                <a href="{{ route('users.index') }}" class="flex items-center px-3 py-2 text-sm font-medium rounded-lg {{ request()->routeIs('users.*') ? 'bg-red-50 text-red-700' : 'text-gray-600 hover:bg-gray-50' }}">🔐 <span class="ml-3">Usuarios</span></a>
            @endcan
        </nav>
        <div class="px-6 py-6 mt-6 border-t text-xs text-gray-500 space-y-1">
            <a href="{{ route('legal.privacy') }}" target="_blank" class="block hover:text-red-700">Privacidad</a>
            <a href="{{ route('legal.cookies') }}" target="_blank" class="block hover:text-red-700">Cookies</a>
        </div>
    </aside>
    <main id="main-content" tabindex="-1" class="min-w-0 w-full flex-1 px-3 py-5 sm:px-6 sm:py-7 lg:px-8">{{ $slot }}</main>
</div>
@livewireScripts
</body>
</html>
