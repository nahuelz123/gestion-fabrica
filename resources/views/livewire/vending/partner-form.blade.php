<div class="max-w-4xl space-y-6">
    <div>
        <h1 class="text-2xl font-bold text-gray-800">{{ $partnerId ? 'Editar comercio' : 'Nuevo comercio' }}</h1>
        <a href="{{ route('vending.partners.index') }}" wire:navigate class="text-sm text-blue-700">← Volver</a>
    </div>

    @if (session()->has('error'))<div class="p-3 bg-red-100 border border-red-300 text-red-800 rounded-md text-sm">{{ session('error') }}</div>@endif

    <form wire:submit="save" class="space-y-6">
        <div class="bg-white border rounded-xl p-6 grid grid-cols-1 md:grid-cols-2 gap-5">
            <div><label class="text-sm font-medium">Nombre *</label><input wire:model="name" class="mt-1 w-full border rounded-md px-3 py-2">@error('name')<p class="text-sm text-red-600 mt-1">{{ $message }}</p>@enderror</div>
            <div><label class="text-sm font-medium">Comisión del kiosco (%) *</label><input wire:model="commission_percent" type="number" step="0.01" min="0" max="100" class="mt-1 w-full border rounded-md px-3 py-2">@error('commission_percent')<p class="text-sm text-red-600 mt-1">{{ $message }}</p>@enderror</div>
            <div><label class="text-sm font-medium">Contacto</label><input wire:model="contact_name" class="mt-1 w-full border rounded-md px-3 py-2"></div>
            <div><label class="text-sm font-medium">Teléfono</label><input wire:model="phone" class="mt-1 w-full border rounded-md px-3 py-2"></div>
        </div>

        <div class="bg-white border rounded-xl p-6">
            <h2 class="font-semibold text-gray-800 mb-1">Ubicación de la sucursal</h2>
            <p class="text-xs text-gray-500 mb-5">Usá la ubicación real del kiosco: Mercado Pago la utiliza al crear la sucursal física.</p>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                <div><label class="text-sm font-medium">Calle *</label><input wire:model="street_name" class="mt-1 w-full border rounded-md px-3 py-2">@error('street_name')<p class="text-sm text-red-600 mt-1">{{ $message }}</p>@enderror</div>
                <div><label class="text-sm font-medium">Número *</label><input wire:model="street_number" class="mt-1 w-full border rounded-md px-3 py-2">@error('street_number')<p class="text-sm text-red-600 mt-1">{{ $message }}</p>@enderror</div>
                <div><label class="text-sm font-medium">Ciudad *</label><input wire:model="city_name" class="mt-1 w-full border rounded-md px-3 py-2">@error('city_name')<p class="text-sm text-red-600 mt-1">{{ $message }}</p>@enderror</div>
                <div><label class="text-sm font-medium">Provincia *</label><input wire:model="state_name" class="mt-1 w-full border rounded-md px-3 py-2">@error('state_name')<p class="text-sm text-red-600 mt-1">{{ $message }}</p>@enderror</div>
                <div class="md:col-span-2"><label class="text-sm font-medium">Referencia</label><input wire:model="location_reference" placeholder="Ej: dentro del kiosco, al lado de la caja" class="mt-1 w-full border rounded-md px-3 py-2"></div>
                <div><label class="text-sm font-medium">Latitud *</label><input wire:model="latitude" type="number" step="0.0000001" placeholder="-38.0055" class="mt-1 w-full border rounded-md px-3 py-2">@error('latitude')<p class="text-sm text-red-600 mt-1">{{ $message }}</p>@enderror</div>
                <div><label class="text-sm font-medium">Longitud *</label><input wire:model="longitude" type="number" step="0.0000001" placeholder="-57.5426" class="mt-1 w-full border rounded-md px-3 py-2">@error('longitude')<p class="text-sm text-red-600 mt-1">{{ $message }}</p>@enderror</div>
            </div>
            <p class="text-xs text-gray-500 mt-3">Podés copiar latitud y longitud desde Google Maps. No uses coordenadas aproximadas de otra dirección.</p>
        </div>

        <div class="bg-white border rounded-xl p-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
                <div class="font-semibold text-gray-800">Mercado Pago</div>
                @if($partner && $partner->hasMercadoPagoConnection())
                    <div class="text-sm text-green-700">Cuenta vinculada{{ $partner->mercadopago_store_id ? ' · Sucursal sincronizada' : '' }}</div>
                @else
                    <div class="text-sm text-gray-500">Guardá el comercio y después vinculá la cuenta del kiosco.</div>
                @endif
            </div>
            @if($partner)
                <a href="{{ route('vending.mercadopago.connect', $partner->id) }}" class="px-4 py-2 rounded-md bg-green-600 text-white text-sm font-medium">{{ $partner->hasMercadoPagoConnection() ? 'Revincular Mercado Pago' : 'Vincular Mercado Pago' }}</a>
            @endif
        </div>

        <div class="flex justify-end gap-3">
            <a href="{{ route('vending.partners.index') }}" wire:navigate class="px-5 py-2.5 border rounded-md">Cancelar</a>
            <button type="submit" class="px-5 py-2.5 bg-blue-600 text-white rounded-md font-medium" wire:loading.attr="disabled">Guardar comercio</button>
        </div>
    </form>
</div>
