<div class="space-y-6">
    @if (session()->has('message'))<div class="p-3 bg-green-100 border border-green-300 text-green-800 rounded-md text-sm">{{ session('message') }}</div>@endif
    @if (session()->has('error'))<div class="p-3 bg-red-100 border border-red-300 text-red-800 rounded-md text-sm">{{ session('error') }}</div>@endif

    <div class="flex items-center justify-between gap-4">
        <div><h1 class="text-2xl font-bold text-gray-800">Ventas de máquinas</h1><p class="text-sm text-gray-500">Registro conciliado con Mercado Pago, incluidos reembolsos.</p></div>
        <a href="{{ route('vending.index') }}" wire:navigate class="text-sm text-blue-700">← Máquinas</a>
    </div>

    <div class="bg-white border rounded-xl p-4 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3">
        <div><label class="text-xs font-semibold text-gray-500">Desde</label><input wire:model.live="date_from" type="date" class="mt-1 w-full border rounded-md px-2 py-2 text-sm"></div>
        <div><label class="text-xs font-semibold text-gray-500">Hasta</label><input wire:model.live="date_to" type="date" class="mt-1 w-full border rounded-md px-2 py-2 text-sm"></div>
        <div><label class="text-xs font-semibold text-gray-500">Comercio</label><select wire:model.live="partner_id" class="mt-1 w-full border rounded-md px-2 py-2 text-sm"><option value="">Todos</option>@foreach($partners as $partner)<option value="{{ $partner->id }}">{{ $partner->name }}</option>@endforeach</select></div>
        <div><label class="text-xs font-semibold text-gray-500">Máquina</label><select wire:model.live="machine_id" class="mt-1 w-full border rounded-md px-2 py-2 text-sm"><option value="">Todas</option>@foreach($machines as $machine)<option value="{{ $machine->id }}">{{ $machine->name }}</option>@endforeach</select></div>
        <div><label class="text-xs font-semibold text-gray-500">Liquidación</label><select wire:model.live="settlement" class="mt-1 w-full border rounded-md px-2 py-2 text-sm"><option value="all">Todas</option><option value="pending">Pendientes</option><option value="settled">Liquidadas</option></select></div>
    </div>

    <div class="grid grid-cols-2 lg:grid-cols-5 gap-3">
        <div class="bg-white border rounded-xl p-4"><div class="text-xs text-gray-500">Movimientos</div><div class="text-2xl font-bold">{{ $summary['count'] }}</div></div>
        <div class="bg-white border rounded-xl p-4"><div class="text-xs text-gray-500">Cobrado originalmente</div><div class="text-xl font-bold">${{ number_format($summary['gross'], 2, ',', '.') }}</div></div>
        <div class="bg-white border rounded-xl p-4"><div class="text-xs text-gray-500">Reembolsado</div><div class="text-xl font-bold text-red-700">${{ number_format($summary['refunded'], 2, ',', '.') }}</div></div>
        <div class="bg-white border rounded-xl p-4"><div class="text-xs text-gray-500">Comisión kioscos</div><div class="text-xl font-bold">${{ number_format($summary['commission'], 2, ',', '.') }}</div></div>
        <div class="bg-white border rounded-xl p-4"><div class="text-xs text-gray-500">Corresponde a fábrica</div><div class="text-xl font-bold">${{ number_format($summary['factory'], 2, ',', '.') }}</div></div>
    </div>

    @if($partner_id && $date_from && $date_to)
        <div class="flex justify-end">
            <button wire:click="markSettled" wire:confirm="¿Marcar como liquidadas todas las ventas pendientes de este comercio y período?" class="px-4 py-2 rounded-md bg-green-600 text-white text-sm font-medium">Marcar período como liquidado</button>
        </div>
    @endif

    <div class="bg-white border rounded-xl overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50"><tr>
                <th class="px-3 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Fecha / Recibo</th>
                <th class="px-3 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Comercio</th>
                <th class="px-3 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Máquina / producto</th>
                <th class="px-3 py-3 text-right text-xs font-semibold text-gray-500 uppercase">Cobrado</th>
                <th class="px-3 py-3 text-right text-xs font-semibold text-gray-500 uppercase">Reembolso</th>
                <th class="px-3 py-3 text-right text-xs font-semibold text-gray-500 uppercase">Comisión</th>
                <th class="px-3 py-3 text-right text-xs font-semibold text-gray-500 uppercase">Fábrica</th>
                <th class="px-3 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Estado</th>
            </tr></thead>
            <tbody class="divide-y divide-gray-100">
                @forelse($sales as $sale)
                    <tr>
                        <td class="px-3 py-3 text-sm"><div>{{ $sale->sold_at?->format('d/m/Y H:i') }}</div><div class="text-xs font-mono text-gray-500">{{ $sale->receipt_number }}</div></td>
                        <td class="px-3 py-3 text-sm">{{ $sale->partner->name }}</td>
                        <td class="px-3 py-3 text-sm"><div>{{ $sale->machine->name }}</div><div class="text-xs text-gray-500">{{ $sale->product->name }}</div></td>
                        <td class="px-3 py-3 text-sm text-right font-medium">${{ number_format((float)$sale->gross_amount, 2, ',', '.') }}</td>
                        <td class="px-3 py-3 text-sm text-right {{ (float)$sale->refunded_amount > 0 ? 'text-red-700 font-medium' : 'text-gray-400' }}">${{ number_format((float)$sale->refunded_amount, 2, ',', '.') }}</td>
                        <td class="px-3 py-3 text-sm text-right">${{ number_format((float)$sale->commission_amount, 2, ',', '.') }} <span class="text-xs text-gray-400">({{ number_format((float)$sale->commission_percent, 2, ',', '.') }}%)</span></td>
                        <td class="px-3 py-3 text-sm text-right font-semibold">${{ number_format((float)$sale->factory_amount, 2, ',', '.') }}</td>
                        <td class="px-3 py-3 text-sm whitespace-nowrap">
                            @if($sale->status === 'refunded')
                                <span class="px-2 py-1 rounded bg-red-100 text-red-800 text-xs font-semibold">Reembolsada</span>
                            @elseif($sale->status === 'partially_refunded')
                                <span class="px-2 py-1 rounded bg-orange-100 text-orange-800 text-xs font-semibold">Reembolso parcial</span>
                            @else
                                <span class="px-2 py-1 rounded bg-blue-100 text-blue-800 text-xs font-semibold">Aprobada</span>
                            @endif
                            @if($sale->settled_at)<span class="ml-1 px-2 py-1 rounded bg-green-100 text-green-800 text-xs font-semibold">Liquidada</span>@elseif(in_array($sale->status, ['approved','partially_refunded']))<span class="ml-1 px-2 py-1 rounded bg-yellow-100 text-yellow-800 text-xs font-semibold">Pendiente</span>@endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="px-4 py-10 text-center text-gray-400">No hay movimientos en el período seleccionado.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    {{ $sales->links() }}
</div>
