<div>
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-2xl font-bold text-gray-800">Ajuste Manual de Inventario</h1>
        <a href="{{ route('inventory.index') }}" wire:navigate
           class="text-sm text-blue-600 hover:underline">← Ver inventario</a>
    </div>

    {{-- Search --}}
    <div class="mb-4">
        <input type="text" wire:model.live.debounce.300ms="search"
               placeholder="Buscar producto..."
               class="w-full border rounded-md px-4 py-2 text-sm focus:ring-2 focus:ring-blue-400">
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

        {{-- Product list --}}
        <div class="bg-white rounded-lg shadow overflow-hidden">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-gray-600 text-xs uppercase">
                    <tr>
                        <th class="px-4 py-3 text-left">Producto</th>
                        <th class="px-4 py-3 text-right">Stock actual</th>
                        <th class="px-4 py-3 text-center">Acción</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach($products as $product)
                    <tr class="hover:bg-gray-50 {{ $selectedProductId === $product->id ? 'bg-blue-50' : '' }}">
                        <td class="px-4 py-3 font-medium text-gray-800">{{ $product->name }}</td>
                        <td class="px-4 py-3 text-right text-gray-600">{{ number_format($product->current_stock, 0) }} u</td>
                        <td class="px-4 py-3 text-center">
                            <button wire:click="selectProduct({{ $product->id }})"
                                    class="text-blue-600 hover:text-blue-800 text-xs font-medium">
                                Ajustar
                            </button>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        {{-- Adjustment panel --}}
        @if($selectedProduct)
        <div class="bg-white rounded-lg shadow p-6">
            <h2 class="text-lg font-semibold text-gray-800 mb-1">{{ $selectedProduct->name }}</h2>
            <p class="text-sm text-gray-500 mb-4">Stock actual: <span class="font-bold text-gray-700">{{ number_format($selectedStock, 0) }} u</span></p>

            @if($successMessage)
                <div class="mb-4 p-3 bg-green-100 text-green-800 rounded text-sm">{{ $successMessage }}</div>
            @endif
            @if($errorMessage)
                <div class="mb-4 p-3 bg-red-100 text-red-800 rounded text-sm">{{ $errorMessage }}</div>
            @endif

            {{-- Mode selector --}}
            <div class="flex gap-2 mb-4">
                <button wire:click="$set('adjustMode','add')"
                        class="flex-1 py-2 text-sm rounded border {{ $adjustMode === 'add' ? 'bg-green-600 text-white border-green-600' : 'bg-white text-gray-700 border-gray-300' }}">
                    + Sumar
                </button>
                <button wire:click="$set('adjustMode','subtract')"
                        class="flex-1 py-2 text-sm rounded border {{ $adjustMode === 'subtract' ? 'bg-red-600 text-white border-red-600' : 'bg-white text-gray-700 border-gray-300' }}">
                    − Restar
                </button>
                <button wire:click="$set('adjustMode','set')"
                        class="flex-1 py-2 text-sm rounded border {{ $adjustMode === 'set' ? 'bg-blue-600 text-white border-blue-600' : 'bg-white text-gray-700 border-gray-300' }}">
                    = Establecer
                </button>
            </div>

            {{-- Presentation selector --}}
            @if($selectedProduct->presentations->count() > 0)
            <div class="mb-3">
                <label class="block text-xs text-gray-500 mb-1">Presentación (opcional)</label>
                <select wire:model="adjustPresentationId" class="w-full border rounded px-3 py-2 text-sm">
                    <option value="">Base (unidades)</option>
                    @foreach($selectedProduct->presentations as $pres)
                        <option value="{{ $pres->id }}">{{ $pres->name }} (× {{ $pres->conversion_factor }})</option>
                    @endforeach
                </select>
            </div>
            @endif

            {{-- Quantity input --}}
            <div class="mb-4">
                <label class="block text-xs text-gray-500 mb-1">
                    @if($adjustMode === 'set') Nuevo stock total
                    @elseif($adjustMode === 'subtract') Cantidad a restar
                    @else Cantidad a sumar
                    @endif
                </label>
                <input type="number" step="0.01" min="0.01" wire:model="adjustQty"
                       class="w-full border rounded px-3 py-2 text-sm focus:ring-2 focus:ring-blue-400"
                       placeholder="Ej: 12">
                @error('adjustQty') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>

            <button wire:click="applyAdjustment"
                    class="w-full py-2 bg-blue-600 hover:bg-blue-700 text-white rounded text-sm font-medium transition">
                Aplicar ajuste
            </button>
        </div>
        @else
        <div class="bg-white rounded-lg shadow p-6 flex items-center justify-center text-gray-400 text-sm">
            Seleccioná un producto de la lista para ajustar su stock.
        </div>
        @endif
    </div>
</div>
