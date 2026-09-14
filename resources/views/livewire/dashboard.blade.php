<div>
    {{-- BANNER DE ALERTAS DE STOCK --}}
    @if ($alerts->isNotEmpty())
        <div class="mb-6 bg-red-50 border border-red-200 rounded-lg p-4">
            <div class="flex items-start">
                <svg class="h-5 w-5 text-red-500 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                </svg>
                <div class="ml-3 flex-1">
                    <h3 class="text-sm font-semibold text-red-800">
                        {{ $alerts->count() }} {{ $alerts->count() === 1 ? 'producto' : 'productos' }} con stock crítico
                    </h3>
                    <div class="mt-2 space-y-1">
                        @foreach ($alerts as $product)
                            <div class="flex items-center justify-between text-sm">
                                <span class="text-red-700 font-medium">{{ $product->name }}</span>
                                <span class="ml-4 flex items-center gap-2 text-right">
                                    <span class="text-gray-600">
                                        {{ rtrim(rtrim($product->total_stock ?? '0', '0'), '.') }}
                                        {{ $product->baseUnit->abbreviation ?? '' }}
                                    </span>
                                    @if ($product->total_stock == 0)
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-bold bg-red-100 text-red-800 uppercase">Agotado</span>
                                    @else
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-orange-100 text-orange-800">⚠ Bajo stock</span>
                                    @endif
                                </span>
                            </div>
                        @endforeach
                    </div>
                    <div class="mt-3">
                        <a href="{{ route('inventory.index') }}" wire:navigate
                           class="text-sm font-medium text-red-700 hover:text-red-900 underline">
                            Ver inventario completo →
                        </a>
                    </div>
                </div>
            </div>
        </div>
    @endif

    {{-- ENCABEZADO --}}
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-800">Bienvenido, {{ auth()->user()->name }}</h1>
        <p class="text-gray-500 mt-1 text-sm">¿Qué necesitás hacer hoy?</p>
    </div>

    {{-- TARJETAS DE ACCIONES OPERATIVAS (todos los roles) --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">

        {{-- Ver stock --}}
        <a href="{{ route('inventory.index') }}" wire:navigate
           class="group bg-white rounded-xl border border-gray-200 shadow-sm p-6 hover:shadow-md hover:border-blue-300 transition-all duration-150 flex items-start gap-4">
            <div class="flex-shrink-0 h-12 w-12 bg-blue-50 group-hover:bg-blue-100 rounded-lg flex items-center justify-center transition">
                <svg class="h-6 w-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/>
                </svg>
            </div>
            <div>
                <h2 class="text-sm font-semibold text-gray-900 group-hover:text-blue-700">Ver stock actual</h2>
                <p class="text-xs text-gray-500 mt-1">Consultá las cantidades disponibles en todos los depósitos.</p>
            </div>
        </a>

        {{-- Registrar entrada --}}
        <a href="{{ route('inventory.entry') }}" wire:navigate
           class="group bg-white rounded-xl border border-gray-200 shadow-sm p-6 hover:shadow-md hover:border-green-300 transition-all duration-150 flex items-start gap-4">
            <div class="flex-shrink-0 h-12 w-12 bg-green-50 group-hover:bg-green-100 rounded-lg flex items-center justify-center transition">
                <svg class="h-6 w-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                </svg>
            </div>
            <div>
                <h2 class="text-sm font-semibold text-gray-900 group-hover:text-green-700">Registrar entrada</h2>
                <p class="text-xs text-gray-500 mt-1">Ingresá mercadería o insumos recibidos al inventario.</p>
            </div>
        </a>

        {{-- Registrar salida / merma --}}
        <a href="{{ route('inventory.exit') }}" wire:navigate
           class="group bg-white rounded-xl border border-gray-200 shadow-sm p-6 hover:shadow-md hover:border-red-300 transition-all duration-150 flex items-start gap-4">
            <div class="flex-shrink-0 h-12 w-12 bg-red-50 group-hover:bg-red-100 rounded-lg flex items-center justify-center transition">
                <svg class="h-6 w-6 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 12H4"/>
                </svg>
            </div>
            <div>
                <h2 class="text-sm font-semibold text-gray-900 group-hover:text-red-700">Registrar salida / merma</h2>
                <p class="text-xs text-gray-500 mt-1">Registrá consumos, ajustes de inventario o desperdicios.</p>
            </div>
        </a>

        {{-- Calculadora de producción --}}
        <a href="{{ route('production.calculator') }}" wire:navigate
           class="group bg-white rounded-xl border border-gray-200 shadow-sm p-6 hover:shadow-md hover:border-purple-300 transition-all duration-150 flex items-start gap-4">
            <div class="flex-shrink-0 h-12 w-12 bg-purple-50 group-hover:bg-purple-100 rounded-lg flex items-center justify-center transition">
                <svg class="h-6 w-6 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z"/>
                </svg>
            </div>
            <div>
                <h2 class="text-sm font-semibold text-gray-900 group-hover:text-purple-700">¿Alcanza para producir?</h2>
                <p class="text-xs text-gray-500 mt-1">Simulá si el stock actual alcanza para producir una cantidad dada.</p>
            </div>
        </a>

        {{-- Nueva compra --}}
        <a href="{{ route('purchases.create') }}" wire:navigate
           class="group bg-white rounded-xl border border-gray-200 shadow-sm p-6 hover:shadow-md hover:border-amber-300 transition-all duration-150 flex items-start gap-4">
            <div class="flex-shrink-0 h-12 w-12 bg-amber-50 group-hover:bg-amber-100 rounded-lg flex items-center justify-center transition">
                <svg class="h-6 w-6 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"/>
                </svg>
            </div>
            <div>
                <h2 class="text-sm font-semibold text-gray-900 group-hover:text-amber-700">Nueva compra</h2>
                <p class="text-xs text-gray-500 mt-1">Cargá una orden de compra a un proveedor.</p>
            </div>
        </a>

    </div>

    {{-- SECCIÓN DE CONFIGURACIÓN (solo owner) --}}
    @if (auth()->user()->isOwner())
        <div class="mt-10">
            <div class="flex items-center gap-3 mb-4">
                <div class="h-px flex-1 bg-gray-200"></div>
                <span class="text-xs font-semibold text-gray-400 uppercase tracking-wider">Configuración</span>
                <div class="h-px flex-1 bg-gray-200"></div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">

                {{-- Proveedores --}}
                <a href="{{ route('suppliers.index') }}" wire:navigate
                   class="group bg-gray-50 rounded-xl border border-gray-200 p-4 hover:bg-white hover:shadow-sm hover:border-gray-300 transition-all duration-150 flex items-center gap-3">
                    <div class="flex-shrink-0 h-9 w-9 bg-gray-100 group-hover:bg-gray-200 rounded-lg flex items-center justify-center transition">
                        <svg class="h-5 w-5 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/>
                        </svg>
                    </div>
                    <div>
                        <span class="text-sm font-medium text-gray-700 group-hover:text-gray-900">Proveedores</span>
                        <p class="text-xs text-gray-400">Administrar proveedores</p>
                    </div>
                </a>

                {{-- Catálogo de Productos --}}
                <a href="{{ route('products.index') }}" wire:navigate
                   class="group bg-gray-50 rounded-xl border border-gray-200 p-4 hover:bg-white hover:shadow-sm hover:border-gray-300 transition-all duration-150 flex items-center gap-3">
                    <div class="flex-shrink-0 h-9 w-9 bg-gray-100 group-hover:bg-gray-200 rounded-lg flex items-center justify-center transition">
                        <svg class="h-5 w-5 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
                        </svg>
                    </div>
                    <div>
                        <span class="text-sm font-medium text-gray-700 group-hover:text-gray-900">Catálogo de Productos</span>
                        <p class="text-xs text-gray-400">Crear, editar y gestionar productos</p>
                    </div>
                </a>

            </div>
        </div>
    @endif
</div>
