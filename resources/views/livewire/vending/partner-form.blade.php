<div class="max-w-2xl space-y-6">
    <div>
        <h1 class="text-2xl font-bold text-gray-900">{{ $partnerId ? 'Editar kiosco' : 'Nuevo kiosco' }}</h1>
        <p class="text-sm text-gray-500 mt-1">Nombre, ubicación y Mercado Pago. Nada más.</p>
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
                <label class="text-sm font-semibold text-gray-800">Dirección o esquina *</label>
                <input wire:model="address_query" placeholder="Ej: Belgrano 2100 o Belgrano e Independencia" class="mt-1 w-full border rounded-lg px-3 py-3 text-base">
                <p class="text-xs text-gray-500 mt-2">Podés poner un número o una esquina. No usamos la ubicación actual de tu teléfono.</p>
                @error('address_query')<p class="text-sm text-red-600 mt-1">{{ $message }}</p>@enderror
            </div>

            <div>
                <label class="text-sm font-semibold text-gray-800">Ciudad *</label>
                <input wire:model="city_name" class="mt-1 w-full border rounded-lg px-3 py-3 text-base">
                @error('city_name')<p class="text-sm text-red-600 mt-1">{{ $message }}</p>@enderror
            </div>
        </div>

        @if(!$partner || !$partner->hasMercadoPagoConnection())
            @if($mercadoPagoConfigured)
                <div class="bg-yellow-50 border border-yellow-200 rounded-2xl p-5">
                    <div class="font-semibold text-gray-900">Vincular Mercado Pago</div>
                    <p class="text-sm text-gray-600 mt-1">Se hace una sola vez por kiosco. Después de guardar, el dueño del kiosco entra a su Mercado Pago y autoriza la vinculación.</p>
                    <p class="text-xs text-gray-500 mt-3">La app obtiene la ubicación a partir de la dirección o esquina escrita; nunca usa el GPS de este dispositivo.</p>
                </div>
            @else
                <div class="bg-amber-50 border border-amber-300 rounded-2xl p-5">
                    <div class="font-semibold text-amber-900">⚠️ Falta la configuración general de Mercado Pago</div>
                    <p class="text-sm text-amber-800 mt-2">Esto se configura una sola vez para Rapi Burguer, no en cada kiosco. Podés guardar el kiosco ahora, pero la vinculación de cuentas queda deshabilitada hasta completar esa configuración en el servidor.</p>
                    <p class="text-xs text-amber-700 mt-3">El dueño del kiosco nunca tiene que cargar Client ID, Client Secret ni otras credenciales técnicas.</p>
                </div>
            @endif
        @else
            <div class="bg-green-50 border border-green-200 rounded-xl p-4 text-sm text-green-800">✅ Mercado Pago vinculado.</div>
        @endif

        <div class="flex flex-col-reverse sm:flex-row sm:justify-end gap-3">
            <a href="{{ $returnToMachine ? route('vending.machines.create') : route('vending.partners.index') }}" wire:navigate class="px-5 py-3 border rounded-lg text-center">Cancelar</a>

            @if(!$partner || !$partner->hasMercadoPagoConnection())
                <button type="submit" class="px-5 py-3 bg-white border rounded-lg font-medium" wire:loading.attr="disabled">Guardar kiosco</button>

                @if($mercadoPagoConfigured)
                    <button type="button" wire:click="saveAndConnect" wire:loading.attr="disabled" wire:target="saveAndConnect" class="px-6 py-3 bg-red-600 hover:bg-red-700 text-white rounded-lg font-semibold disabled:opacity-50">
                        <span wire:loading.remove wire:target="saveAndConnect">Guardar y vincular Mercado Pago</span>
                        <span wire:loading wire:target="saveAndConnect">Preparando vinculación…</span>
                    </button>
                @else
                    <button type="button" disabled class="px-6 py-3 bg-gray-200 text-gray-500 rounded-lg font-semibold cursor-not-allowed">
                        Mercado Pago pendiente de configuración
                    </button>
                @endif
            @else
                <button type="submit" class="px-6 py-3 bg-red-600 hover:bg-red-700 text-white rounded-lg font-semibold" wire:loading.attr="disabled">Guardar cambios</button>
            @endif
        </div>
    </form>
</div>
