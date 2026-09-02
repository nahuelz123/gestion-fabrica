<div>
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-800">Dashboard</h1>
        <p class="text-gray-500 mt-1">Bienvenido, {{ auth()->user()->name }}</p>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
        {{-- Info card --}}
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
            <h3 class="text-sm font-medium text-gray-500 uppercase tracking-wider">Tu cuenta</h3>
            <div class="mt-3 space-y-2">
                <p class="text-gray-800"><span class="font-medium">Nombre:</span> {{ auth()->user()->name }}</p>
                <p class="text-gray-800"><span class="font-medium">Email:</span> {{ auth()->user()->email ?? '—' }}</p>
                <p class="text-gray-800"><span class="font-medium">Teléfono:</span> {{ auth()->user()->phone ?? '—' }}</p>
                <p class="text-gray-800">
                    <span class="font-medium">Rol:</span>
                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium {{ auth()->user()->isOwner() ? 'bg-blue-100 text-blue-800' : 'bg-green-100 text-green-800' }}">
                        {{ auth()->user()->role->label() }}
                    </span>
                </p>
            </div>
        </div>

        {{-- Placeholder for future modules --}}
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 border-dashed p-6 flex items-center justify-center">
            <p class="text-gray-400 text-sm text-center">📦 Módulos próximos:<br>Productos, Inventario, Producción</p>
        </div>
    </div>
</div>
