<div class="max-w-4xl space-y-6">
    <div>
        <h1 class="text-2xl font-bold text-gray-800">{{ $machineId ? 'Editar máquina' : 'Nueva máquina' }}</h1>
        <a href="{{ route('vending.index') }}" wire:navigate class="text-sm text-blue-700">← Volver</a>
    </div>

    <form wire:submit="save" class="space-y-6">
        <div class="bg-white border rounded-xl p-6 grid grid-cols-1 md:grid-cols-2 gap-5">
            <div>
                <label class="text-sm font-medium">Código *</label>
                <input wire:model="code" placeholder="Ej: MAQ-001" class="mt-1 w-full border rounded-md px-3 py-2">
                @error('code') <p class="text-sm text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="text-sm font-medium">Nombre *</label>
                <input wire:model="name" placeholder="Ej: Máquina Kiosco Centro" class="mt-1 w-full border rounded-md px-3 py-2">
                @error('name') <p class="text-sm text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="text-sm font-medium">Comercio *</label>
                <select wire:model="vending_partner_id" class="mt-1 w-full border rounded-md px-3 py-2">
                    <option value="">Seleccionar...</option>
                    @foreach($partners as $partner)<option value="{{ $partner->id }}">{{ $partner->name }}</option>@endforeach
                </select>
                @error('vending_partner_id') <p class="text-sm text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="text-sm font-medium">Hamburguesa *</label>
                <select wire:model="product_id" class="mt-1 w-full border rounded-md px-3 py-2">
                    <option value="">Seleccionar...</option>
                    @foreach($products as $product)<option value="{{ $product->id }}">{{ $product->name }}</option>@endforeach
                </select>
                @error('product_id') <p class="text-sm text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="text-sm font-medium">Precio de venta *</label>
                <input wire:model="sale_price" type="number" step="0.01" min="1" class="mt-1 w-full border rounded-md px-3 py-2">
                @error('sale_price') <p class="text-sm text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="text-sm font-medium">Ubicación dentro del comercio</label>
                <input wire:model="location" placeholder="Ej: entrada, frente a caja" class="mt-1 w-full border rounded-md px-3 py-2">
            </div>
            <div>
                <label class="text-sm font-medium">Capacidad</label>
                <input wire:model="capacity" type="number" min="1" class="mt-1 w-full border rounded-md px-3 py-2">
                @error('capacity') <p class="text-sm text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="text-sm font-medium">Hamburguesas cargadas ahora *</label>
                <input wire:model="loaded_units" type="number" min="0" class="mt-1 w-full border rounded-md px-3 py-2">
                <p class="text-xs text-gray-500 mt-1">Cada venta aprobada descuenta 1 automáticamente.</p>
                @error('loaded_units') <p class="text-sm text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="text-sm font-medium">Estado</label>
                <select wire:model="status" class="mt-1 w-full border rounded-md px-3 py-2"><option value="active">Activa</option><option value="inactive">Inactiva</option></select>
            </div>
        </div>

        <div class="bg-blue-50 border border-blue-200 rounded-xl p-5 text-sm text-blue-900">
            Al guardar una máquina activa, si el comercio ya tiene Mercado Pago vinculado, el sistema crea o recupera automáticamente la sucursal, la caja/POS, el QR estático y deja preparada la orden con el precio configurado.
        </div>

        <div class="flex justify-end gap-3">
            <a href="{{ route('vending.index') }}" wire:navigate class="px-5 py-2.5 border rounded-md">Cancelar</a>
            <button type="submit" class="px-5 py-2.5 bg-blue-600 text-white rounded-md font-medium" wire:loading.attr="disabled">Guardar y sincronizar</button>
        </div>
    </form>
</div>
