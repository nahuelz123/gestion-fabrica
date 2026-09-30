<div class="max-w-3xl space-y-6">
    <div>
        <h1 class="text-2xl font-bold text-gray-900">{{ $machineId ? 'Editar máquina' : 'Nueva máquina' }}</h1>
        <p class="text-sm text-gray-500 mt-1">Elegí comercio, hamburguesa, precio y stock. El resto lo hace el sistema.</p>
        <a href="{{ route('vending.index') }}" wire:navigate class="inline-block mt-2 text-sm text-red-700">← Volver</a>
    </div>

    @if (session()->has('message'))
        <div class="p-4 bg-yellow-50 border border-yellow-200 text-yellow-900 rounded-xl text-sm">{{ session('message') }}</div>
    @endif
    @if (session()->has('error'))
        <div class="p-4 bg-red-50 border border-red-200 text-red-800 rounded-xl text-sm">{{ session('error') }}</div>
    @endif

    <form wire:submit="save" class="space-y-5">
        <div class="bg-white border border-gray-200 rounded-2xl p-5 sm:p-6 space-y-5">
            <div>
                <div class="flex items-center justify-between gap-3 mb-1">
                    <label class="text-sm font-semibold text-gray-800">Comercio *</label>
                    <a href="{{ route('vending.partners.create', ['return' => 'machine']) }}" wire:navigate class="text-sm font-semibold text-red-700">+ Crear comercio</a>
                </div>

                @if($partnerCount > 20)
                    <input wire:model.live.debounce.300ms="partnerSearch" placeholder="Buscar comercio..." class="mb-2 w-full border rounded-lg px-3 py-2.5 text-sm">
                @endif

                <select wire:model.live="vending_partner_id" class="w-full border rounded-lg px-3 py-3 text-base">
                    <option value="">Elegir comercio...</option>
                    @foreach($partners as $partner)
                        <option value="{{ $partner->id }}">{{ $partner->name }}</option>
                    @endforeach
                </select>
                @error('vending_partner_id') <p class="text-sm text-red-600 mt-1">{{ $message }}</p> @enderror

                @if($selectedPartner)
                    <div class="mt-2 text-xs {{ $selectedPartner->hasMercadoPagoConnection() ? 'text-green-700' : 'text-amber-700' }}">
                        {{ $selectedPartner->hasMercadoPagoConnection() ? '✅ Mercado Pago vinculado' : '⚠️ Falta vincular Mercado Pago. Al guardar te llevo al paso de vinculación.' }}
                    </div>
                @endif
            </div>

            <div>
                <label class="text-sm font-semibold text-gray-800">Hamburguesa *</label>
                @if($productCount > 20)
                    <input wire:model.live.debounce.300ms="productSearch" placeholder="Buscar hamburguesa..." class="mt-1 mb-2 w-full border rounded-lg px-3 py-2.5 text-sm">
                @endif
                <select wire:model="product_id" class="mt-1 w-full border rounded-lg px-3 py-3 text-base">
                    <option value="">Elegir hamburguesa...</option>
                    @foreach($products as $product)
                        <option value="{{ $product->id }}">{{ $product->name }}</option>
                    @endforeach
                </select>
                @error('product_id') <p class="text-sm text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="text-sm font-semibold text-gray-800">Precio de venta *</label>
                    <div class="relative mt-1">
                        <span class="absolute left-3 top-3 text-gray-500">$</span>
                        <input wire:model="sale_price" type="number" step="0.01" min="1" placeholder="8500" class="w-full border rounded-lg pl-7 pr-3 py-3 text-base">
                    </div>
                    @error('sale_price') <p class="text-sm text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="text-sm font-semibold text-gray-800">Hamburguesas cargadas *</label>
                    <input wire:model="loaded_units" type="number" min="0" placeholder="40" class="mt-1 w-full border rounded-lg px-3 py-3 text-base">
                    <p class="text-xs text-gray-500 mt-1">Cada venta aprobada descuenta 1 automáticamente.</p>
                    @error('loaded_units') <p class="text-sm text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>
            </div>

            <details class="border-t pt-4">
                <summary class="cursor-pointer text-sm font-medium text-gray-600">Opciones avanzadas</summary>
                <p class="text-xs text-gray-500 mt-2">Normalmente no necesitás tocar nada acá.</p>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mt-4">
                    <div>
                        <label class="text-sm text-gray-600">Capacidad máxima</label>
                        <input wire:model="capacity" type="number" min="1" class="mt-1 w-full border rounded-lg px-3 py-2.5">
                        @error('capacity') <p class="text-sm text-red-600 mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="text-sm text-gray-600">Ubicación dentro del comercio</label>
                        <input wire:model="location" placeholder="Ej: al lado de la caja" class="mt-1 w-full border rounded-lg px-3 py-2.5">
                    </div>
                    <div>
                        <label class="text-sm text-gray-600">Código</label>
                        <input wire:model="code" placeholder="Se genera solo" class="mt-1 w-full border rounded-lg px-3 py-2.5">
                        <p class="text-xs text-gray-400 mt-1">Si lo dejás vacío se genera automáticamente.</p>
                        @error('code') <p class="text-sm text-red-600 mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="text-sm text-gray-600">Nombre interno</label>
                        <input wire:model="name" placeholder="Se genera solo" class="mt-1 w-full border rounded-lg px-3 py-2.5">
                        <p class="text-xs text-gray-400 mt-1">Ej: Máquina Kiosco Independencia.</p>
                        @error('name') <p class="text-sm text-red-600 mt-1">{{ $message }}</p> @enderror
                    </div>
                    @if($machineId)
                        <div>
                            <label class="text-sm text-gray-600">Estado</label>
                            <select wire:model="status" class="mt-1 w-full border rounded-lg px-3 py-2.5"><option value="active">Activa</option><option value="inactive">Inactiva</option></select>
                        </div>
                    @endif
                </div>
            </details>
        </div>

        <div class="bg-yellow-50 border border-yellow-200 rounded-xl p-4 text-sm text-gray-700">
            <strong>Así de simple:</strong> si el comercio ya tiene Mercado Pago vinculado, al guardar se prepara automáticamente la sucursal, la caja, el QR y el cobro con el precio configurado.
        </div>

        <div class="flex flex-col-reverse sm:flex-row sm:justify-end gap-3">
            <a href="{{ route('vending.index') }}" wire:navigate class="px-5 py-3 border rounded-lg text-center">Cancelar</a>
            <button type="submit" class="px-6 py-3 bg-red-600 hover:bg-red-700 text-white rounded-lg font-semibold" wire:loading.attr="disabled">
                {{ $machineId ? 'Guardar cambios' : 'Guardar máquina' }}
            </button>
        </div>
    </form>
</div>
