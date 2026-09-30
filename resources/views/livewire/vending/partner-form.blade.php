<div class="max-w-2xl space-y-6" x-data="{ locating: false, locationError: '' }">
    <div>
        <h1 class="text-2xl font-bold text-gray-900">{{ $partnerId ? 'Editar comercio' : 'Nuevo comercio' }}</h1>
        <p class="text-sm text-gray-500 mt-1">Solo cargá el kiosco y su dirección.</p>
        <a href="{{ $returnToMachine ? route('vending.machines.create') : route('vending.partners.index') }}" wire:navigate class="inline-block mt-2 text-sm text-red-700">← Volver</a>
    </div>

    @if (session()->has('error'))
        <div class="p-4 bg-red-50 border border-red-200 text-red-800 rounded-xl text-sm">{{ session('error') }}</div>
    @endif

    <form wire:submit="save" class="space-y-5">
        <div class="bg-white border border-gray-200 rounded-2xl p-5 sm:p-6 space-y-5">
            <div>
                <label class="text-sm font-semibold text-gray-800">Nombre del kiosco *</label>
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

            <div>
                <label class="text-sm font-semibold text-gray-800">Ciudad *</label>
                <input wire:model="city_name" class="mt-1 w-full border rounded-lg px-3 py-3 text-base">
                @error('city_name')<p class="text-sm text-red-600 mt-1">{{ $message }}</p>@enderror
            </div>
        </div>

        @if(!$partner || !$partner->hasMercadoPagoConnection())
            <div class="bg-yellow-50 border border-yellow-200 rounded-2xl p-5">
                <div class="font-semibold text-gray-900">Vincular Mercado Pago</div>
                <p class="text-sm text-gray-600 mt-1">Se hace una sola vez. Las ventas de esa máquina quedan registradas automáticamente y el pago entra a la cuenta del kiosco.</p>
                <p x-show="locationError" x-text="locationError" class="mt-3 text-sm text-red-700" x-cloak></p>
                @error('latitude')<p class="mt-3 text-sm text-red-700">{{ $message }}</p>@enderror
                @error('longitude')<p class="mt-3 text-sm text-red-700">{{ $message }}</p>@enderror
            </div>
        @else
            <div class="bg-green-50 border border-green-200 rounded-xl p-4 text-sm text-green-800">✅ Mercado Pago vinculado.</div>
        @endif

        <div class="flex flex-col-reverse sm:flex-row sm:justify-end gap-3">
            <a href="{{ $returnToMachine ? route('vending.machines.create') : route('vending.partners.index') }}" wire:navigate class="px-5 py-3 border rounded-lg text-center">Cancelar</a>

            @if(!$partner || !$partner->hasMercadoPagoConnection())
                <button type="submit" class="px-5 py-3 bg-white border rounded-lg font-medium" wire:loading.attr="disabled">Guardar sin vincular</button>
                <button
                    type="button"
                    class="px-6 py-3 bg-red-600 hover:bg-red-700 text-white rounded-lg font-semibold disabled:opacity-50"
                    :disabled="locating"
                    @click="
                        locationError = '';
                        if (!navigator.geolocation) {
                            locationError = 'Este dispositivo no permite obtener la ubicación. Guardá el comercio y vinculalo después desde otro dispositivo.';
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
                                locationError = 'Permití el acceso a ubicación y volvé a intentar.';
                            },
                            { enableHighAccuracy: true, timeout: 15000, maximumAge: 60000 }
                        );
                    "
                >
                    <span x-show="!locating">Guardar y vincular Mercado Pago</span>
                    <span x-show="locating" x-cloak>Preparando vinculación…</span>
                </button>
            @else
                <button type="submit" class="px-6 py-3 bg-red-600 hover:bg-red-700 text-white rounded-lg font-semibold" wire:loading.attr="disabled">Guardar cambios</button>
            @endif
        </div>
    </form>
</div>
