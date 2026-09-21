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
    {{-- Top navbar --}}
    <nav class="bg-white shadow-sm border-b border-gray-200">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between h-16">
                <div class="flex items-center">
                    <button @click="sidebarOpen = !sidebarOpen" class="md:hidden p-2 rounded-md text-gray-500 hover:text-gray-700 hover:bg-gray-100">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
                        </svg>
                    </button>
                    <span class="ml-2 text-xl font-bold text-gray-800">🏭 Gestión Fábrica</span>
                </div>
                <div class="flex items-center space-x-4">
                    <div class="text-sm text-gray-600">
                        <span class="font-medium">{{ auth()->user()->name }}</span>
                        <span class="ml-1 inline-flex items-center px-2 py-0.5 rounded text-xs font-medium {{ auth()->user()->isOwner() ? 'bg-blue-100 text-blue-800' : 'bg-green-100 text-green-800' }}">
                            {{ auth()->user()->role->label() }}
                        </span>
                    </div>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="text-sm text-gray-500 hover:text-gray-700 transition">
                            Salir
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </nav>

    <div class="flex">
        {{-- Sidebar --}}
        <aside class="w-64 min-h-[calc(100vh-4rem)] bg-white shadow-sm border-r border-gray-200 hidden md:block"
               :class="{ 'block': sidebarOpen, 'hidden': !sidebarOpen }"
               @class(['md:block'])>
            <nav class="mt-4 px-3 space-y-1">
                <a href="{{ route('dashboard') }}"
                   class="flex items-center px-3 py-2 text-sm font-medium rounded-md {{ request()->routeIs('dashboard') ? 'bg-gray-100 text-gray-900' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900' }}">
                    <svg class="mr-3 h-5 w-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
                    </svg>
                    Inicio
                </a>
                @can('owner-only')
                <a href="{{ route('products.index') }}"
                   class="flex items-center px-3 py-2 text-sm font-medium rounded-md {{ request()->routeIs('products.*') ? 'bg-gray-100 text-gray-900' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900' }}">
                    <svg class="mr-3 h-5 w-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
                    </svg>
                    Productos
                </a>
                @endcan
                <a href="{{ route('inventory.index') }}"
                   class="flex items-center px-3 py-2 text-sm font-medium rounded-md {{ request()->routeIs('inventory.*') ? 'bg-gray-100 text-gray-900' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900' }}">
                    <svg class="mr-3 h-5 w-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/>
                    </svg>
                    Inventario
                </a>
                @can('owner-only')
                <a href="{{ route('suppliers.index') }}"
                   class="flex items-center px-3 py-2 text-sm font-medium rounded-md {{ request()->routeIs('suppliers.*') ? 'bg-gray-100 text-gray-900' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900' }}">
                    <svg class="mr-3 h-5 w-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/>
                    </svg>
                    Proveedores
                </a>
                <a href="{{ route('purchases.index') }}"
                   class="flex items-center px-3 py-2 text-sm font-medium rounded-md {{ request()->routeIs('purchases.*') ? 'bg-gray-100 text-gray-900' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900' }}">
                    <svg class="mr-3 h-5 w-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"/>
                    </svg>
                    Compras
                </a>
                @endcan
                
                <div class="pt-4 pb-2">
                    <p class="px-3 text-xs font-semibold text-gray-500 uppercase tracking-wider">Producción</p>
                </div>
                <a href="{{ route('production.calculator') }}"
                   class="flex items-center px-3 py-2 text-sm font-medium rounded-md {{ request()->routeIs('production.*') ? 'bg-gray-100 text-gray-900' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900' }}">
                    <svg class="mr-3 h-5 w-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z"/>
                    </svg>
                    Calculadora
                </a>
            </nav>
        </aside>

        {{-- Main content --}}
        <main class="flex-1 p-6">
            {{ $slot }}
        </main>
    </div>

    @livewireScripts
</body>
</html>
