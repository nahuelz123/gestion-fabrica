<div><div class="flex flex-col lg:flex-row lg:justify-between lg:items-center gap-4 mb-6"><div><h1 class="text-2xl font-bold text-gray-800">Inventario actual</h1><p class="text-sm text-gray-500 mt-1">Stock por depósito y lote.</p></div><div class="flex flex-wrap gap-2"><a href="{{ route('inventory.movements') }}" wire:navigate class="bg-white border px-4 py-2 rounded-md text-sm">🧾 Historial</a><a href="{{ route('inventory.import') }}" wire:navigate class="bg-amber-100 text-amber-900 px-4 py-2 rounded-md text-sm font-semibold">📊 Importar Excel</a><a href="{{ route('inventory.adjust') }}" wire:navigate class="bg-blue-600 text-white px-4 py-2 rounded-md text-sm">± Ajuste</a><a href="{{ route('inventory.entry') }}" wire:navigate class="bg-green-600 text-white px-4 py-2 rounded-md text-sm">+ Entrada</a><a href="{{ route('inventory.exit') }}" wire:navigate class="bg-red-600 text-white px-4 py-2 rounded-md text-sm">- Salida / merma</a></div></div>
@if(session()->has('message'))<div class="mb-4 p-3 bg-blue-100 border border-blue-300 text-blue-800 rounded-md text-sm">{{ session('message') }}</div>@endif
<div class="mb-4 flex flex-col sm:flex-row gap-3 sm:justify-between"><input wire:model.live.debounce.300ms="search" placeholder="Buscar por producto, alias o código..." class="w-full sm:w-96 border rounded-md px-3 py-2 text-sm"><select wire:model.live="perPage" class="border rounded-md px-3 py-2 text-sm"><option value="25">25 por página</option><option value="50">50 por página</option><option value="100">100 por página</option></select></div>

<div class="space-y-3 sm:hidden">
    @forelse($stocks as $stock)
        <article class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <h2 class="break-words font-semibold text-gray-900">{{ $stock->product->name }}</h2>
                    <p class="mt-1 text-xs text-gray-500">{{ $stock->product->internal_code }} · {{ $stock->warehouse->name }}</p>
                </div>
                <span class="shrink-0 text-right font-bold {{ (float) $stock->quantity <= 0 ? 'text-red-700' : 'text-gray-900' }}">
                    {{ number_format((float)$stock->quantity, 2, ',', '.') }} {{ $stock->product->baseUnit->abbreviation ?? 'u' }}
                </span>
            </div>
            <p class="mt-3 text-sm text-gray-600">Lote: {{ $stock->lot?->lot_code ?? 'Sin lote' }}</p>
            @if($stock->lot?->expiration_date)
                <p class="mt-1 text-sm {{ $stock->lot->expiration_date->isPast() ? 'font-semibold text-red-700' : 'text-gray-600' }}">
                    Vencimiento: {{ $stock->lot->expiration_date->format('d/m/Y') }}
                </p>
            @endif
        </article>
    @empty
        <div class="rounded-xl border bg-white p-6 text-center text-sm text-gray-500">No hay stock disponible.</div>
    @endforelse
</div>
<div data-table-scroll tabindex="0" role="region" aria-label="Inventario detallado" class="hidden sm:block bg-white border rounded-lg overflow-x-auto"><table class="min-w-full divide-y"><thead class="bg-gray-50"><tr><th class="px-4 py-3 text-left text-xs uppercase">Producto</th><th class="px-4 py-3 text-left text-xs uppercase">Depósito</th><th class="px-4 py-3 text-left text-xs uppercase">Lote</th><th class="px-4 py-3 text-left text-xs uppercase">Vencimiento</th><th class="px-4 py-3 text-right text-xs uppercase">Cantidad</th></tr></thead><tbody class="divide-y">@forelse($stocks as $stock)<tr><td class="px-4 py-3 text-sm font-medium">{{ $stock->product->name }} <span class="text-xs text-gray-500">({{ $stock->product->internal_code }})</span></td><td class="px-4 py-3 text-sm">{{ $stock->warehouse->name }}</td><td class="px-4 py-3 text-sm">{{ $stock->lot?->lot_code ?? '—' }}</td><td class="px-4 py-3 text-sm">@if($stock->lot?->expiration_date)<span class="{{ $stock->lot->expiration_date->isPast() ? 'text-red-600 font-bold' : '' }}">{{ $stock->lot->expiration_date->format('d/m/Y') }}</span>@else—@endif</td><td class="px-4 py-3 text-right text-sm font-bold {{ $stock->quantity == 0 ? 'text-red-600' : '' }}">{{ rtrim(rtrim($stock->quantity,'0'),'.') }} {{ $stock->product->baseUnit->abbreviation }}</td></tr>@empty<tr><td colspan="5" class="px-4 py-8 text-center text-gray-400">No hay stock disponible.</td></tr>@endforelse</tbody></table></div><div class="mt-4">{{ $stocks->links() }}</div></div>
