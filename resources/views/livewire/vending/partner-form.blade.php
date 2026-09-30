<div class="max-w-3xl space-y-6" x-data="{ locating: false, locationError: '' }">
    <div>
        <h1 class="text-2xl font-bold text-gray-900">{{ $partnerId ? 'Editar comercio' : 'Nuevo comercio' }}</h1>
        <p class="text-sm text-gray-500 mt-1">Cargá lo básico. Los datos técnicos quedan ocultos.</p>
        <a href="{{ $returnToMachine ? route('vending.machines.create') : route('vending.partners.index') }}" wire:navigate class="inline-block mt-2 text-sm text-red-700">← Volver</a>
    </div>

    @if (session()->has('error'))
        <div class="p-4 bg-red-50 border border-red-200 text-red-800 rounded-xl text-sm">{{ session('error') }}</div>
    @endif

    <form wire:submit="save" class="space-y-5">
        <div class="bg-white border border-gray-200 rounded-2xl p-5 sm:p-6 space-y-5">
            <div>
                <label class="text-sm font-semibold text-gray-800">Nombre del comercio *</label>
                <input wire:model="name" placeholder="Ej: Kiosco Independencia" class="mt-1 w-full border rounded-lg px-3 py-3 text-base">
                @error('name')<p class="text-sm text-red-600 mt-1">{{ $message }}</p>@enderror
            </div>

            <div>
                <label class="text-sm font-semibold text-gray-800">Dirección *</label>
                <div class="grid grid-cols-3 gap-3 mt-1">
                    <input wire:model="street_name" placeholder="Calle" class="col-span-2 w-full border rounded-lg px-3 py-3 text-base">
                    <input wire:model="street_number" placeholder="Número" class="w-full border rounded-lg px-3 py-3 text-base">
                </div>
                @error('street_name')<p class="text-sm text-red-600 mt-1">{{ $message }}</p>@enderror
                @error('street_number')<p class="text-sm text-red-600 mt-1">{{ $message }}</p>@enderror
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="text-sm font-semibold text-gray-800">Ciudad *</label>
                    <input wire:model="city_name" class="mt-1 w-full border rounded-lg px-3 py-3">
                    @error('city_name')<p class="text-sm text-red-600 mt-1">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="text-sm font-semibold text-gray-800">Comisión del comercio (%) *</label>
                    <input wire:model="commission_percent" type="number" step="0.01" min="0" max="100" placeholder="Ej: 15" class="mt-1 w-full border rounded-lg px-3 py-3">
                    @error('commission_percent')<p class="text-sm text-red-600 mt-1">{{ $message }}</p>@enderror
                </div>
            </div>

            <details class="border-t pt-4">
                <summary class="cursor-pointer text-sm font-medium text-gray-600">Más datos opcionales</summary>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mt-4">
                    <div><label class="text-sm text-gray-600">Contacto</label><input wire:model="contact_name" class="mt-1 w-full border rounded-lg px-3 py-2.5"></div>
                    <div><label class="text-sm text-gray-600">Teléfono</label><input wire:model="phone" class="mt-1 w-full border rounded-lg px-3 py-2.5"></div>
                    <div><label class="text-sm text-gray-600">Provincia</label><input wire:model="state_name" class="mt-1 w-full border rounded-lg px-3 py-2.5"></div>
                    <div><label class="text-sm text-gray-600">Referencia</label><input wire:model="location_reference" placeholder="Ej: al lado de la caja" class="mt-1 w-full border rounded-lg px-3 py-2.5"></div>
                    @if($partnerId)
                        <div><label class="text-sm text-gray-600">Estado</label><select wire:model="status" class="mt-1 w-full border rounded-lg px-3 py-2.5"><option value="active">Activo</option><option value="inactive">Inactivo</option></select></div>
                    @endif
                </div>
            </details>
        </div>

        <div class="bg-yellow-50 border border-yellow-200 rounded-2xl p-5 sm:p-6">
            <div class="flex items-start gap-3">
                <div class="text-2xl">💳</div>
                <div class="flex-1">
                    <h2 class="font-semibold text-gray-900">Mercado Pago</h2>
                    @if($partner && $partner->hasMercadoPagoConnection())
                        <p class="text-sm text-green-700 mt-1">✅ Cuenta vinculada{{ $partner->mercadopago_store_id ? ' y sucursal preparada' : '' }}.</p>
                    @else
                        <p class="text-sm text-gray-600 mt-1">La cuenta del kiosco se vincula una sola vez. El dinero de las ventas entra directamente a ese comercio.</p>
                        <p class="text-xs text-gray-500 mt-2">Mercado Pago exige la ubicación física de la sucursal. No tenés que escribir latitud ni longitud: al vincular, el navegador te pide permiso y las toma automáticamente.</p>
                    @endif

                    <p x-show="locationError" x-text="locationError" class="mt-3 text-sm text-red-700" x-cloak></p>
                    @error('latitude')<p class="mt-3 text-sm text-red-700">{{ $message }}</p>@enderror
                    @error('longitude')<p class="mt-3 text-sm text-red-700">{{ $message }}</p>@enderror
                </div>
            </div>
        </div>

        <div class="flex flex-col-reverse sm:flex-row sm:justify-end gap-3">
            <a href="{{ $returnToMachine ? route('vending.machines.create') : route('vending.partners.index') }}" wire:navigate class="px-5 py-3 border rounded-lg text-center">Cancelar</a>

            <button type="submit" class="px-5 py-3 bg-white border border-red-600 text-red-700 rounded-lg font-semibold" wire:loading.attr="disabled">
                Guardar comercio
            </button>

            @if(!$partner || !$partner->hasMercadoPagoConnection())
                <button
                    type="button"
                    class="px-5 py-3 bg-red-600 hover:bg-red-700 text-white rounded-lg font-semibold disabled:opacity-50"
                    :disabled="locating"
                    @click="
                        locationError = '';
                        if (!navigator.geolocation) {
                            locationError = 'Este dispositivo no permite obtener la ubicación. Podés guardar el comercio y vincular Mercado Pago después desde un dispositivo compatible.';
                            return;
                        }
                        locating = true;
                        navigator.geolocation.getCurrentPosition(
                            async (pos) => {
                                await $wire.set('latitude', String(pos.coords.latitude));
                                await $wire.set('longitude', String(pos.coords.longitude));
                                locating = false;
                                $wire.saveAndConnect();
                            },
                            () => {
                                locating = false;
                                locationError = 'No pude obtener la ubicación. Permití el acceso a ubicación estando en el comercio y probá de nuevo.';
                            },
                            { enableHighAccuracy: true, timeout: 15000, maximumAge: 60000 }
                        );
                    "
                >
                    <span x-show="!locating">Guardar y vincular Mercado Pago</span>
                    <span x-show="locating" x-cloak>Obteniendo ubicación…</span>
                </button>
            @endif
        </div>
    </form>
</div>
