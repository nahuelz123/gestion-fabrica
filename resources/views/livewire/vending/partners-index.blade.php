<div class="space-y-5">
    @if (session()->has('message'))<div class="p-4 bg-yellow-50 border border-yellow-200 text-yellow-900 rounded-xl text-sm">{{ session('message') }}</div>@endif
    @if (session()->has('error'))<div class="p-4 bg-red-50 border border-red-200 text-red-800 rounded-xl text-sm">{{ session('error') }}</div>@endif

    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Kioscos</h1>
            <p class="text-sm text-gray-500">Mirá cuántas máquinas y ventas tiene cada kiosco.</p>
        </div>
        <a href="{{ route('vending.partners.create') }}" wire:navigate class="px-5 py-3 rounded-lg bg-red-600 hover:bg-red-700 text-white text-sm font-semibold text-center">+ Nuevo kiosco</a>
    </div>

    <input wire:model.live.debounce.300ms="search" type="text" placeholder="Buscar kiosco..." class="w-full sm:w-96 rounded-lg border-gray-300 px-3 py-3 border text-sm">

    <div class="bg-white border rounded-xl overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50"><tr>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Kiosco</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Dirección</th>
                <th class="px-4 py-3 text-right text-xs font-semibold text-gray-500 uppercase">Máquinas</th>
                <th class="px-4 py-3 text-right text-xs font-semibold text-gray-500 uppercase">Ventas hoy</th>
                <th class="px-4 py-3 text-right text-xs font-semibold text-gray-500 uppercase">Ventas mes</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Mercado Pago</th>
                <th class="px-4 py-3 text-right text-xs font-semibold text-gray-500 uppercase">Acciones</th>
            </tr></thead>
            <tbody class="divide-y divide-gray-100">
                @forelse($partners as $partner)
                    <tr>
                        <td class="px-4 py-3 font-medium">{{ $partner->name }}</td>
                        <td class="px-4 py-3 text-sm text-gray-600">{{ $partner->address ?: '—' }}</td>
                        <td class="px-4 py-3 text-lg text-right font-bold">{{ $partner->machines_count }}</td>
                        <td class="px-4 py-3 text-lg text-right font-bold">{{ $partner->sales_today_count }}</td>
                        <td class="px-4 py-3 text-lg text-right font-bold">{{ $partner->sales_month_count }}</td>
                        <td class="px-4 py-3 text-sm">
                            @if($partner->hasMercadoPagoConnection())
                                <span class="inline-flex px-2 py-1 rounded bg-green-100 text-green-800 text-xs font-semibold">✅ Vinculado</span>
                            @else
                                <span class="inline-flex px-2 py-1 rounded bg-yellow-100 text-yellow-800 text-xs font-semibold">Falta vincular</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right text-sm whitespace-nowrap">
                            @if(!$partner->hasMercadoPagoConnection())
                                <a href="{{ route('vending.partners.edit', $partner->id) }}" wire:navigate class="text-red-700 font-semibold mr-3">Vincular MP</a>
                            @else
                                <a href="{{ route('vending.mercadopago.connect', $partner->id) }}" class="text-gray-600 font-medium mr-3">Revincular</a>
                            @endif
                            <a href="{{ route('vending.partners.edit', $partner->id) }}" wire:navigate class="text-gray-700 font-medium">Editar</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-10 text-center text-gray-400">Todavía no hay kioscos. Tocá “Nuevo kiosco” para cargar el primero.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    {{ $partners->links() }}
</div>
