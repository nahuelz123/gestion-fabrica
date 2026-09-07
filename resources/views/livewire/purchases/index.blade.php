<div>
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-2xl font-bold text-gray-800">Compras</h1>
        <a href="{{ route('purchases.create') }}" wire:navigate class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-md text-sm font-medium transition">
            + Nueva Compra
        </a>
    </div>

    @if (session()->has('message'))
        <div class="mb-4 p-3 bg-green-100 border border-green-300 text-green-800 rounded-md text-sm">
            {{ session('message') }}
        </div>
    @endif

    <div class="mb-4">
        <input wire:model.live.debounce.300ms="search" type="text" placeholder="Buscar por ID, N° Factura o Proveedor..."
               class="w-full sm:w-96 rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 text-sm px-3 py-2 border">
    </div>

    <div class="bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">ID</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Fecha</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Proveedor</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Comprobante</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Depósito</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Estado</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Acciones</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                @forelse ($purchases as $purchase)
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3 text-sm font-bold text-gray-900">#{{ $purchase->id }}</td>
                        <td class="px-4 py-3 text-sm text-gray-600">{{ $purchase->purchase_date->format('d/m/Y') }}</td>
                        <td class="px-4 py-3 text-sm text-gray-900">{{ $purchase->supplier->name }}</td>
                        <td class="px-4 py-3 text-sm text-gray-600">{{ $purchase->invoice_number ?? '—' }}</td>
                        <td class="px-4 py-3 text-sm text-gray-600">{{ $purchase->warehouse->name }}</td>
                        <td class="px-4 py-3 text-center text-sm">
                            @if ($purchase->status->value === 'draft')
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-800">Borrador</span>
                            @elseif ($purchase->status->value === 'confirmed')
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-green-100 text-green-800">Confirmada</span>
                            @else
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-red-100 text-red-800">Cancelada</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right text-sm">
                            <a href="{{ route('purchases.show', $purchase->id) }}" wire:navigate class="text-blue-600 hover:text-blue-900">Ver / Procesar</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-4 py-8 text-center text-gray-400">
                            No hay compras registradas.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
