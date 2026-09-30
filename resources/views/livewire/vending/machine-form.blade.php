<div class="max-w-2xl space-y-6">
    <div>
        <h1 class="text-2xl font-bold text-gray-900">{{ $machineId ? 'Editar máquina' : 'Nueva máquina' }}</h1>
        <p class="text-sm text-gray-500 mt-1">Elegí el kiosco, la hamburguesa y el precio.</p>
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
                    <label class="text-sm font-semibold text-gray-800">Kiosco *</label>
                    <a href="{{ route('vending.partners.create', ['return' => 'machine']) }}" wire:navigate class="text-sm font-semibold text-red-700">+ Nuevo kiosco</a>
                </div>

                @if($partnerCount > 20)
                    <input wire:model.live.debounce.300ms="partnerSearch" placeholder="Buscar kiosco..." class="mb-2 w-full border rounded-lg px-3 py-2.5 text-sm">
                @endif

                <select wire:model.live="vending_partner_id" class="w-full border rounded-lg px-3 py-3 text-base">
                    <option value="">Elegir kiosco...</option>
                    @foreach($partners as $partner)
                        <option value="{{ $partner->id }}">{{ $partner->name }}</option>
                    @endforeach
                </select>
                @error('vending_partner_id') <p class="text-sm text-red-600 mt-1">{{ $message }}</p> @enderror

                @if($selectedPartner)
                    <div class="mt-2 text-xs {{ $selectedPartner->hasMercadoPagoConnection() ? 'text-green-700' : 'text-amber-700' }}">
                        {{ $selectedPartner->hasMercadoPagoConnection() ? '✅ Mercado Pago vinculado' : '⚠️ Falta vincular Mercado Pago. Al guardar te llevo a vincularlo.' }}
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

            <div>
                <label class="text-sm font-semibold text-gray-800">Precio *</label>
                <div class="relative mt-1">
                    <span class="absolute left-3 top-3 text-gray-500">$</span>
                    <input wire:model="sale_price" type="number" step="0.01" min="1" placeholder="8500" class="w-full border rounded-lg pl-7 pr-3 py-3 text-base">
                </div>
                @error('sale_price') <p class="text-sm text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>
        </div>

        <div class="bg-green-50 border border-green-200 rounded-xl p-4 text-sm text-green-800">
            Cada pago aprobado queda registrado automáticamente como <strong>1 venta</strong> de esta máquina en este kiosco.
        </div>

        <div class="flex flex-col-reverse sm:flex-row sm:justify-end gap-3">
            <a href="{{ route('vending.index') }}" wire:navigate class="px-5 py-3 border rounded-lg text-center">Cancelar</a>
            <button type="submit" class="px-6 py-3 bg-red-600 hover:bg-red-700 text-white rounded-lg font-semibold" wire:loading.attr="disabled">
                {{ $machineId ? 'Guardar cambios' : 'Crear máquina' }}
            </button>
        </div>
    </form>
</div>
