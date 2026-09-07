<div>
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-2xl font-bold text-gray-800">Inventario Actual</h1>
        <div class="space-x-2">
            <a href="{{ route('inventory.entry') }}" wire:navigate
               class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-md text-sm font-medium transition">
                + Entrada
            </a>
            <a href="{{ route('inventory.exit') }}" wire:navigate
               class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-md text-sm font-medium transition">
                - Salida / Merma
            </a>
        </div>
    </div>

    @if (session()->has('message'))
        <div class="mb-4 p-3 bg-blue-100 border border-blue-300 text-blue-800 rounded-md text-sm">
            {{ session('message') }}
        </div>
    @endif

    <div class="mb-4">
        <input wire:model.live.debounce.300ms="search" type="text" placeholder="Buscar por producto o código..."
               class="w-full sm:w-96 rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 text-sm px-3 py-2 border">
    </div>

    <div class="bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Producto</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Depósito</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Lote</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Vencimiento</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Cantidad</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                @forelse ($stocks as $stock)
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3 text-sm font-medium text-gray-900">
                            {{ $stock->product->name }}
                            <span class="text-xs text-gray-500 ml-1">({{ $stock->product->internal_code }})</span>
                        </td>
                        <td class="px-4 py-3 text-sm text-gray-600">{{ $stock->warehouse->name }}</td>
                        <td class="px-4 py-3 text-sm text-gray-600">
                            {{ $stock->lot ? $stock->lot->lot_code : '—' }}
                        </td>
                        <td class="px-4 py-3 text-sm text-gray-600">
                            @if ($stock->lot && $stock->lot->expiration_date)
                                <span class="{{ $stock->lot->expiration_date->isPast() ? 'text-red-600 font-bold' : '' }}">
                                    {{ $stock->lot->expiration_date->format('d/m/Y') }}
                                </span>
                            @else
                                —
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right text-sm">
                            <span class="font-bold {{ $stock->quantity == 0 ? 'text-red-600' : ($stock->product->min_stock !== null && $stock->quantity <= $stock->product->min_stock ? 'text-orange-600' : 'text-gray-900') }}">
                                {{ rtrim(rtrim($stock->quantity, '0'), '.') }}
                            </span>
                            <span class="text-gray-500 ml-1">{{ $stock->product->baseUnit->abbreviation }}</span>
                            
                            @if ($stock->quantity == 0)
                                <span class="ml-2 inline-flex items-center px-2 py-0.5 rounded text-xs font-bold bg-red-100 text-red-800 uppercase">
                                    Agotado
                                </span>
                            @elseif ($stock->product->min_stock !== null && $stock->quantity <= $stock->product->min_stock)
                                <span class="ml-2 inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-orange-100 text-orange-800" title="Bajo stock mínimo ({{ $stock->product->min_stock }})">
                                    ⚠️ Bajo
                                </span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-8 text-center text-gray-400">
                            No hay stock disponible.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
