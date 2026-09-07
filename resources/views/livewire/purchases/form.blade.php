<div>
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-800">Nueva Compra</h1>
        <a href="{{ route('purchases.index') }}" wire:navigate class="text-sm text-blue-600 hover:text-blue-800">
            ← Volver a compras
        </a>
    </div>

    <form wire:submit="save" class="space-y-6">
        <!-- Cabecera -->
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
            <h2 class="text-lg font-medium text-gray-900 mb-4 border-b pb-2">Datos Principales</h2>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <div>
                    <label class="block text-sm font-medium text-gray-700">Proveedor *</label>
                    <select wire:model="supplier_id" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 text-sm px-3 py-2 border">
                        <option value="">Seleccionar...</option>
                        @foreach($suppliers as $supplier)
                            <option value="{{ $supplier->id }}">{{ $supplier->name }}</option>
                        @endforeach
                    </select>
                    @error('supplier_id') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">Depósito Destino *</label>
                    <select wire:model="warehouse_id" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 text-sm px-3 py-2 border">
                        <option value="">Seleccionar...</option>
                        @foreach($warehouses as $warehouse)
                            <option value="{{ $warehouse->id }}">{{ $warehouse->name }}</option>
                        @endforeach
                    </select>
                    @error('warehouse_id') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">Fecha *</label>
                    <input wire:model="purchase_date" type="date" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 text-sm px-3 py-2 border">
                    @error('purchase_date') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">N° Factura / Remito</label>
                    <input wire:model="invoice_number" type="text" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 text-sm px-3 py-2 border">
                </div>
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-gray-700">Notas / Observaciones</label>
                    <input wire:model="notes" type="text" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 text-sm px-3 py-2 border">
                </div>
            </div>
        </div>

        <!-- Ítems -->
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
            <h2 class="text-lg font-medium text-gray-900 mb-4 border-b pb-2">Ítems de Compra</h2>
            
            <div class="space-y-4">
                @foreach($items as $index => $item)
                    <div class="p-4 bg-gray-50 border border-gray-200 rounded-md">
                        <div class="grid grid-cols-1 md:grid-cols-5 gap-4">
                            <!-- Producto -->
                            <div class="md:col-span-2">
                                <label class="block text-xs font-medium text-gray-500 uppercase">Producto</label>
                                <select wire:model.live="items.{{ $index }}.product_id" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 text-sm px-3 py-2 border">
                                    <option value="">Seleccionar...</option>
                                    @foreach($products as $p)
                                        <option value="{{ $p->id }}">{{ $p->name }} ({{ $p->internal_code }})</option>
                                    @endforeach
                                </select>
                                @error("items.{$index}.product_id") <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                            </div>

                            <!-- Presentación -->
                            <div>
                                <label class="block text-xs font-medium text-gray-500 uppercase">Presentación</label>
                                @php
                                    $selectedProduct = $item['product_id'] ? $products->firstWhere('id', $item['product_id']) : null;
                                @endphp
                                <select wire:model="items.{{ $index }}.presentation_id" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 text-sm px-3 py-2 border">
                                    <option value="">Unidad Base ({{ $selectedProduct ? $selectedProduct->baseUnit->name : '—' }})</option>
                                    @if($selectedProduct)
                                        @foreach($selectedProduct->presentations as $pres)
                                            <option value="{{ $pres->id }}">{{ $pres->name }} (x{{ rtrim(rtrim($pres->conversion_factor, '0'), '.') }})</option>
                                        @endforeach
                                    @endif
                                </select>
                            </div>

                            <!-- Cantidad -->
                            <div>
                                <label class="block text-xs font-medium text-gray-500 uppercase">Cantidad</label>
                                <input wire:model="items.{{ $index }}.quantity" type="number" step="0.01" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 text-sm px-3 py-2 border">
                                @error("items.{$index}.quantity") <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                            </div>

                            <!-- Costo Unitario -->
                            <div>
                                <label class="block text-xs font-medium text-gray-500 uppercase">Costo Unitario ($)</label>
                                <div class="flex items-center space-x-2">
                                    <input wire:model="items.{{ $index }}.unit_cost" type="number" step="0.01" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 text-sm px-3 py-2 border">
                                    @if(count($items) > 1)
                                        <button type="button" wire:click="removeItem({{ $index }})" class="mt-1 text-red-500 hover:text-red-700 font-bold" title="Eliminar ítem">
                                            &times;
                                        </button>
                                    @endif
                                </div>
                                @error("items.{$index}.unit_cost") <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                            </div>
                        </div>

                        <!-- Fila de Lote (Condicional) -->
                        @if($selectedProduct && $selectedProduct->requires_lot)
                            <div class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-4 bg-orange-50 p-3 rounded border border-orange-200">
                                <div>
                                    <label class="block text-xs font-medium text-gray-700 uppercase">Código de Lote *</label>
                                    <input wire:model="items.{{ $index }}.lot_code" type="text" class="mt-1 block w-full rounded-md border-orange-300 shadow-sm focus:border-orange-500 text-sm px-3 py-2 border">
                                    @error("items.{$index}.lot_code") <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                                </div>
                                @if($selectedProduct->requires_expiration)
                                    <div>
                                        <label class="block text-xs font-medium text-gray-700 uppercase">Vencimiento *</label>
                                        <input wire:model="items.{{ $index }}.expiration_date" type="date" class="mt-1 block w-full rounded-md border-orange-300 shadow-sm focus:border-orange-500 text-sm px-3 py-2 border">
                                        @error("items.{$index}.expiration_date") <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                                    </div>
                                @endif
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>

            <div class="mt-4">
                <button type="button" wire:click="addItem" class="text-sm font-medium text-blue-600 hover:text-blue-800">
                    + Agregar otro ítem
                </button>
            </div>
            @error('items') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>

        <div class="flex justify-end pt-4 border-t border-gray-100">
            <button type="submit" class="bg-green-600 hover:bg-green-700 text-white px-6 py-2 rounded-md text-sm font-medium transition" wire:loading.attr="disabled">
                Guardar Borrador
            </button>
        </div>
    </form>
</div>
