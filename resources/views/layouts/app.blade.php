<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'Rapi Burguer' }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="min-h-screen bg-gray-50" x-data="{ sidebarOpen: false }">
<nav class="bg-red-600 text-white shadow-sm border-b border-red-700">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between h-16">
            <div class="flex items-center">
                <button @click="sidebarOpen = !sidebarOpen" class="md:hidden p-2 rounded-md text-white/90 hover:bg-red-700" aria-label="Abrir menú">☰</button>
                <span class="ml-2 text-xl font-black tracking-tight">🍔 Rapi Burguer</span>
                <span class="hidden sm:inline-flex ml-3 px-2 py-1 rounded-full bg-yellow-400 text-red-900 text-[10px] font-bold uppercase tracking-wider">Gestión</span>
            </div>
            <div class="flex items-center space-x-4">
                <div class="text-sm text-white/90">
                    <span class="font-medium">{{ auth()->user()->name }}</span>
                    <span class="ml-1 inline-flex items-center px-2 py-0.5 rounded text-xs font-semibold {{ auth()->user()->isOwner() ? 'bg-white text-red-700' : 'bg-yellow-400 text-red-900' }}">{{ auth()->user()->role->label() }}</span>
                </div>
                <form method="POST" action="{{ route('logout') }}">@csrf<button type="submit" class="text-sm text-white/80 hover:text-white">Salir</button></form>
            </div>
        </div>
    </div>
</nav>
<div class="flex">
    <aside class="w-64 min-h-[calc(100vh-4rem)] bg-white shadow-sm border-r border-gray-200 hidden md:block" :class="{ 'block': sidebarOpen, 'hidden': !sidebarOpen }" @class(['md:block'])>
        <nav class="mt-4 px-3 space-y-1">
            <a href="{{ route('dashboard') }}" class="flex items-center px-3 py-2 text-sm font-medium rounded-lg {{ request()->routeIs('dashboard') ? 'bg-red-50 text-red-700' : 'text-gray-600 hover:bg-gray-50' }}">🏠 <span class="ml-3">Inicio</span></a>
            <a href="{{ route('inventory.index') }}" class="flex items-center px-3 py-2 text-sm font-medium rounded-lg {{ request()->routeIs('inventory.index') || request()->routeIs('inventory.entry') || request()->routeIs('inventory.exit') || request()->routeIs('inventory.adjust') ? 'bg-red-50 text-red-700' : 'text-gray-600 hover:bg-gray-50' }}">📦 <span class="ml-3">Stock</span></a>
            <a href="{{ route('inventory.movements') }}" class="flex items-center px-3 py-2 text-sm font-medium rounded-lg {{ request()->routeIs('inventory.movements') ? 'bg-red-50 text-red-700' : 'text-gray-600 hover:bg-gray-50' }}">🧾 <span class="ml-3">Movimientos</span></a>

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
    <main class="flex-1 p-4 sm:p-6">{{ $slot }}</main>
</div>
@livewireScripts
</body>
</html>
