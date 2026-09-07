<div>
    <div class="flex justify-between items-center mb-6">
        <div>
            <h1 class="text-2xl font-bold text-gray-800">
                Compra #{{ $purchase->id }}
            </h1>
            <a href="{{ route('purchases.index') }}" wire:navigate class="text-sm text-blue-600 hover:text-blue-800">
                ← Volver a compras
            </a>
        </div>
        
        <div class="space-x-3">
            @if ($purchase->status->value === 'draft')
                <button wire:click="confirmPurchase"
                        wire:confirm="¿Estás seguro de confirmar esta compra? Esto ingresará el stock al sistema y no se puede deshacer."
                        class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-md text-sm font-medium transition"
                        wire:loading.attr="disabled">
                    Confirmar Compra
                </button>
            @else
                <span class="inline-flex items-center px-3 py-1 rounded text-sm font-bold 
                    {{ $purchase->status->value === 'confirmed' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' }}">
                    {{ $purchase->status->label() }}
                </span>
            @endif
        </div>
    </div>

    @if (session()->has('error'))
        <div class="mb-4 p-3 bg-red-100 border border-red-300 text-red-800 rounded-md text-sm">
            {{ session('error') }}
        </div>
    @endif

    <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-5">
            <h3 class="text-sm font-medium text-gray-500 uppercase mb-2">Proveedor</h3>
            <p class="font-bold text-gray-900">{{ $purchase->supplier->name }}</p>
            <p class="text-sm text-gray-600">CUIT: {{ $purchase->supplier->tax_id ?? '—' }}</p>
        </div>
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-5">
            <h3 class="text-sm font-medium text-gray-500 uppercase mb-2">Datos de la Compra</h3>
            <p class="text-sm text-gray-900"><strong>Fecha:</strong> {{ $purchase->purchase_date->format('d/m/Y') }}</p>
            <p class="text-sm text-gray-900"><strong>Comprobante:</strong> {{ $purchase->invoice_number ?? '—' }}</p>
            <p class="text-sm text-gray-900"><strong>Depósito:</strong> {{ $purchase->warehouse->name }}</p>
        </div>
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-5">
            <h3 class="text-sm font-medium text-gray-500 uppercase mb-2">Registro</h3>
            <p class="text-sm text-gray-900"><strong>Usuario:</strong> {{ $purchase->user->name }}</p>
            <p class="text-sm text-gray-900"><strong>Creada:</strong> {{ $purchase->created_at->format('d/m/Y H:i') }}</p>
        </div>
    </div>

    @if($purchase->notes)
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-5 mb-6">
            <h3 class="text-sm font-medium text-gray-500 uppercase mb-2">Notas / Observaciones</h3>
            <p class="text-sm text-gray-800">{{ $purchase->notes }}</p>
        </div>
    @endif

    <div class="bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden">
        <div class="px-5 py-4 border-b border-gray-200 bg-gray-50">
            <h2 class="text-lg font-medium text-gray-900">Ítems ({{ $purchase->items->count() }})</h2>
        </div>
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Producto</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Presentación / Lote</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Cant. Solicitada</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Cant. Base Ingresada</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Costo Unit.</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Subtotal</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                @php $total = 0; @endphp
                @foreach ($purchase->items as $item)
                    @php $subtotal = $item->quantity * $item->unit_cost; $total += $subtotal; @endphp
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3 text-sm font-medium text-gray-900">
                            {{ $item->product->name }} <br>
                            <span class="text-xs text-gray-500">{{ $item->product->internal_code }}</span>
                        </td>
                        <td class="px-4 py-3 text-sm text-gray-600">
                            @if($item->presentation)
                                {{ $item->presentation->name }} (x{{ rtrim(rtrim($item->presentation->conversion_factor, '0'), '.') }})
                            @else
                                Unidad Base
                            @endif

                            @if($item->lot_code)
                                <div class="mt-1">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-orange-100 text-orange-800">
                                        Lote: {{ $item->lot_code }} 
                                        @if($item->expiration_date)
                                            (Vence: {{ $item->expiration_date->format('d/m/Y') }})
                                        @endif
                                    </span>
                                </div>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right text-sm font-medium text-gray-900">
                            {{ rtrim(rtrim($item->quantity, '0'), '.') }}
                        </td>
                        <td class="px-4 py-3 text-right text-sm text-gray-600">
                            @if($purchase->status->value === 'confirmed' && $item->quantity_base)
                                {{ rtrim(rtrim($item->quantity_base, '0'), '.') }} {{ $item->product->baseUnit->abbreviation }}
                            @else
                                <span class="text-gray-400 italic">Pendiente</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right text-sm text-gray-600">
                            ${{ number_format($item->unit_cost, 2, ',', '.') }}
                        </td>
                        <td class="px-4 py-3 text-right text-sm font-bold text-gray-900">
                            ${{ number_format($subtotal, 2, ',', '.') }}
                        </td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot class="bg-gray-50">
                <tr>
                    <td colspan="5" class="px-4 py-3 text-right text-sm font-bold text-gray-900 uppercase">Total Estimado</td>
                    <td class="px-4 py-3 text-right text-lg font-bold text-blue-600">
                        ${{ number_format($total, 2, ',', '.') }}
                    </td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>
