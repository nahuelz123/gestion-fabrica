<div>
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-800">Registrar Salida / Merma</h1>
        <a href="{{ route('inventory.index') }}" wire:navigate class="text-sm text-blue-600 hover:text-blue-800">
            ← Volver al inventario
        </a>
    </div>

    <form wire:submit="save" class="max-w-3xl space-y-6">
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-gray-700">Tipo de salida *</label>
                    <div class="mt-2 space-x-4">
                        <label class="inline-flex items-center">
                            <input type="radio" wire:model="exit_type" value="adjustment" class="text-blue-600 focus:ring-blue-500 border-gray-300">
                            <span class="ml-2 text-sm text-gray-700">Ajuste / Salida manual</span>
                        </label>
                        <label class="inline-flex items-center">
                            <input type="radio" wire:model="exit_type" value="waste" class="text-blue-600 focus:ring-blue-500 border-gray-300">
                            <span class="ml-2 text-sm text-gray-700">Merma (Desperdicio)</span>
                        </label>
                    </div>
                </div>

                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-gray-700">Producto *</label>
                    <select wire:model.live="product_id" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 text-sm px-3 py-2 border">
                        <option value="">Seleccionar producto...</option>
                        @foreach ($products as $p)
                            <option value="{{ $p->id }}">{{ $p->name }} ({{ $p->internal_code }})</option>
                        @endforeach
                    </select>
                    @error('product_id') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700">Depósito *</label>
                    <select wire:model.live="warehouse_id" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 text-sm px-3 py-2 border">
                        <option value="">Seleccionar depósito...</option>
                        @foreach ($warehouses as $w)
                            <option value="{{ $w->id }}">{{ $w->name }}</option>
                        @endforeach
                    </select>
                    @error('warehouse_id') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                
                @if ($this->selectedProduct && $this->selectedProduct->requires_lot)
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Lote disponible *</label>
                        <select wire:model="lot_id" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 text-sm px-3 py-2 border">
                            <option value="">Seleccionar lote...</option>
                            @foreach ($this->available_lots as $lot)
                                <option value="{{ $lot->id }}">{{ $lot->lot_code }} (Vence: {{ $lot->expiration_date ? $lot->expiration_date->format('d/m/Y') : 'N/A' }})</option>
                            @endforeach
                        </select>
                        @error('lot_id') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                        @if($this->available_lots->isEmpty() && $warehouse_id)
                            <p class="mt-1 text-xs text-red-500">No hay lotes con stock en este depósito.</p>
                        @endif
                    </div>
                @endif

                <div>
                    <label class="block text-sm font-medium text-gray-700">Presentación</label>
                    <select wire:model="presentation_id" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 text-sm px-3 py-2 border">
                        <option value="">Unidad Base ({{ $this->selectedProduct ? $this->selectedProduct->baseUnit->name : 'N/A' }})</option>
                        @if ($this->selectedProduct)
                            @foreach ($this->selectedProduct->presentations as $pres)
                                <option value="{{ $pres->id }}">{{ $pres->name }} (x{{ rtrim(rtrim($pres->conversion_factor, '0'), '.') }})</option>
                            @endforeach
                        @endif
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700">Cantidad *</label>
                    <input wire:model="quantity" type="number" step="0.01" min="0.01" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 text-sm px-3 py-2 border">
                    @error('quantity') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>

                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-gray-700">Razón / Notas</label>
                    <input wire:model="reason" type="text" placeholder="Ej: Mercadería vencida, rotura..." class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 text-sm px-3 py-2 border">
                </div>
            </div>
        </div>

        <div class="flex justify-end space-x-3">
            <button type="submit"
                    class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-md text-sm font-medium transition"
                    wire:loading.attr="disabled" wire:loading.class="opacity-50">
                Registrar Salida
            </button>
        </div>
    </form>
</div>
