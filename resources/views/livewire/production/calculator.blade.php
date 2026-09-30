<div>
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-900">Producción</h1>
        <p class="text-sm text-gray-500 mt-1">Elegí el producto, cargá carros o bandejas y confirmá. El sistema calcula y descuenta los insumos.</p>
    </div>

    @if ($successMessage)
        <div class="mb-6 p-4 bg-green-50 border border-green-200 text-green-800 rounded-xl whitespace-pre-line">{{ $successMessage }}</div>
    @endif
    @if ($errorMessage)
        <div class="mb-6 p-4 bg-red-50 border border-red-200 text-red-800 rounded-xl">{{ $errorMessage }}</div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-5 gap-6">
        <div class="lg:col-span-2 bg-white rounded-xl border border-gray-200 p-6 h-fit">
            <form wire:submit="calculate" class="space-y-5">
                <div>
                    <label class="block text-sm font-medium text-gray-700">¿Qué hicieron?</label>
                    <select wire:model="product_id" class="mt-1 block w-full rounded-lg border-gray-300 text-base px-3 py-3 border">
                        <option value="">Elegir producto...</option>
                        @foreach($products as $p)<option value="{{ $p->id }}">{{ $p->name }}</option>@endforeach
                    </select>
                    @error('product_id') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">¿Cuánto?</label>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <span class="text-xs text-gray-500">Carros</span>
                            <input wire:model="carros" type="number" step="0.01" min="0" placeholder="0" class="mt-1 block w-full rounded-lg border-gray-300 text-lg px-4 py-3 border">
                        </div>
                        <div>
                            <span class="text-xs text-gray-500">Bandejas extra</span>
                            <input wire:model="bandejas" type="number" step="0.01" min="0" placeholder="0" class="mt-1 block w-full rounded-lg border-gray-300 text-lg px-4 py-3 border">
                        </div>
                    </div>
                    <p class="text-xs text-gray-400 mt-2">1 carro = 12 bandejas = 288 hamburguesas · 1 bandeja = 24.</p>
                    @error('carros') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    @error('bandejas') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                <details class="text-sm">
                    <summary class="cursor-pointer text-gray-500">Quiero cargar unidades directamente</summary>
                    <div class="mt-3">
                        <input wire:model="target_quantity" type="number" step="0.01" min="0.01" class="block w-full rounded-lg border-gray-300 px-4 py-3 border" placeholder="Unidades">
                        <p class="text-xs text-gray-400 mt-1">Usalo sólo si no vas a cargar carros ni bandejas.</p>
                    </div>
                </details>

                <button type="submit" wire:loading.attr="disabled" class="w-full bg-blue-600 hover:bg-blue-700 disabled:opacity-50 text-white px-4 py-3 rounded-lg font-semibold">Comprobar</button>
            </form>
        </div>

        <div class="lg:col-span-3">
            @if ($result)
                <div class="rounded-xl border overflow-hidden bg-white">
                    <div class="p-5 {{ $result['can_produce'] ? 'bg-green-50 border-green-200' : 'bg-red-50 border-red-200' }}">
                        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
                            <div>
                                <h2 class="text-xl font-bold {{ $result['can_produce'] ? 'text-green-800' : 'text-red-800' }}">{{ $result['can_produce'] ? '✅ Se puede hacer' : '❌ No alcanza el stock' }}</h2>
                                <p class="text-sm text-gray-600 mt-1">Cantidad: <strong>{{ $target_quantity }}</strong> hamburguesas</p>
                            </div>
                        </div>
                    </div>

                    <div class="divide-y">
                        @foreach ($result['items'] as $item)
                            @php
                                $missing = $item['missing'] > 0;
                                $unit = $item['ingredient']->baseUnit->abbreviation ?? 'u';
                            @endphp
                            <div class="p-4 flex items-center justify-between gap-4 {{ $missing ? 'bg-red-50/40' : '' }}">
                                <div>
                                    <div class="font-medium text-gray-900">{{ $item['ingredient']->name }}</div>
                                    <div class="text-xs text-gray-400">Hay {{ rtrim(rtrim(number_format($item['available'], 4, '.', ''), '0'), '.') }} {{ $unit }}</div>
                                </div>
                                <div class="text-right">
                                    <div class="font-semibold {{ $missing ? 'text-red-600' : 'text-gray-900' }}">Necesita {{ rtrim(rtrim(number_format($item['required'], 4, '.', ''), '0'), '.') }} {{ $unit }}</div>
                                    @if($missing)<div class="text-xs text-red-600">Faltan {{ rtrim(rtrim(number_format($item['missing'], 4, '.', ''), '0'), '.') }} {{ $unit }}</div>@else<div class="text-xs text-green-600">OK</div>@endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>

                @if ($result['can_produce'])
                    <div class="mt-5 bg-white rounded-xl border border-gray-200 p-6">
                        <h3 class="font-semibold text-gray-900">Registrar lo que hicieron</h3>
                        <p class="text-sm text-gray-500 mt-1">Si usaron lo normal, no tenés que tocar nada: confirmá y listo.</p>

                        <details class="mt-4 border rounded-lg p-4">
                            <summary class="cursor-pointer text-sm font-medium text-gray-700">✏️ Algún insumo se usó en una cantidad distinta</summary>
                            <p class="text-xs text-gray-500 mt-2 mb-3">Modificá solamente lo que haya sido diferente. Los valores ya vienen cargados según la receta.</p>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                @foreach ($result['items'] as $item)
                                    @php $unit = $item['ingredient']->baseUnit->abbreviation ?? 'u'; @endphp
                                    <div>
                                        <label class="block text-xs font-medium text-gray-600">{{ $item['ingredient']->name }}</label>
                                        <div class="mt-1 flex">
                                            <input wire:model="actual_consumptions.{{ $item['ingredient']->id }}" type="number" step="0.0001" min="0" class="block w-full rounded-l-lg border-gray-300 px-3 py-2 border">
                                            <span class="inline-flex items-center px-3 rounded-r-lg border border-l-0 bg-gray-50 text-gray-500 text-sm">{{ $unit }}</span>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </details>

                        <button wire:click="confirm" wire:loading.attr="disabled" class="w-full mt-5 bg-green-600 hover:bg-green-700 disabled:opacity-50 text-white px-6 py-3 rounded-lg font-semibold">✅ Confirmar producción</button>
                    </div>
                @endif
            @else
                <div class="bg-gray-50 border border-dashed rounded-xl p-12 text-center text-gray-400">
                    <div class="text-4xl mb-3">🏭</div>
                    <p class="font-medium">Cargá el producto y la cantidad.</p>
                    <p class="text-sm mt-1">Te digo enseguida si alcanza el stock.</p>
                </div>
            @endif
        </div>
    </div>
</div>
