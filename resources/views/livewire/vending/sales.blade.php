<div class="space-y-6">
    <div class="flex items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-800">Ventas de máquinas</h1>
            <p class="text-sm text-gray-500">Filtrá por kiosco o máquina y mirá cuántas ventas hizo cada una.</p>
        </div>
        <a href="{{ route('vending.index') }}" wire:navigate class="text-sm text-red-700">← Máquinas</a>
    </div>

    <div class="bg-white border rounded-xl p-4 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
        <div><label class="text-xs font-semibold text-gray-500">Desde</label><input wire:model.live="date_from" type="date" class="mt-1 w-full border rounded-md px-2 py-2 text-sm"></div>
        <div><label class="text-xs font-semibold text-gray-500">Hasta</label><input wire:model.live="date_to" type="date" class="mt-1 w-full border rounded-md px-2 py-2 text-sm"></div>
        <div><label class="text-xs font-semibold text-gray-500">Kiosco</label><select wire:model.live="partner_id" class="mt-1 w-full border rounded-md px-2 py-2 text-sm"><option value="">Todos</option>@foreach($partners as $partner)<option value="{{ $partner->id }}">{{ $partner->name }}</option>@endforeach</select></div>
        <div><label class="text-xs font-semibold text-gray-500">Máquina</label><select wire:model.live="machine_id" class="mt-1 w-full border rounded-md px-2 py-2 text-sm"><option value="">Todas</option>@foreach($machines as $machine)<option value="{{ $machine->id }}">{{ $machine->name }}</option>@endforeach</select></div>
    </div>

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
        <div class="bg-white border rounded-xl p-4"><div class="text-xs text-gray-500">Ventas</div><div class="text-3xl font-bold">{{ $summary['sales'] }}</div></div>
        <div class="bg-white border rounded-xl p-4"><div class="text-xs text-gray-500">Kioscos con ventas</div><div class="text-3xl font-bold">{{ $summary['partners'] }}</div></div>
        <div class="bg-white border rounded-xl p-4"><div class="text-xs text-gray-500">Máquinas con ventas</div><div class="text-3xl font-bold">{{ $summary['machines'] }}</div></div>
        <div class="bg-white border rounded-xl p-4"><div class="text-xs text-gray-500">Cobrado</div><div class="text-xl font-bold">${{ number_format($summary['amount'], 0, ',', '.') }}</div></div>
    </div>

    <div class="bg-white border rounded-xl overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50"><tr>
                <th class="px-3 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Fecha</th>
                <th class="px-3 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Kiosco</th>
                <th class="px-3 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Máquina</th>
                <th class="px-3 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Hamburguesa</th>
                <th class="px-3 py-3 text-right text-xs font-semibold text-gray-500 uppercase">Importe</th>
                <th class="px-3 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Estado</th>
            </tr></thead>
            <tbody class="divide-y divide-gray-100">
                @forelse($sales as $sale)
                    <tr>
                        <td class="px-3 py-3 text-sm">{{ $sale->sold_at?->format('d/m/Y H:i') }}</td>
                        <td class="px-3 py-3 text-sm font-medium">{{ $sale->partner->name }}</td>
                        <td class="px-3 py-3 text-sm">{{ $sale->machine->name }}</td>
                        <td class="px-3 py-3 text-sm">{{ $sale->product->name }}</td>
                        <td class="px-3 py-3 text-sm text-right font-medium">${{ number_format(max(0, (float)$sale->gross_amount - (float)$sale->refunded_amount), 0, ',', '.') }}</td>
                        <td class="px-3 py-3 text-sm whitespace-nowrap">
                            @if($sale->status === 'refunded')
                                <span class="px-2 py-1 rounded bg-red-100 text-red-800 text-xs font-semibold">Reembolsada</span>
                            @elseif($sale->status === 'partially_refunded')
                                <span class="px-2 py-1 rounded bg-orange-100 text-orange-800 text-xs font-semibold">Reembolso parcial</span>
                            @else
                                <span class="px-2 py-1 rounded bg-green-100 text-green-800 text-xs font-semibold">Venta</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-10 text-center text-gray-400">No hay ventas en el período seleccionado.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    {{ $sales->links() }}
</div>
