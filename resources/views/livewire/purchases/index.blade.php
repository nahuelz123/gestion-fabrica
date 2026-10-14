<div>
    <div class="flex flex-col sm:flex-row sm:justify-between sm:items-center gap-3 mb-6">
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

    
    <div class="space-y-3 sm:hidden">
        @forelse($purchases as $purchase)
            <article class="rounded-xl border bg-white p-4 shadow-sm">
                <div class="flex items-start justify-between gap-2">
                    <div class="min-w-0">
                        <h2 class="font-semibold text-gray-900">Compra #{{ $purchase->id }}</h2>
                        <p class="mt-1 break-words text-sm text-gray-600">{{ $purchase->supplier->name }}</p>
                    </div>
                    <span class="shrink-0 rounded-full px-2 py-1 text-xs font-semibold {{ $purchase->status->value === 'confirmed' ? 'bg-green-100 text-green-800' : ($purchase->status->value === 'draft' ? 'bg-gray-100 text-gray-800' : 'bg-red-100 text-red-800') }}">
                        {{ $purchase->status->value === 'confirmed' ? 'Confirmada' : ($purchase->status->value === 'draft' ? 'Borrador' : 'Cancelada') }}
                    </span>
                </div>
                <p class="mt-3 text-sm text-gray-500">{{ $purchase->purchase_date->format('d/m/Y') }} · {{ $purchase->warehouse->name }}</p>
                <p class="mt-1 text-sm text-gray-500">Comprobante: {{ $purchase->invoice_number ?: '—' }}</p>
                <a href="{{ route('purchases.show', $purchase->id) }}" wire:navigate class="mt-4 flex min-h-11 items-center justify-center rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white">Ver / procesar</a>
            </article>
        @empty
            <div class="rounded-xl border bg-white p-6 text-center text-sm text-gray-500">No hay compras registradas.</div>
        @endforelse
    </div>
    <div data-table-scroll tabindex="0" role="region" aria-label="Compras detalladas" class="hidden sm:block bg-white rounded-lg shadow-sm border border-gray-200 overflow-x-auto">
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
