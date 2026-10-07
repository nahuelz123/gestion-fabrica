<div class="space-y-6">
    @if (session()->has('message'))
        <div class="p-4 bg-yellow-50 border border-yellow-200 text-yellow-900 rounded-xl text-sm">{{ session('message') }}</div>
    @endif
    @if (session()->has('error'))
        <div class="p-4 bg-red-50 border border-red-200 text-red-800 rounded-xl text-sm">{{ session('error') }}</div>
    @endif

    <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Máquinas</h1>
            <p class="text-sm text-gray-500 mt-1">Control de cuántas ventas hace cada máquina en cada kiosco.</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <a href="{{ route('vending.sales') }}" wire:navigate class="px-4 py-2.5 rounded-lg border border-gray-300 bg-white text-sm font-medium">Ver todas las ventas</a>
            <a href="{{ route('vending.partners.index') }}" wire:navigate class="px-4 py-2.5 rounded-lg border border-gray-300 bg-white text-sm font-medium">Kioscos</a>
            <a href="{{ route('vending.machines.create') }}" wire:navigate class="px-4 py-2.5 rounded-lg bg-red-600 hover:bg-red-700 text-white text-sm font-semibold">+ Nueva máquina</a>
        </div>
    </div>

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
        <div class="bg-white border rounded-xl p-4"><div class="text-xs text-gray-500">Ventas hoy</div><div class="text-3xl font-bold mt-1">{{ $stats['today'] }}</div></div>
        <div class="bg-white border rounded-xl p-4"><div class="text-xs text-gray-500">Esta semana</div><div class="text-3xl font-bold mt-1">{{ $stats['week'] }}</div></div>
        <div class="bg-white border rounded-xl p-4"><div class="text-xs text-gray-500">Este mes</div><div class="text-3xl font-bold mt-1">{{ $stats['month'] }}</div></div>
        <div class="bg-white border rounded-xl p-4"><div class="text-xs text-gray-500">Máquinas activas</div><div class="text-3xl font-bold mt-1">{{ $stats['machines'] }}</div></div>
    </div>

    <input wire:model.live.debounce.300ms="search" type="text" placeholder="Buscar kiosco, máquina o hamburguesa..." class="w-full sm:w-96 rounded-lg border-gray-300 px-3 py-3 border text-sm">

    <div class="bg-white border rounded-xl overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50"><tr>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Kiosco</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Máquina</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Hamburguesa</th>
                <th class="px-4 py-3 text-right text-xs font-semibold text-gray-500 uppercase">Precio</th>
                <th class="px-4 py-3 text-right text-xs font-semibold text-gray-500 uppercase">Ventas hoy</th>
                <th class="px-4 py-3 text-right text-xs font-semibold text-gray-500 uppercase">Ventas mes</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Mercado Pago</th>
                <th class="px-4 py-3 text-right text-xs font-semibold text-gray-500 uppercase">Acciones</th>
            </tr></thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($machines as $machine)
                    <tr>
                        <td class="px-4 py-3 text-sm font-medium text-gray-900">{{ $machine->partner->name }}</td>
                        <td class="px-4 py-3"><div class="font-medium text-gray-900">{{ $machine->name }}</div><div class="text-xs text-gray-500">{{ $machine->code }}</div></td>
                        <td class="px-4 py-3 text-sm">{{ $machine->product->name }}</td>
                        <td class="px-4 py-3 text-sm text-right">${{ number_format((float)$machine->sale_price, 0, ',', '.') }}</td>
                        <td class="px-4 py-3 text-2xl text-right font-bold">{{ $machine->sales_today_count }}</td>
                        <td class="px-4 py-3 text-2xl text-right font-bold">{{ $machine->sales_month_count }}</td>
                        <td class="px-4 py-3 text-sm">
                            @if($machine->isReady())
                                <span class="inline-flex px-2 py-1 rounded bg-green-100 text-green-800 text-xs font-semibold">✅ Listo</span>
                            @elseif($machine->partner->hasMercadoPagoConnection())
                                <span class="inline-flex px-2 py-1 rounded bg-yellow-100 text-yellow-800 text-xs font-semibold">Falta sincronizar</span>
                            @else
                                <span class="inline-flex px-2 py-1 rounded bg-gray-100 text-gray-700 text-xs font-semibold">Falta vincular</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right text-sm whitespace-nowrap">
                            @if($machine->isReady())
                                <a href="{{ $machine->tabletUrl() }}" target="_blank" class="text-green-700 font-medium mr-3">Tablet</a>
                            @endif
                            @if($machine->partner->hasMercadoPagoConnection() && $machine->status === 'active')
                                <button wire:click="provision({{ $machine->id }})" wire:loading.attr="disabled" class="text-amber-700 font-medium mr-3">Sincronizar</button>
                            @endif
                            <button wire:click="toggleStatus({{ $machine->id }})" wire:loading.attr="disabled"
                                class="{{ $machine->status === 'active' ? 'text-gray-600' : 'text-green-700' }} font-medium mr-3">
                                {{ $machine->status === 'active' ? 'Pausar' : 'Activar' }}
                            </button>
                            <a href="{{ route('vending.machines.edit', $machine->id) }}" wire:navigate class="text-red-700 font-medium">Editar</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="px-4 py-10 text-center text-gray-400">Todavía no hay máquinas. Creá una y elegí el kiosco, la hamburguesa y el precio.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $machines->links() }}
</div>
