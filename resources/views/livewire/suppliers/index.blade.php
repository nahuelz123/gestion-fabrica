<div>
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-2xl font-bold text-gray-800">Proveedores</h1>
        <a href="{{ route('suppliers.create') }}" wire:navigate class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-md text-sm font-medium transition">
            + Nuevo Proveedor
        </a>
    </div>

    @if (session()->has('message'))
        <div class="mb-4 p-3 bg-green-100 border border-green-300 text-green-800 rounded-md text-sm">
            {{ session('message') }}
        </div>
    @endif

    <div class="mb-4">
        <input wire:model.live.debounce.300ms="search" type="text" placeholder="Buscar por nombre o CUIT..."
               class="w-full sm:w-96 rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 text-sm px-3 py-2 border">
    </div>

    <div class="bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Nombre</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">CUIT/RUT</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Contacto</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Estado</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Acciones</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                @forelse ($suppliers as $supplier)
                    <tr class="hover:bg-gray-50 {{ $supplier->status === 'inactive' ? 'opacity-60' : '' }}">
                        <td class="px-4 py-3 text-sm font-medium text-gray-900">{{ $supplier->name }}</td>
                        <td class="px-4 py-3 text-sm text-gray-600">{{ $supplier->tax_id ?? '—' }}</td>
                        <td class="px-4 py-3 text-sm text-gray-600">
                            {{ $supplier->email ?? '' }} <br>
                            {{ $supplier->phone ?? '' }}
                        </td>
                        <td class="px-4 py-3 text-center text-sm">
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium {{ $supplier->status === 'active' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' }}">
                                {{ $supplier->status === 'active' ? 'Activo' : 'Inactivo' }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-right text-sm space-x-2">
                            <a href="{{ route('suppliers.edit', $supplier->id) }}" wire:navigate class="text-blue-600 hover:text-blue-900">Editar</a>
                            <button wire:click="toggleStatus({{ $supplier->id }})" class="{{ $supplier->status === 'active' ? 'text-red-600 hover:text-red-900' : 'text-green-600 hover:text-green-900' }}">
                                {{ $supplier->status === 'active' ? 'Desactivar' : 'Activar' }}
                            </button>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-8 text-center text-gray-400">
                            No hay proveedores registrados.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
