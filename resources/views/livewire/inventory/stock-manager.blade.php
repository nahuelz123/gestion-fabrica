<div>
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-900">Contar stock</h1>
        <p class="text-sm text-gray-500 mt-1">Buscá un producto, poné cuánto contaste y listo. El sistema guarda la diferencia automáticamente.</p>
    </div>

    <div class="mb-4 flex flex-col sm:flex-row gap-3">
        <input wire:model.live.debounce.300ms="search" placeholder="Buscar producto..." class="flex-1 border rounded-lg px-4 py-3 text-base">
        <select wire:model.live="perPage" class="border rounded-lg px-3 py-3 text-sm"><option value="25">25</option><option value="50">50</option><option value="100">100</option></select>
    </div>

    <div class="grid lg:grid-cols-2 gap-6">
        <div class="bg-white border rounded-xl overflow-hidden">
            <table class="w-full text-sm">
                <thead class="bg-gray-50"><tr><th class="p-3 text-left">Producto</th><th class="p-3 text-right">Sistema</th><th></th></tr></thead>
                <tbody>
                @forelse($products as $product)
                    <tr class="border-t hover:bg-gray-50">
                        <td class="p-3 font-medium">{{ $product->name }}<div class="text-xs text-gray-400">{{ $product->internal_code }}</div></td>
                        <td class="p-3 text-right">{{ number_format((float)($product->current_stock ?? 0),2,',','.') }} {{ $product->baseUnit->abbreviation ?? '' }}</td>
                        <td class="p-3 text-right"><button wire:click="selectProduct({{ $product->id }})" class="bg-gray-900 text-white rounded-lg px-3 py-2 text-xs">Contar</button></td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="p-8 text-center text-gray-400">No encontré productos.</td></tr>
                @endforelse
                </tbody>
            </table>
            <div class="p-3">{{ $products->links() }}</div>
        </div>

        @if($selectedProduct)
            <div class="bg-white border rounded-xl p-6 h-fit lg:sticky lg:top-4">
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

                @if($adjustMode === 'set')
                    <label class="block text-sm font-medium text-gray-700">¿Cuánto contaste realmente?</label>
                    <input wire:model="adjustQty" type="number" step="0.01" min="0.01" class="w-full border rounded-lg px-4 py-3 mt-1 text-lg" placeholder="Ej. 1250">
                    @error('adjustQty')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                    <button wire:click="applyAdjustment" wire:loading.attr="disabled" class="w-full bg-blue-600 hover:bg-blue-700 disabled:opacity-50 text-white rounded-lg py-3 mt-4 font-semibold">Guardar recuento</button>
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
