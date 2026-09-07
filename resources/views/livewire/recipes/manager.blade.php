<div>
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-800">
            Receta para: {{ $product->name }}
        </h1>
        <p class="text-sm text-gray-500 mb-2">Código: {{ $product->internal_code }} | Unidad: {{ $product->baseUnit->name }}</p>
        <a href="{{ route('products.index') }}" wire:navigate class="text-sm text-blue-600 hover:text-blue-800">
            ← Volver a productos
        </a>
    </div>

    @if (session()->has('message'))
        <div class="mb-4 p-3 bg-green-100 border border-green-300 text-green-800 rounded-md text-sm">
            {{ session('message') }}
        </div>
    @endif

    <form wire:submit="save" class="space-y-6">
        
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
            <h2 class="text-lg font-medium text-gray-900 mb-4 border-b pb-2">Rendimiento</h2>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <label class="block text-sm font-medium text-gray-700">Para producir (Cantidad base) *</label>
                    <div class="mt-1 flex rounded-md shadow-sm">
                        <input wire:model="yield_quantity" type="number" step="0.01" class="flex-1 block w-full rounded-none rounded-l-md border-gray-300 focus:border-blue-500 focus:ring-blue-500 text-sm px-3 py-2 border">
                        <span class="inline-flex items-center px-3 rounded-r-md border border-l-0 border-gray-300 bg-gray-50 text-gray-500 text-sm">
                            {{ $product->baseUnit->abbreviation }}
                        </span>
                    </div>
                    <p class="mt-1 text-xs text-gray-500">Ej: Si la receta es para 100 alfajores, poné 100.</p>
                    @error('yield_quantity') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700">Notas de Preparación</label>
                    <textarea wire:model="notes" rows="2" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 text-sm px-3 py-2 border"></textarea>
                    @error('notes') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
            </div>
        </div>

        <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
            <h2 class="text-lg font-medium text-gray-900 mb-4 border-b pb-2">Materiales / Insumos Requeridos</h2>
            
            @error('items')
                <div class="mb-4 p-3 bg-red-50 border border-red-200 text-red-700 rounded-md text-sm">
                    {{ $message }}
                </div>
            @enderror

            <div class="space-y-4">
                @foreach($items as $index => $item)
                    <div class="flex items-start space-x-4">
                        <div class="flex-1">
                            <label class="block text-xs font-medium text-gray-500 uppercase">Insumo</label>
                            <select wire:model.live="items.{{ $index }}.product_id" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 text-sm px-3 py-2 border">
                                <option value="">Seleccionar insumo...</option>
                                @foreach($ingredients as $ingredient)
                                    @if($ingredient->id !== $product->id)
                                        <option value="{{ $ingredient->id }}">{{ $ingredient->name }} (en {{ $ingredient->baseUnit->abbreviation }})</option>
                                    @endif
                                @endforeach
                            </select>
                            @error("items.{$index}.product_id") <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>

                        <div class="w-1/3">
                            <label class="block text-xs font-medium text-gray-500 uppercase">Cantidad</label>
                            <div class="mt-1 flex rounded-md shadow-sm">
                                <input wire:model="items.{{ $index }}.quantity_base" type="number" step="0.0001" class="flex-1 block w-full rounded-none rounded-l-md border-gray-300 focus:border-blue-500 text-sm px-3 py-2 border">
                                <span class="inline-flex items-center px-3 rounded-r-md border border-l-0 border-gray-300 bg-gray-50 text-gray-500 text-xs">
                                    @php
                                        $selected = $item['product_id'] ? $ingredients->firstWhere('id', $item['product_id']) : null;
                                    @endphp
                                    {{ $selected ? $selected->baseUnit->abbreviation : 'ud' }}
                                </span>
                            </div>
                            @error("items.{$index}.quantity_base") <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>

                        <div class="pt-6">
                            <button type="button" wire:click="removeItem({{ $index }})" class="text-red-500 hover:text-red-700 font-bold p-2" title="Eliminar insumo">
                                &times;
                            </button>
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="mt-4">
                <button type="button" wire:click="addItem" class="text-sm font-medium text-blue-600 hover:text-blue-800">
                    + Agregar insumo
                </button>
            </div>
        </div>

        <div class="flex justify-end">
            <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-md text-sm font-medium transition" wire:loading.attr="disabled">
                Guardar Receta
            </button>
        </div>
    </form>
</div>
