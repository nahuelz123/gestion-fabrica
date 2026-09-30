<div>
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-800">Producción</h1>
        <p class="text-sm text-gray-500">Simulá un pedido, comprobá el stock y registrá lo que realmente se produjo y consumió.</p>
        @if (auth()->user()->isManager())
            <p class="mt-2 text-sm text-blue-700 bg-blue-50 border border-blue-200 rounded-md px-3 py-2">
                Como encargado podés administrar stock, comprobar pedidos y confirmar la producción realizada. Las compras y la administración general siguen reservadas a los dueños.
            </p>
        @endif
    </div>

    @if ($successMessage)
        <div class="mb-6 p-4 bg-green-100 border border-green-300 text-green-800 rounded-lg whitespace-pre-line">
            {{ $successMessage }}
        </div>
    @endif

    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
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

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Carros</label>
                        <input wire:model="carros" type="number" step="0.01" min="0" placeholder="Ej. 3" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 text-sm px-3 py-2 border">
                        @error('carros') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Bandejas extra</label>
                        <input wire:model="bandejas" type="number" step="0.01" min="0" placeholder="Ej. 2" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 text-sm px-3 py-2 border">
                        @error('bandejas') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                </div>
                <p class="text-xs text-gray-500">1 carro = 12 bandejas = 288 hamburguesas. 1 bandeja = 24.</p>

                <div>
                    <label class="block text-sm font-medium text-gray-700">Unidades</label>
                    <input wire:model="target_quantity" type="number" step="0.01" min="0.01" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 text-sm px-3 py-2 border">
                    <p class="mt-1 text-xs text-gray-500">Si cargás carros o bandejas, el sistema calcula las unidades automáticamente.</p>
                    @error('target_quantity') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 disabled:opacity-50 text-white px-4 py-2 rounded-md text-sm font-medium transition" wire:loading.attr="disabled">
                    Comprobar producción
                </button>
            </form>
        </div>

        <div class="md:col-span-2">
            @if ($errorMessage)
                <div class="p-4 bg-red-50 border border-red-200 text-red-800 rounded-lg mb-4">
                    {{ $errorMessage }}
                </div>
            @endif

            @if ($result)
                <div class="bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden">
                    <div class="px-5 py-4 border-b border-gray-200 flex flex-col sm:flex-row sm:justify-between sm:items-center gap-2 {{ $result['can_produce'] ? 'bg-green-50' : 'bg-red-50' }}">
                        <h2 class="text-lg font-bold {{ $result['can_produce'] ? 'text-green-800' : 'text-red-800' }}">
                            {{ $result['can_produce'] ? '✅ Stock suficiente' : '❌ Stock insuficiente' }}
                        </h2>
                        <span class="text-sm text-gray-600">Para producir: <strong>{{ $target_quantity }}</strong> ud.</span>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Insumo</th>
                                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Requerido</th>
                                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">En stock</th>
                                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Diferencia</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200">
                                @foreach ($result['items'] as $item)
                                    @php
                                        $missing = $item['missing'] > 0;
                                        $unit = $item['ingredient']->baseUnit->abbreviation ?? 'u';
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
                </div>

                @if ($result['can_produce'])
                    @can('manage-production')
                        <div class="mt-6 bg-white rounded-lg shadow-sm border border-gray-200 p-6">
                            <h3 class="text-lg font-medium text-gray-900 mb-2">Confirmar producción realizada</h3>
                            <p class="text-sm text-gray-500 mb-4">
                                Los valores vienen precargados con la receta. Si realmente se usó una cantidad distinta de algún insumo, corregila antes de confirmar. Al confirmar se descuenta el consumo real y se suma el producto terminado.
                            </p>

                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-5">
                                @foreach ($result['items'] as $item)
                                    @php $unit = $item['ingredient']->baseUnit->abbreviation ?? 'u'; @endphp
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700">{{ $item['ingredient']->name }} usado</label>
                                        <div class="mt-1 flex rounded-md shadow-sm">
                                            <input wire:model="actual_consumptions.{{ $item['ingredient']->id }}" type="number" step="0.0001" min="0" class="block w-full rounded-l-md border-gray-300 focus:border-green-500 focus:ring-green-500 text-sm px-3 py-2 border">
                                            <span class="inline-flex items-center px-3 rounded-r-md border border-l-0 border-gray-300 bg-gray-50 text-gray-500 text-sm">{{ $unit }}</span>
                                        </div>
                                    </div>
                                @endforeach
                            </div>

                            <div class="flex justify-end">
                                <button wire:click="confirm" wire:loading.attr="disabled" class="bg-green-600 hover:bg-green-700 disabled:opacity-50 text-white px-6 py-2 rounded-md text-sm font-medium transition shadow-sm">
                                    Confirmar y registrar producción
                                </button>
                            </div>
                        </div>
                    @endcan
                @endif
            @else
                <div class="h-full flex flex-col items-center justify-center p-12 text-gray-400 bg-gray-50 rounded-lg border border-dashed border-gray-300">
                    <svg class="w-12 h-12 mb-4 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7v14a2 2 0 002 2z"></path>
                    </svg>
                    <p>Seleccioná un producto y una cantidad para comprobar el pedido.</p>
                </div>
            @endif
        </div>
    </div>
</div>
