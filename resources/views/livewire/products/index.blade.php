<div>
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-2xl font-bold text-gray-800">Productos</h1>
        <a href="{{ route('products.create') }}" wire:navigate
           class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-md text-sm font-medium transition">
            + Nuevo Producto
        </a>
    </div>

    @if (session()->has('message'))
        <div class="mb-4 p-3 bg-green-100 border border-green-300 text-green-800 rounded-md text-sm">
            {{ session('message') }}
        </div>
    @endif

    <div class="mb-4 flex flex-col sm:flex-row gap-3 sm:items-center sm:justify-between">
        <input wire:model.live.debounce.300ms="search" type="text" placeholder="Buscar por nombre, alias, código o código de barras..."
               class="w-full sm:w-96 rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 text-sm px-3 py-2 border">
        <select wire:model.live="perPage" class="rounded-md border-gray-300 text-sm px-3 py-2 border">
            <option value="25">25 por página</option>
            <option value="50">50 por página</option>
            <option value="100">100 por página</option>
        </select>
    </div>

    <div class="bg-white rounded-lg shadow-sm border border-gray-200 overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Código</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Nombre</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Tipo</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Presentación</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Estado</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Acciones</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                @forelse ($products as $product)
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3 text-sm font-mono text-gray-600">{{ $product->internal_code }}</td>
                        <td class="px-4 py-3 text-sm font-medium text-gray-900">
                            {{ $product->name }}
                            @if ($product->requires_lot)
                                <span class="ml-1 text-xs text-orange-600" title="Requiere lote">📦</span>
                            @endif
                            @if ($product->requires_expiration)
                                <span class="ml-1 text-xs text-red-600" title="Requiere vencimiento">📅</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-sm text-gray-600">
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium {{ $product->type->value === 'raw_material' ? 'bg-blue-100 text-blue-800' : 'bg-green-100 text-green-800' }}">
                                {{ $product->type->label() }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-sm text-gray-600">{{ $product->presentation }}</td>
                        <td class="px-4 py-3">
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium {{ $product->isActive() ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' }}">
                                {{ $product->status->label() }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-right text-sm font-medium space-x-2 whitespace-nowrap">
                            <a href="{{ route('recipes.manager', $product->id) }}" wire:navigate class="text-green-600 hover:text-green-900">Receta</a>
                            <a href="{{ route('products.edit', $product->id) }}" wire:navigate class="text-blue-600 hover:text-blue-900">Editar</a>
                            <button wire:click="delete({{ $product->id }})"
                                    wire:confirm="¿Estás seguro de eliminar '{{ $product->name }}'?"
                                    class="text-red-600 hover:text-red-800 font-medium">Eliminar</button>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-8 text-center text-gray-400">No se encontraron productos.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $products->links() }}
    </div>
</div>
