<div>
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-800">Simulador de Producción</h1>
        <p class="text-sm text-gray-500">Calculá los insumos necesarios para fabricar un producto y verificá si hay stock suficiente.</p>
    </div>

    @if ($successMessage)
        <div class="mb-6 p-4 bg-green-100 border border-green-300 text-green-800 rounded-lg">
            {{ $successMessage }}
        </div>
    @endif

    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
        
        <!-- Formulario -->
        <div class="md:col-span-1 bg-white rounded-lg shadow-sm border border-gray-200 p-6 self-start">
            <form wire:submit="calculate" class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700">Producto a fabricar *</label>
                    <select wire:model="product_id" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 text-sm px-3 py-2 border">
                        <option value="">Seleccionar...</option>
                        @foreach($products as $p)
                            <option value="{{ $p->id }}">{{ $p->name }}</option>
                        @endforeach
                    </select>
                    @error('product_id') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700">Cantidad objetivo *</label>
                    <input wire:model="target_quantity" type="number" step="0.01" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 text-sm px-3 py-2 border">
                    @error('target_quantity') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                <div class="pt-2">
                    <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-md text-sm font-medium transition" wire:loading.attr="disabled">
                        Calcular Requerimientos
                    </button>
                </div>
            </form>
        </div>

        <!-- Resultados -->
        <div class="md:col-span-2">
            @if ($errorMessage)
                <div class="p-4 bg-red-50 border border-red-200 text-red-800 rounded-lg">
                    {{ $errorMessage }}
                </div>
            @elseif ($result)
                <div class="bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden">
                    <div class="px-5 py-4 border-b border-gray-200 flex justify-between items-center {{ $result['can_produce'] ? 'bg-green-50' : 'bg-red-50' }}">
                        <h2 class="text-lg font-bold {{ $result['can_produce'] ? 'text-green-800' : 'text-red-800' }}">
                            {{ $result['can_produce'] ? '✅ Stock Suficiente' : '❌ Stock Insuficiente' }}
                        </h2>
                        <span class="text-sm text-gray-600">
                            Para producir: <strong>{{ $target_quantity }}</strong> ud.
                        </span>
                    </div>

                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Insumo</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Requerido</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">En Stock</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Diferencia</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            @foreach ($result['items'] as $item)
                                @php 
                                    $missing = $item['missing'] > 0; 
                                    $unit = $item['ingredient']->baseUnit->abbreviation;
                                @endphp
                                <tr class="hover:bg-gray-50 {{ $missing ? 'bg-red-50/30' : '' }}">
                                    <td class="px-4 py-3 text-sm font-medium text-gray-900">
                                        {{ $item['ingredient']->name }}
                                        <div class="text-xs text-gray-500">{{ $item['ingredient']->internal_code }}</div>
                                    </td>
                                    <td class="px-4 py-3 text-right text-sm font-bold text-blue-600">
                                        {{ rtrim(rtrim(number_format($item['required'], 4, '.', ''), '0'), '.') }} {{ $unit }}
                                    </td>
                                    <td class="px-4 py-3 text-right text-sm text-gray-600">
                                        {{ rtrim(rtrim(number_format($item['available'], 4, '.', ''), '0'), '.') }} {{ $unit }}
                                    </td>
                                    <td class="px-4 py-3 text-right text-sm font-bold {{ $missing ? 'text-red-600' : 'text-green-600' }}">
                                        @if ($missing)
                                            -{{ rtrim(rtrim(number_format($item['missing'], 4, '.', ''), '0'), '.') }} {{ $unit }}
                                        @else
                                            OK
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <!-- SECCIÓN DE CONFIRMACIÓN (Solo si alcanza el stock) -->
                @if ($result['can_produce'])
                    @php 
                        $selectedProduct = $products->firstWhere('id', $product_id);
                    @endphp
                    <div class="mt-6 bg-white rounded-lg shadow-sm border border-gray-200 p-6">
                        <h3 class="text-lg font-medium text-gray-900 mb-4 border-b pb-2">Confirmar Producción</h3>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Depósito Origen/Destino *</label>
                                <select wire:model="warehouse_id" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 text-sm px-3 py-2 border">
                                    <option value="">Seleccionar depósito...</option>
                                    @foreach($warehouses as $warehouse)
                                        <option value="{{ $warehouse->id }}">{{ $warehouse->name }}</option>
                                    @endforeach
                                </select>
                                @error('warehouse_id') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                            </div>
                        </div>

                        @if ($selectedProduct && $selectedProduct->requires_lot)
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6 bg-orange-50 p-4 rounded-md border border-orange-200">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700">Código de Lote a generar *</label>
                                    <input wire:model="output_lot_code" type="text" class="mt-1 block w-full rounded-md border-orange-300 shadow-sm focus:border-orange-500 text-sm px-3 py-2 border">
                                    @error('output_lot_code') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                                </div>
                                
                                @if ($selectedProduct->requires_expiration)
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700">Fecha de Vencimiento *</label>
                                        <input wire:model="output_expiration_date" type="date" class="mt-1 block w-full rounded-md border-orange-300 shadow-sm focus:border-orange-500 text-sm px-3 py-2 border">
                                        @error('output_expiration_date') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                                    </div>
                                @endif
                            </div>
                        @endif

                        <div class="flex justify-end">
                            <button wire:click="confirm" wire:loading.attr="disabled"
                                    class="bg-green-600 hover:bg-green-700 text-white px-6 py-2 rounded-md text-sm font-medium transition shadow-sm">
                                Confirmar y Registrar Producción
                            </button>
                        </div>
                    </div>
                @endif
            @else
                <div class="h-full flex flex-col items-center justify-center p-12 text-gray-400 bg-gray-50 rounded-lg border border-dashed border-gray-300">
                    <svg class="w-12 h-12 mb-4 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z"></path>
                    </svg>
                    <p>Seleccioná un producto y la cantidad a fabricar para ver los requerimientos.</p>
                </div>
            @endif
        </div>
    </div>
</div>
