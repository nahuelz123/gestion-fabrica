<div>
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-800">
            {{ $productId ? 'Editar Producto' : 'Nuevo Producto' }}
        </h1>
        <a href="{{ route('products.index') }}" wire:navigate class="text-sm text-blue-600 hover:text-blue-800">
            ← Volver al listado
        </a>
    </div>

    @if (session()->has('message'))
        <div class="mb-4 p-3 bg-green-100 border border-green-300 text-green-800 rounded-md text-sm">
            {{ session('message') }}
        </div>
    @endif

    <form wire:submit="save" class="space-y-6">
        {{-- Product basic info --}}
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
            <h2 class="text-lg font-semibold text-gray-700 mb-4">Datos del producto</h2>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700">Nombre *</label>
                    <input wire:model="name" type="text" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 text-sm px-3 py-2 border">
                    @error('name') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700">Código interno *</label>
                    <input wire:model="internal_code" type="text" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 text-sm px-3 py-2 border">
                    @error('internal_code') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700">Categoría *</label>
                    <select wire:model="category_id" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 text-sm px-3 py-2 border">
                        <option value="">Seleccionar...</option>
                        @foreach ($categories as $cat)
                            <option value="{{ $cat->id }}">{{ $cat->name }}</option>
                        @endforeach
                    </select>
                    @error('category_id') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700">Unidad base *</label>
                    <select wire:model="base_unit_id" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 text-sm px-3 py-2 border">
                        <option value="">Seleccionar...</option>
                        @foreach ($units as $unit)
                            <option value="{{ $unit->id }}">{{ $unit->name }} ({{ $unit->abbreviation }})</option>
                        @endforeach
                    </select>
                    @error('base_unit_id') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700">Código de barras</label>
                    <input wire:model="barcode" type="text" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 text-sm px-3 py-2 border">
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700">Estado</label>
                    <select wire:model="status" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 text-sm px-3 py-2 border">
                        <option value="active">Activo</option>
                        <option value="inactive">Inactivo</option>
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700">Costo *</label>
                    <input wire:model="cost" type="number" step="0.01" min="0" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 text-sm px-3 py-2 border">
                    @error('cost') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700">Precio *</label>
                    <input wire:model="price" type="number" step="0.01" min="0" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 text-sm px-3 py-2 border">
                    @error('price') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700">Stock mínimo</label>
                    <input wire:model="min_stock" type="number" step="0.01" min="0" placeholder="Sin mínimo" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 text-sm px-3 py-2 border">
                    @error('min_stock') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
            </div>

            {{-- Lot/expiration toggles --}}
            <div class="mt-4 flex flex-wrap gap-6">
                <label class="flex items-center space-x-2">
                    <input wire:model.live="requires_lot" type="checkbox" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                    <span class="text-sm text-gray-700">Requiere lote</span>
                </label>

                <label class="flex items-center space-x-2">
                    <input wire:model.live="requires_expiration" type="checkbox" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                    <span class="text-sm text-gray-700">Requiere vencimiento</span>
                </label>

                @if ($requires_expiration)
                    <div class="flex items-center space-x-2">
                        <label class="text-sm text-gray-700">Vida útil (días):</label>
                        <input wire:model="shelf_life_days" type="number" min="1" class="w-24 rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 text-sm px-2 py-1 border">
                    </div>
                @endif
            </div>
        </div>

        {{-- Presentations --}}
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
            <h2 class="text-lg font-semibold text-gray-700 mb-4">Presentaciones</h2>
            <p class="text-sm text-gray-500 mb-4">
                Define cómo se empaqueta el producto. Ej: "Caja x50" con factor 50 significa que 1 caja = 50 unidades base.
            </p>

            {{-- Existing presentations list --}}
            @if (count($presentations) > 0)
                <div class="mb-4 space-y-2">
                    @foreach ($presentations as $index => $pres)
                        <div class="flex items-center justify-between bg-gray-50 rounded-md px-4 py-2 {{ $editingPresentationIndex === $index ? 'ring-2 ring-blue-300' : '' }}">
                            <div class="text-sm">
                                <span class="font-medium">{{ $pres['name'] }}</span>
                                <span class="text-gray-500 ml-2">× {{ rtrim(rtrim($pres['conversion_factor'], '0'), '.') }} unidades base</span>
                                @if ($pres['barcode'])
                                    <span class="text-gray-400 ml-2">| {{ $pres['barcode'] }}</span>
                                @endif
                                @if ($pres['is_purchase_default'])
                                    <span class="ml-2 text-xs bg-blue-100 text-blue-700 px-1 rounded">Compra</span>
                                @endif
                                @if ($pres['is_sale_default'])
                                    <span class="ml-1 text-xs bg-green-100 text-green-700 px-1 rounded">Venta</span>
                                @endif
                            </div>
                            <div class="flex space-x-2">
                                <button type="button" wire:click="editPresentation({{ $index }})" class="text-blue-600 hover:text-blue-800 text-sm">Editar</button>
                                <button type="button" wire:click="removePresentation({{ $index }})"
                                        wire:confirm="¿Eliminar esta presentación?"
                                        class="text-red-600 hover:text-red-800 text-sm">Eliminar</button>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif

            {{-- Add/edit presentation form --}}
            <div class="border border-gray-200 rounded-md p-4 bg-gray-50">
                <h3 class="text-sm font-medium text-gray-600 mb-3">
                    {{ $editingPresentationIndex !== null ? 'Editar presentación' : 'Agregar presentación' }}
                </h3>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                    <div>
                        <label class="block text-xs font-medium text-gray-600">Nombre *</label>
                        <input wire:model="pres_name" type="text" placeholder="Ej: Caja x50" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 text-sm px-3 py-2 border">
                        @error('pres_name') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600">Factor de conversión *</label>
                        <input wire:model="pres_conversion_factor" type="number" step="0.0001" min="0.0001" placeholder="Ej: 50" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 text-sm px-3 py-2 border">
                        @error('pres_conversion_factor') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600">Código de barras</label>
                        <input wire:model="pres_barcode" type="text" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 text-sm px-3 py-2 border">
                    </div>
                </div>
                <div class="mt-3 flex flex-wrap items-center gap-4">
                    <label class="flex items-center space-x-2">
                        <input wire:model="pres_is_purchase_default" type="checkbox" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                        <span class="text-sm text-gray-700">Default compra</span>
                    </label>
                    <label class="flex items-center space-x-2">
                        <input wire:model="pres_is_sale_default" type="checkbox" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                        <span class="text-sm text-gray-700">Default venta</span>
                    </label>
                    <div class="flex-1"></div>
                    @if ($editingPresentationIndex !== null)
                        <button type="button" wire:click="cancelEditPresentation" class="text-sm text-gray-500 hover:text-gray-700">Cancelar</button>
                    @endif
                    <button type="button" wire:click="addPresentation"
                            class="bg-gray-600 hover:bg-gray-700 text-white px-3 py-1.5 rounded-md text-sm font-medium transition">
                        {{ $editingPresentationIndex !== null ? 'Guardar cambio' : '+ Agregar' }}
                    </button>
                </div>
            </div>
        </div>

        {{-- Submit --}}
        <div class="flex justify-end space-x-3">
            <a href="{{ route('products.index') }}" wire:navigate
               class="px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 hover:bg-gray-50 transition">
                Cancelar
            </a>
            <button type="submit"
                    class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-md text-sm font-medium transition"
                    wire:loading.attr="disabled" wire:loading.class="opacity-50">
                {{ $productId ? 'Guardar cambios' : 'Crear producto' }}
            </button>
        </div>
    </form>
</div>
