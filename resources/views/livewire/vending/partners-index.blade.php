<div class="space-y-5">
    @if (session()->has('message'))<div class="p-3 bg-green-100 border border-green-300 text-green-800 rounded-md text-sm">{{ session('message') }}</div>@endif
    @if (session()->has('error'))<div class="p-3 bg-red-100 border border-red-300 text-red-800 rounded-md text-sm">{{ session('error') }}</div>@endif

    <div class="flex items-center justify-between gap-4">
        <div><h1 class="text-2xl font-bold text-gray-800">Comercios / kioscos</h1><p class="text-sm text-gray-500">Cada comercio cobra en su propia cuenta de Mercado Pago.</p></div>
        <a href="{{ route('vending.partners.create') }}" wire:navigate class="px-4 py-2 rounded-md bg-blue-600 text-white text-sm font-medium">+ Nuevo comercio</a>
    </div>

    <input wire:model.live.debounce.300ms="search" type="text" placeholder="Buscar comercio..." class="w-full sm:w-96 rounded-md border-gray-300 px-3 py-2 border text-sm">

    <div class="bg-white border rounded-xl overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50"><tr>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Comercio</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Dirección</th>
                <th class="px-4 py-3 text-right text-xs font-semibold text-gray-500 uppercase">Comisión</th>
                <th class="px-4 py-3 text-right text-xs font-semibold text-gray-500 uppercase">Máquinas</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Mercado Pago</th>
                <th class="px-4 py-3 text-right text-xs font-semibold text-gray-500 uppercase">Acciones</th>
            </tr></thead>
            <tbody class="divide-y divide-gray-100">
                @forelse($partners as $partner)
                    <tr>
                        <td class="px-4 py-3"><div class="font-medium">{{ $partner->name }}</div><div class="text-xs text-gray-500">{{ $partner->contact_name }} {{ $partner->phone }}</div></td>
                        <td class="px-4 py-3 text-sm text-gray-600">{{ $partner->address ?: '—' }}</td>
                        <td class="px-4 py-3 text-sm text-right">{{ number_format((float)$partner->commission_percent, 2, ',', '.') }}%</td>
                        <td class="px-4 py-3 text-sm text-right">{{ $partner->machines_count }}</td>
                        <td class="px-4 py-3 text-sm">
                            @if($partner->hasMercadoPagoConnection())
                                <span class="inline-flex px-2 py-1 rounded bg-green-100 text-green-800 text-xs font-semibold">Vinculado</span>
                            @else
                                <span class="inline-flex px-2 py-1 rounded bg-gray-100 text-gray-700 text-xs font-semibold">No vinculado</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right text-sm whitespace-nowrap">
                            @if(!$partner->hasMercadoPagoConnection())
                                <a href="{{ route('vending.mercadopago.connect', $partner->id) }}" class="text-green-700 font-medium mr-3">Vincular MP</a>
                            @else
                                <a href="{{ route('vending.mercadopago.connect', $partner->id) }}" class="text-gray-600 font-medium mr-3">Revincular</a>
                            @endif
                            <a href="{{ route('vending.partners.edit', $partner->id) }}" wire:navigate class="text-blue-700 font-medium">Editar</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-10 text-center text-gray-400">Todavía no hay comercios cargados.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    {{ $partners->links() }}
</div>
