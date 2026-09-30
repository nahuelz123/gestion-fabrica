<div>
    @if($alerts->isNotEmpty())
        <div class="mb-6 bg-red-50 border border-red-200 rounded-xl p-4">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                <div>
                    <h3 class="text-sm font-semibold text-red-800">⚠️ {{ $alerts->count() }} {{ $alerts->count() === 1 ? 'producto necesita atención' : 'productos necesitan atención' }}</h3>
                    <p class="text-xs text-red-700 mt-1">Hay stock en cero o por debajo del mínimo configurado.</p>
                </div>
                <a href="{{ route('inventory.index') }}" wire:navigate class="text-sm font-medium text-red-700 underline">Revisar stock →</a>
            </div>
        </div>
    @endif

    <div class="mb-7">
        <h1 class="text-2xl font-bold text-gray-900">Hola, {{ auth()->user()->name }}</h1>
        <p class="text-gray-500 mt-1 text-sm">Elegí lo que necesitás hacer. El sistema se ocupa del resto.</p>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4">
        <a href="{{ route('production.calculator') }}" wire:navigate class="bg-white rounded-2xl border border-gray-200 p-6 hover:shadow-md transition">
            <div class="text-3xl">🏭</div>
            <h2 class="mt-3 font-semibold text-gray-900">Producción</h2>
            <p class="text-sm text-gray-500 mt-1">Comprobá un pedido y registrá los carros o bandejas hechos.</p>
        </a>

        <a href="{{ route('inventory.adjust') }}" wire:navigate class="bg-white rounded-2xl border border-gray-200 p-6 hover:shadow-md transition">
            <div class="text-3xl">📦</div>
            <h2 class="mt-3 font-semibold text-gray-900">Contar stock</h2>
            <p class="text-sm text-gray-500 mt-1">Poné lo que contaste y el sistema registra la diferencia.</p>
        </a>

        <a href="{{ route('inventory.index') }}" wire:navigate class="bg-white rounded-2xl border border-gray-200 p-6 hover:shadow-md transition">
            <div class="text-3xl">🔎</div>
            <h2 class="mt-3 font-semibold text-gray-900">Consultar stock</h2>
            <p class="text-sm text-gray-500 mt-1">Buscá cualquier producto y mirá cuánto queda.</p>
        </a>

        <a href="{{ route('inventory.movements') }}" wire:navigate class="bg-white rounded-2xl border border-gray-200 p-6 hover:shadow-md transition">
            <div class="text-3xl">🧾</div>
            <h2 class="mt-3 font-semibold text-gray-900">Movimientos</h2>
            <p class="text-sm text-gray-500 mt-1">Revisá qué cambió, cuándo y quién lo hizo.</p>
        </a>
    </div>

    @if(auth()->user()->isOwner())
        <div class="mt-10">
            <div class="flex items-center justify-between mb-4">
                <div>
                    <h2 class="text-lg font-semibold text-gray-900">Gestión del negocio</h2>
                    <p class="text-sm text-gray-500">Lo administrativo queda separado de la operación diaria.</p>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                <a href="{{ route('purchases.create') }}" wire:navigate class="bg-blue-50 border border-blue-100 rounded-xl p-5 hover:shadow-sm transition">
                    <div class="font-semibold text-blue-900">🛒 Registrar compra</div>
                    <p class="text-xs text-blue-700 mt-1">Ingresá mercadería comprada.</p>
                </a>
                <a href="{{ route('products.index') }}" wire:navigate class="bg-gray-50 border rounded-xl p-5 hover:shadow-sm transition">
                    <div class="font-semibold text-gray-900">📦 Productos y recetas</div>
                    <p class="text-xs text-gray-500 mt-1">Configuración del catálogo.</p>
                </a>
                <a href="{{ route('suppliers.index') }}" wire:navigate class="bg-gray-50 border rounded-xl p-5 hover:shadow-sm transition">
                    <div class="font-semibold text-gray-900">👥 Proveedores</div>
                    <p class="text-xs text-gray-500 mt-1">Datos de compra y contacto.</p>
                </a>
                <a href="{{ route('vending.index') }}" wire:navigate class="bg-gray-50 border rounded-xl p-5 hover:shadow-sm transition">
                    <div class="font-semibold text-gray-900">🏪 Máquinas</div>
                    <p class="text-xs text-gray-500 mt-1">Ventas, kioscos y Mercado Pago.</p>
                </a>
            </div>
        </div>
    @endif
</div>
