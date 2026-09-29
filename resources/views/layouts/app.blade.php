<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'Gestión Fábrica' }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="min-h-screen bg-gray-100" x-data="{ sidebarOpen: false }">
    <nav class="bg-white shadow-sm border-b border-gray-200">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between h-16">
                <div class="flex items-center">
                    <button @click="sidebarOpen = !sidebarOpen" class="md:hidden p-2 rounded-md text-gray-500 hover:text-gray-700 hover:bg-gray-100">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
                    </button>
                    <span class="ml-2 text-xl font-bold text-gray-800">🏭 Gestión Fábrica</span>
                </div>
                <div class="flex items-center space-x-4">
                    <div class="text-sm text-gray-600">
                        <span class="font-medium">{{ auth()->user()->name }}</span>
                        <span class="ml-1 inline-flex items-center px-2 py-0.5 rounded text-xs font-medium {{ auth()->user()->isOwner() ? 'bg-blue-100 text-blue-800' : 'bg-green-100 text-green-800' }}">{{ auth()->user()->role->label() }}</span>
                    </div>
                    <form method="POST" action="{{ route('logout') }}">@csrf<button type="submit" class="text-sm text-gray-500 hover:text-gray-700">Salir</button></form>
                </div>
            </div>
        </div>
    </nav>

    <div class="flex">
        <aside class="w-64 min-h-[calc(100vh-4rem)] bg-white shadow-sm border-r border-gray-200 hidden md:block" :class="{ 'block': sidebarOpen, 'hidden': !sidebarOpen }" @class(['md:block'])>
            <nav class="mt-4 px-3 space-y-1">
                <a href="{{ route('dashboard') }}" class="flex items-center px-3 py-2 text-sm font-medium rounded-md {{ request()->routeIs('dashboard') ? 'bg-gray-100 text-gray-900' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900' }}">🏠 <span class="ml-3">Inicio</span></a>

                @can('owner-only')
                    <a href="{{ route('products.index') }}" class="flex items-center px-3 py-2 text-sm font-medium rounded-md {{ request()->routeIs('products.*') ? 'bg-gray-100 text-gray-900' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900' }}">📦 <span class="ml-3">Productos</span></a>
                @endcan

                <a href="{{ route('inventory.index') }}" class="flex items-center px-3 py-2 text-sm font-medium rounded-md {{ request()->routeIs('inventory.*') ? 'bg-gray-100 text-gray-900' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900' }}">📋 <span class="ml-3">Inventario</span></a>

                @can('owner-only')
                    <a href="{{ route('suppliers.index') }}" class="flex items-center px-3 py-2 text-sm font-medium rounded-md {{ request()->routeIs('suppliers.*') ? 'bg-gray-100 text-gray-900' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900' }}">👥 <span class="ml-3">Proveedores</span></a>
                    <a href="{{ route('purchases.index') }}" class="flex items-center px-3 py-2 text-sm font-medium rounded-md {{ request()->routeIs('purchases.*') ? 'bg-gray-100 text-gray-900' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900' }}">🛒 <span class="ml-3">Compras</span></a>
                    <a href="{{ route('vending.index') }}" class="flex items-center px-3 py-2 text-sm font-medium rounded-md {{ request()->routeIs('vending.*') ? 'bg-gray-100 text-gray-900' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900' }}">🏪 <span class="ml-3">Máquinas</span></a>
                @endcan

                <div class="pt-4 pb-2"><p class="px-3 text-xs font-semibold text-gray-500 uppercase tracking-wider">Producción</p></div>
                <a href="{{ route('production.calculator') }}" class="flex items-center px-3 py-2 text-sm font-medium rounded-md {{ request()->routeIs('production.*') ? 'bg-gray-100 text-gray-900' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900' }}">🧮 <span class="ml-3">Calculadora</span></a>
            </nav>
        </aside>

        <main class="flex-1 p-6">{{ $slot }}</main>
    </div>

    @livewireScripts
</body>
</html>
