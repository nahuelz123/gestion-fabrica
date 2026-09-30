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
            <div class="bg-yellow-50 border border-yellow-200 rounded-2xl p-5">
                <div class="font-semibold text-gray-900">Vincular Mercado Pago</div>
                <p class="text-sm text-gray-600 mt-1">Se hace una sola vez. La app ubica el kiosco a partir de la dirección o esquina que escribiste y después abre Mercado Pago para autorizar la cuenta del kiosco.</p>
                <p class="text-xs text-gray-500 mt-3">La ubicación técnica se obtiene en el servidor con datos de <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener" class="underline">OpenStreetMap</a>; nunca se pide el GPS de este dispositivo.</p>
            </div>
        @else
            <div class="bg-green-50 border border-green-200 rounded-xl p-4 text-sm text-green-800">✅ Mercado Pago vinculado.</div>
        @endif

        <div class="flex flex-col-reverse sm:flex-row sm:justify-end gap-3">
            <a href="{{ $returnToMachine ? route('vending.machines.create') : route('vending.partners.index') }}" wire:navigate class="px-5 py-3 border rounded-lg text-center">Cancelar</a>

            @if(!$partner || !$partner->hasMercadoPagoConnection())
                <button type="submit" class="px-5 py-3 bg-white border rounded-lg font-medium" wire:loading.attr="disabled">Guardar sin vincular</button>
                <button type="button" wire:click="saveAndConnect" wire:loading.attr="disabled" wire:target="saveAndConnect" class="px-6 py-3 bg-red-600 hover:bg-red-700 text-white rounded-lg font-semibold disabled:opacity-50">
                    <span wire:loading.remove wire:target="saveAndConnect">Guardar y vincular Mercado Pago</span>
                    <span wire:loading wire:target="saveAndConnect">Buscando ubicación…</span>
                </button>
            @else
                <button type="submit" class="px-6 py-3 bg-red-600 hover:bg-red-700 text-white rounded-lg font-semibold" wire:loading.attr="disabled">Guardar cambios</button>
            @endif
        </div>
    </form>
</div>
