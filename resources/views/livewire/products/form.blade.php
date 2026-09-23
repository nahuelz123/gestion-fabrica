<div>
    <div class="mb-6 flex justify-between items-center">
        <div>
            <h1 class="text-2xl font-bold text-gray-800">
                {{ $productId ? 'Editar Producto' : 'Nuevo Producto' }}
            </h1>
            <a href="{{ route('products.index') }}" wire:navigate class="text-sm text-blue-600 hover:text-blue-800">
                ← Volver al listado
            </a>
        </div>
    </div>

    @if (session()->has('message'))
        <div class="mb-4 p-3 bg-green-100 border border-green-300 text-green-800 rounded-md text-sm">
            {{ session('message') }}
        </div>
    @endif

    <form wire:submit="save" class="space-y-6">
        
        {{-- Selector de Tipo --}}
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6 text-center">
            <h2 class="text-lg font-medium text-gray-700 mb-4">¿Qué vas a cargar?</h2>
            <div class="flex justify-center space-x-4">
                <button type="button" 
                        wire:click="$set('type', 'raw_material')"
                        class="px-6 py-3 rounded-lg border-2 transition-all font-semibold {{ $type === 'raw_material' ? 'border-blue-600 bg-blue-50 text-blue-700' : 'border-gray-200 text-gray-500 hover:border-gray-300' }}">
                    INSUMO
                </button>
                <button type="button" 
                        wire:click="$set('type', 'finished_product')"
                        class="px-6 py-3 rounded-lg border-2 transition-all font-semibold {{ $type === 'finished_product' ? 'border-green-600 bg-green-50 text-green-700' : 'border-gray-200 text-gray-500 hover:border-gray-300' }}">
                    PRODUCTO FINAL
                </button>
            </div>
        </div>

        {{-- Datos Básicos --}}
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <label class="block text-sm font-medium text-gray-700">Nombre *</label>
                    <input wire:model="name" type="text" placeholder="Ej: Papel manteca" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 px-4 py-2 border">
                    <p class="mt-1 text-xs text-gray-500">Nombre descriptivo del producto o insumo.</p>
                    @error('name') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700">Presentación *</label>
                    <input wire:model="presentation" type="text" placeholder="Ej: Paquete de 1000" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 px-4 py-2 border">
                    <p class="mt-1 text-xs text-gray-500">Cómo se empaqueta o cuenta. Ej: "Caja x 24", "Unidad", "Bolsa 5kg".</p>
                    @error('presentation') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>

                @if (!$productId)
                <div>
                    <label class="block text-sm font-medium text-gray-700">Stock Inicial (Opcional)</label>
                    <input wire:model="initial_stock" type="number" step="0.01" min="0" placeholder="Ej: 20" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 px-4 py-2 border">
                    <p class="mt-1 text-xs text-gray-500">Cantidad de presentaciones que tenés actualmente.</p>
                    @error('initial_stock') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                @endif
            </div>
        </div>

        {{-- Opciones Finales --}}
        @if ($type === 'finished_product')
        <div class="bg-green-50 rounded-lg shadow-sm border border-green-200 p-6">
            <h2 class="text-sm font-semibold text-green-800 mb-4 uppercase tracking-wider">Opciones de trazabilidad</h2>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <label class="block text-sm font-medium text-gray-700">Código de barras (Opcional)</label>
                    <input wire:model="barcode" type="text" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500 px-4 py-2 border">
                </div>

                <div class="space-y-4 pt-2">
                    <label class="flex items-center space-x-3">
                        <input wire:model.live="requires_lot" type="checkbox" class="rounded border-gray-300 text-green-600 focus:ring-green-500 w-5 h-5">
                        <span class="text-sm font-medium text-gray-700">Requiere Lote</span>
                    </label>

                    <label class="flex items-center space-x-3">
                        <input wire:model.live="requires_expiration" type="checkbox" class="rounded border-gray-300 text-green-600 focus:ring-green-500 w-5 h-5">
                        <span class="text-sm font-medium text-gray-700">Requiere Vencimiento</span>
                    </label>

                    @if ($requires_expiration)
                        <div class="pl-8 flex items-center space-x-2">
                            <label class="text-sm text-gray-700">Vida útil (días):</label>
                            <input wire:model="shelf_life_days" type="number" min="1" class="w-24 rounded-md border-gray-300 shadow-sm focus:border-green-500 px-3 py-1 border">
                        </div>
                    @endif
                </div>
            </div>
        </div>
        @endif

        {{-- Submit --}}
        <div class="flex justify-end space-x-4 pt-4">
            <a href="{{ route('products.index') }}" wire:navigate
               class="px-6 py-3 border border-gray-300 rounded-lg text-sm font-medium text-gray-700 hover:bg-gray-50 transition">
                Cancelar
            </a>
            <button type="submit"
                    class="bg-blue-600 hover:bg-blue-700 text-white px-8 py-3 rounded-lg text-sm font-medium shadow-sm transition"
                    wire:loading.attr="disabled" wire:loading.class="opacity-50">
                {{ $productId ? 'Guardar Cambios' : 'Guardar Producto' }}
            </button>
        </div>
    </form>
</div>
