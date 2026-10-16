<div class="mx-auto max-w-6xl space-y-6">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <a href="{{ route('inventory.index') }}" wire:navigate class="text-sm font-medium text-red-700 hover:underline">← Volver al inventario</a>
            <h1 class="mt-2 text-2xl font-bold text-gray-900">Conciliar stock desde Excel</h1>
            <p class="mt-1 text-sm text-gray-600">Compará el inventario actual con una planilla nueva y aplicá solo las diferencias.</p>
        </div>
        <button type="button" wire:click="downloadTemplate" class="min-h-11 shrink-0 rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-semibold hover:bg-gray-50">
            Descargar plantilla con el stock actual
        </button>
    </div>

    <section class="rounded-xl border border-blue-200 bg-blue-50 p-4 text-sm text-blue-900">
        <h2 class="font-bold">Cómo funciona</h2>
        <p class="mt-1">Elegí el depósito, descargá la plantilla y editá las cantidades reales en Excel. Al subirla, se mostrará la existencia del sistema, la cantidad de la planilla y la diferencia. <strong>Importar no modifica el stock hasta que confirmes.</strong></p>
        <p class="mt-2">Formatos: .xlsx o .csv; hasta 1000 filas y 4 MB. Las cantidades son unidades base, salvo que indiques una Presentación existente. Cada producto con control por lote debe incluir su código de Lote, que tiene que existir. Los productos ausentes del archivo <strong>no se ponen en cero</strong>.</p>
    </section>

    <form wire:submit="preview" class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm sm:p-6">
        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label for="import-warehouse" class="mb-1 block text-sm font-semibold text-gray-700">Depósito a comparar</label>
                <select id="import-warehouse" wire:model.live="warehouseId" class="min-h-12 w-full rounded-lg border-gray-300 px-3 py-2 text-base">
                    <option value="">Elegí un depósito</option>
                    @foreach($warehouses as $warehouse)
                        <option value="{{ $warehouse->id }}">{{ $warehouse->name }}</option>
                    @endforeach
                </select>
                @error('warehouseId')<p class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror
            </div>
            <div>
                <label for="stock-spreadsheet" class="mb-1 block text-sm font-semibold text-gray-700">Excel o CSV de recuento</label>
                <input id="stock-spreadsheet" type="file" wire:model="spreadsheet" accept=".xlsx,.csv,.txt" class="block w-full min-w-0 rounded-lg border border-gray-300 p-2 text-sm file:mr-2 file:rounded-md file:border-0 file:bg-gray-100 file:px-3 file:py-2">
                @error('spreadsheet')<p class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror
                <p wire:loading wire:target="spreadsheet" class="mt-1 text-sm text-gray-600" role="status">Subiendo archivo…</p>
            </div>
        </div>

        <button type="submit" wire:loading.attr="disabled" wire:target="preview,spreadsheet"
            class="mt-5 min-h-12 w-full rounded-lg bg-gray-900 px-5 py-3 font-semibold text-white disabled:opacity-50 sm:w-auto">
            <span wire:loading.remove wire:target="preview">Comparar sin modificar stock</span>
            <span wire:loading wire:target="preview">Validando y comparando…</span>
        </button>
    </form>

    @if($error)
        <div role="alert" class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-900">{{ $error }}</div>
    @endif
    @if($message)
        <div role="status" class="rounded-lg border border-green-200 bg-green-50 p-4 text-sm text-green-900">{{ $message }}</div>
    @endif
    @if(count($validationErrors))
        <section role="alert" class="rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900">
            <h2 class="font-semibold">Corregí estas filas antes de importar</h2>
            <p class="mt-1">Por seguridad, si alguna fila es inválida, no se aplica ninguna.</p>
            <ul class="mt-2 list-disc space-y-1 pl-5">
                @foreach($validationErrors as $item)<li>{{ $item }}</li>@endforeach
            </ul>
        </section>
    @endif

    @if($batch)
        <section class="space-y-4 rounded-xl border border-gray-200 bg-white p-4 shadow-sm sm:p-6">
            <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h2 class="text-xl font-bold text-gray-900">Vista previa de diferencias</h2>
                    <p class="mt-1 break-words text-sm text-gray-500">{{ $batch->filename }} · {{ $batch->row_count }} filas · {{ $batch->created_at->format('d/m/Y H:i') }}</p>
                </div>
                <span class="self-start rounded-full bg-blue-100 px-3 py-1 text-xs font-semibold text-blue-800">Sin aplicar</span>
            </div>
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                <div class="rounded-lg bg-gray-50 p-3"><p class="text-xs text-gray-500">Filas</p><p class="text-xl font-bold">{{ $batch->row_count }}</p></div>
                <div class="rounded-lg bg-blue-50 p-3"><p class="text-xs text-blue-700">Con cambios</p><p class="text-xl font-bold">{{ $totalChanged }}</p></div>
                <div class="rounded-lg bg-green-50 p-3"><p class="text-xs text-green-700">Aumentan</p><p class="text-xl font-bold">{{ $totalIncreased }}</p></div>
                <div class="rounded-lg bg-red-50 p-3"><p class="text-xs text-red-700">Disminuyen</p><p class="text-xl font-bold">{{ $totalDecreased }}</p></div>
            </div>

            <div role="region" aria-label="Detalle de comparación" tabindex="0" class="overflow-x-auto rounded-lg border border-gray-200">
                <table class="min-w-[720px] w-full divide-y divide-gray-100 text-sm">
                    <thead class="bg-gray-50 text-left text-xs uppercase text-gray-600">
                        <tr>
                            <th class="px-4 py-3">Producto / lote</th>
                            <th class="px-4 py-3 text-right">Sistema</th>
                            <th class="px-4 py-3 text-right">Excel</th>
                            <th class="px-4 py-3 text-right">Diferencia</th>
                            <th class="px-4 py-3">Resultado</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($previewRows as $row)
                            <tr>
                                <td class="px-4 py-3">
                                    <span class="block font-medium">{{ $row['product_name'] }}</span>
                                    @if($row['lot_code'])<span class="text-xs text-gray-500">Lote {{ $row['lot_code'] }}</span>@endif
                                </td>
                                <td class="px-4 py-3 text-right tabular-nums">{{ number_format($row['current'], 2, ',', '.') }}</td>
                                <td class="px-4 py-3 text-right tabular-nums">{{ number_format($row['target'], 2, ',', '.') }}</td>
                                <td class="px-4 py-3 text-right font-semibold tabular-nums {{ $row['difference'] > 0 ? 'text-green-700' : ($row['difference'] < 0 ? 'text-red-700' : 'text-gray-500') }}">
                                    {{ $row['difference'] > 0 ? '+' : '' }}{{ number_format($row['difference'], 2, ',', '.') }}
                                </td>
                                <td class="px-4 py-3">{{ $row['difference'] > 0 ? 'Sumar' : ($row['difference'] < 0 ? 'Restar' : 'Sin cambios') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if($batch->row_count > 50)
                <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                    <p class="text-xs text-gray-600">Página {{ $previewPage }} de {{ (int) ceil($batch->row_count / 50) }} · Las {{ $batch->row_count }} filas se incluirán al confirmar.</p>
                    <div class="flex gap-2">
                        <button type="button" wire:click="previousPreviewPage" @disabled($previewPage <= 1) class="min-h-11 rounded-lg border px-4 py-2 text-sm disabled:opacity-40">Anterior</button>
                        <button type="button" wire:click="nextPreviewPage" @disabled($previewPage >= ceil($batch->row_count / 50)) class="min-h-11 rounded-lg border px-4 py-2 text-sm disabled:opacity-40">Siguiente</button>
                    </div>
                </div>
            @endif

            <div class="rounded-lg border border-amber-200 bg-amber-50 p-4">
                <label class="flex cursor-pointer items-start gap-3 text-sm text-amber-950">
                    <input wire:model="confirmed" type="checkbox" class="mt-1 h-5 w-5 rounded border-amber-500 text-amber-700">
                    <span>Revisé esta comparación y confirmo que las cantidades del Excel son el <strong>stock real contado</strong> del depósito seleccionado. Entiendo que se registrarán ajustes auditables.</span>
                </label>
                <button type="button" wire:click="apply" wire:loading.attr="disabled" wire:target="apply"
                    class="mt-4 min-h-12 w-full rounded-lg bg-red-700 px-5 py-3 font-semibold text-white disabled:opacity-50 sm:w-auto">
                    <span wire:loading.remove wire:target="apply">Confirmar y ajustar stock</span>
                    <span wire:loading wire:target="apply">Aplicando ajustes…</span>
                </button>
            </div>
        </section>
    @endif
</div>
