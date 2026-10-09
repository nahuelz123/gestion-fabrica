<div>
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-900">Contar stock</h1>
        <p class="text-sm text-gray-500 mt-1">Buscá un producto, poné cuánto contaste y listo. El sistema guarda la diferencia automáticamente.</p>
    </div>

    @if($attentionOnly)
        <div class="mb-4 rounded-xl border border-red-200 bg-red-50 p-4 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
            <div>
                <div class="font-semibold text-red-800">⚠️ Mostrando sólo productos que necesitan atención</div>
                <div class="text-sm text-red-700 mt-1">Podés seleccionar cualquiera y corregir el stock directamente.</div>
            </div>
            <button wire:click="$set('attentionOnly', false)" class="text-sm font-medium text-red-700 underline">Ver todos</button>
        </div>
    @endif

    <div class="mb-4 flex flex-col sm:flex-row gap-3">
        <input wire:model.live.debounce.300ms="search" placeholder="Buscar producto..." class="flex-1 border rounded-lg px-4 py-3 text-base">
        <select wire:model.live="perPage" class="border rounded-lg px-3 py-3 text-sm"><option value="25">25</option><option value="50">50</option><option value="100">100</option></select>
    </div>

    <div class="grid lg:grid-cols-2 gap-6">
        <div class="bg-white border rounded-xl overflow-hidden">
            <div class="hidden sm:block">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50"><tr><th class="p-3 text-left">Producto</th><th class="p-3 text-right">Sistema</th><th></th></tr></thead>
                    <tbody>
                    @forelse($products as $product)
                        <tr class="border-t hover:bg-gray-50">
                            <td class="p-3 font-medium">
                                {{ $product->name }}
                                <div class="text-xs text-gray-400">{{ $product->internal_code }}</div>
                                @if((float)($product->current_stock ?? 0) <= 0 || ($product->min_stock !== null && (float)$product->min_stock > 0 && (float)($product->current_stock ?? 0) <= (float)$product->min_stock))
                                    <div class="text-xs text-red-600 font-semibold mt-1">
                                        ⚠️ {{ (float)($product->current_stock ?? 0) <= 0 ? 'Sin stock' : 'Stock bajo' }}
                                        @if($product->min_stock !== null && (float)$product->min_stock > 0)
                                            · mínimo {{ rtrim(rtrim(number_format((float)$product->min_stock, 2, '.', ''), '0'), '.') }} {{ $product->baseUnit->abbreviation ?? '' }}
                                        @endif
                                    </div>
                                @endif
                            </td>
                            <td class="p-3 text-right {{ ((float)($product->current_stock ?? 0) <= 0 || ($product->min_stock !== null && (float)$product->min_stock > 0 && (float)($product->current_stock ?? 0) <= (float)$product->min_stock)) ? 'text-red-700 font-bold' : '' }}">{{ number_format((float)($product->current_stock ?? 0),2,',','.') }} {{ $product->baseUnit->abbreviation ?? '' }}</td>
                            <td class="p-3 text-right">
                                <button
                                    type="button"
                                    wire:click="selectProduct({{ $product->id }})"
                                    wire:loading.attr="disabled"
                                    wire:target="selectProduct({{ $product->id }})"
                                    class="bg-gray-900 text-white rounded-lg px-3 py-2 text-xs disabled:opacity-50">
                                    Contar
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="p-8 text-center text-gray-400">No encontré productos.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>

            <div class="sm:hidden divide-y divide-gray-100">
                @forelse($products as $product)
                    @php
                        $needsAttention = (float)($product->current_stock ?? 0) <= 0
                            || ($product->min_stock !== null && (float)$product->min_stock > 0 && (float)($product->current_stock ?? 0) <= (float)$product->min_stock);
                    @endphp
                    <div class="p-4">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <div class="font-semibold text-gray-900 break-words">{{ $product->name }}</div>
                                <div class="text-xs text-gray-400 mt-0.5">{{ $product->internal_code }}</div>
                                @if($needsAttention)
                                    <div class="text-xs text-red-600 font-semibold mt-1">
                                        ⚠️ {{ (float)($product->current_stock ?? 0) <= 0 ? 'Sin stock' : 'Stock bajo' }}
                                        @if($product->min_stock !== null && (float)$product->min_stock > 0)
                                            · mínimo {{ rtrim(rtrim(number_format((float)$product->min_stock, 2, '.', ''), '0'), '.') }} {{ $product->baseUnit->abbreviation ?? '' }}
                                        @endif
                                    </div>
                                @endif
                            </div>
                            <div class="shrink-0 text-right">
                                <div class="text-xs text-gray-500">Sistema</div>
                                <div class="font-bold {{ $needsAttention ? 'text-red-700' : 'text-gray-900' }}">
                                    {{ number_format((float)($product->current_stock ?? 0),2,',','.') }} {{ $product->baseUnit->abbreviation ?? '' }}
                                </div>
                            </div>
                        </div>

                        <button
                            type="button"
                            wire:click="selectProduct({{ $product->id }})"
                            wire:loading.attr="disabled"
                            wire:target="selectProduct({{ $product->id }})"
                            class="mt-4 w-full min-h-12 rounded-xl bg-gray-900 px-4 py-3 text-sm font-semibold text-white active:bg-gray-700 disabled:opacity-50 touch-manipulation">
                            <span wire:loading.remove wire:target="selectProduct({{ $product->id }})">Contar stock real</span>
                            <span wire:loading wire:target="selectProduct({{ $product->id }})">Abriendo…</span>
                        </button>
                    </div>
                @empty
                    <div class="p-8 text-center text-gray-400">No encontré productos.</div>
                @endforelse
            </div>

            <div class="p-3">{{ $products->links() }}</div>
        </div>

        @if($selectedProduct)
            <div id="stock-recount-panel" class="bg-white border rounded-xl p-4 sm:p-6 h-fit lg:sticky lg:top-4" x-data x-init="if (window.innerWidth < 1024) { setTimeout(() => $el.scrollIntoView({ behavior: 'smooth', block: 'start' }), 80) }">
                <div class="mb-5">
                    <p class="text-xs text-gray-400 uppercase font-semibold">Producto</p>
                    <h2 class="text-xl font-bold text-gray-900">{{ $selectedProduct->name }}</h2>
                </div>

                @if($warehouses->count() > 1)
                    <label class="block text-sm font-medium text-gray-700">Depósito</label>
                    <select wire:model.live="warehouse_id" class="w-full border rounded-lg px-3 py-3 mt-1 mb-4">@foreach($warehouses as $w)<option value="{{ $w->id }}">{{ $w->name }}</option>@endforeach</select>
                @endif

                <div class="bg-gray-50 rounded-xl p-4 mb-5">
                    <p class="text-xs text-gray-500">El sistema dice</p>
                    <p class="text-2xl font-bold text-gray-900 mt-1">{{ $selectedStock === null ? '—' : number_format($selectedStock,2,',','.') }} {{ $selectedProduct->baseUnit->abbreviation ?? 'u' }}</p>
                </div>

                @if($successMessage)<div class="mb-4 p-3 rounded-lg bg-green-50 border border-green-200 text-green-800 text-sm">{{ $successMessage }}</div>@endif
                @if($errorMessage)<div class="mb-4 p-3 rounded-lg bg-red-50 border border-red-200 text-red-800 text-sm">{{ $errorMessage }}</div>@endif

                @if($selectedProduct->requires_lot)
                    <div class="mb-4 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                        Este producto se controla por lote. Para no perder la trazabilidad, las correcciones se hacen indicando el lote.
                    </div>

                    <div class="border-t pt-4">
                        <div class="flex items-center justify-between mb-3">
                            <h3 class="font-medium text-gray-900">Corrección por lote</h3>
                        </div>

                        <div class="grid grid-cols-2 gap-2 mb-3">
                            <button wire:click="$set('adjustMode','add')" class="border rounded-lg px-3 py-2 {{ $adjustMode === 'add' ? 'bg-green-50 border-green-300' : '' }}">+ Sumar</button>
                            <button wire:click="$set('adjustMode','subtract')" class="border rounded-lg px-3 py-2 {{ $adjustMode === 'subtract' ? 'bg-red-50 border-red-300' : '' }}">− Restar</button>
                        </div>

                        @if($adjustMode === 'subtract')
                            <label class="block text-sm font-medium text-gray-700">Lote *</label>
                            <select wire:model="lot_id" class="w-full border rounded-lg px-3 py-3 mt-1 mb-1">
                                <option value="">Elegir lote...</option>
                                @foreach($availableLots as $stockRow)
                                    <option value="{{ $stockRow->lot_id }}">
                                        {{ $stockRow->lot->lot_code }}
                                        · {{ rtrim(rtrim(number_format((float)$stockRow->quantity, 2, '.', ''), '0'), '.') }} {{ $selectedProduct->baseUnit->abbreviation ?? 'u' }}
                                        @if($stockRow->lot->expiration_date)
                                            · vence {{ $stockRow->lot->expiration_date->format('d/m/Y') }}
                                        @endif
                                    </option>
                                @endforeach
                            </select>
                            @error('lot_id')<p class="text-xs text-red-600 mb-3">{{ $message }}</p>@enderror
                            @if($availableLots->isEmpty())
                                <p class="text-xs text-red-600 mb-3">No hay lotes con stock disponible para descontar.</p>
                            @endif
                        @else
                            <div class="grid sm:grid-cols-2 gap-3 mb-3">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700">Código de lote *</label>
                                    <input wire:model="lot_code" class="w-full border rounded-lg px-3 py-3 mt-1" placeholder="Ej. AJUSTE-20261007">
                                    @error('lot_code')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                                </div>
                                @if($selectedProduct->requires_expiration)
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700">Vencimiento {{ $selectedProduct->shelf_life_days ? '(opcional)' : '*' }}</label>
                                        <input wire:model="expiration_date" type="date" class="w-full border rounded-lg px-3 py-3 mt-1">
                                        @if($selectedProduct->shelf_life_days)
                                            <p class="text-xs text-gray-500 mt-1">Si lo dejás vacío, se calcula con la vida útil configurada.</p>
                                        @endif
                                        @error('expiration_date')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                                    </div>
                                @endif
                            </div>
                        @endif

                        @if($selectedProduct->presentations->isNotEmpty())
                            <select wire:model="adjustPresentationId" class="w-full border rounded-lg px-3 py-3 mb-3">
                                <option value="">Unidad base</option>
                                @foreach($selectedProduct->presentations as $p)<option value="{{ $p->id }}">{{ $p->name }}</option>@endforeach
                            </select>
                        @endif

                        <input wire:model="adjustQty" type="number" step="0.01" min="0.01" class="w-full border rounded-lg px-4 py-3" placeholder="Cantidad">
                        @error('adjustQty')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror

                        <button wire:click="applyAdjustment" wire:loading.attr="disabled" class="w-full bg-gray-900 text-white rounded-lg py-3 mt-4 font-semibold disabled:opacity-50">Aplicar corrección</button>
                    </div>
                @elseif($adjustMode === 'set')
                    <label class="block text-sm font-medium text-gray-700">¿Cuánto contaste realmente?</label>
                    <input wire:model="adjustQty" type="number" inputmode="decimal" step="0.01" min="0" class="w-full border rounded-lg px-4 py-4 mt-1 text-lg" placeholder="Ej. 1250">
                    @error('adjustQty')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                    <button type="button" wire:click="applyAdjustment" wire:loading.attr="disabled" wire:target="applyAdjustment" class="w-full min-h-12 bg-blue-600 hover:bg-blue-700 active:bg-blue-800 disabled:opacity-50 text-white rounded-xl py-3 mt-4 font-semibold touch-manipulation">
                        <span wire:loading.remove wire:target="applyAdjustment">Guardar recuento</span>
                        <span wire:loading wire:target="applyAdjustment">Guardando…</span>
                    </button>
                    <button wire:click="$set('adjustMode','add')" class="w-full text-sm text-gray-500 hover:text-gray-700 mt-4">Necesito hacer otra corrección →</button>
                @else
                    <div class="border-t pt-4">
                        <div class="flex items-center justify-between mb-3"><h3 class="font-medium text-gray-900">Corrección manual</h3><button wire:click="$set('adjustMode','set')" class="text-xs text-blue-600">Volver al recuento</button></div>
                        <div class="grid grid-cols-2 gap-2 mb-3">
                            <button wire:click="$set('adjustMode','add')" class="border rounded-lg px-3 py-2 {{ $adjustMode === 'add' ? 'bg-green-50 border-green-300' : '' }}">+ Sumar</button>
                            <button wire:click="$set('adjustMode','subtract')" class="border rounded-lg px-3 py-2 {{ $adjustMode === 'subtract' ? 'bg-red-50 border-red-300' : '' }}">− Restar</button>
                        </div>
                        @if($selectedProduct->presentations->isNotEmpty())
                            <select wire:model="adjustPresentationId" class="w-full border rounded-lg px-3 py-3 mb-3"><option value="">Unidad base</option>@foreach($selectedProduct->presentations as $p)<option value="{{ $p->id }}">{{ $p->name }}</option>@endforeach</select>
                        @endif
                        <input wire:model="adjustQty" type="number" step="0.01" min="0.01" class="w-full border rounded-lg px-4 py-3" placeholder="Cantidad">
                        <button wire:click="applyAdjustment" wire:loading.attr="disabled" class="w-full bg-gray-900 text-white rounded-lg py-3 mt-4 font-semibold">Aplicar corrección</button>
                    </div>
                @endif
            </div>
        @else
            <div class="bg-white border border-dashed rounded-xl p-10 text-center text-gray-400 h-fit">Elegí un producto para contar.</div>
        @endif
    </div>
</div>
