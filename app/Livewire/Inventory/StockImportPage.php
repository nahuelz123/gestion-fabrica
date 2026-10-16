<?php

namespace App\Livewire\Inventory;

use App\Models\Product;
use App\Models\StockImport;
use App\Models\Warehouse;
use App\Services\StockImportService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;
use Throwable;

#[Layout('layouts.app')]
class StockImportPage extends Component
{
    use WithFileUploads;

    public $spreadsheet = null;
    public string $warehouseId = '';
    public ?int $batchId = null;
    public bool $confirmed = false;
    public array $validationErrors = [];
    public ?string $message = null;
    public ?string $error = null;

    public function mount(): void
    {
        Gate::authorize('manage-stock');
        $this->warehouseId = (string) (Warehouse::where('company_id', auth()->user()->company_id)
            ->orderBy('id')->value('id') ?? '');
    }

    public function updatedWarehouseId(): void
    {
        $this->batchId = null;
        $this->confirmed = false;
        $this->validationErrors = [];
        $this->message = null;
        $this->error = null;
    }

    public function preview(StockImportService $service): void
    {
        Gate::authorize('manage-stock');
        $this->reset(['message', 'error', 'batchId', 'validationErrors']);
        $this->confirmed = false;

        $this->validate([
            'warehouseId' => [
                'required',
                Rule::exists('warehouses', 'id')
                    ->where(fn ($query) => $query->where('company_id', auth()->user()->company_id)),
            ],
            'spreadsheet' => 'required|file|mimes:xlsx,csv,txt|max:4096',
        ], [
            'spreadsheet.mimes' => 'El archivo tiene que ser .xlsx o .csv.',
            'spreadsheet.max' => 'El archivo no puede superar 4 MB.',
        ]);

        try {
            $result = $service->preview(
                auth()->user(),
                (int) $this->warehouseId,
                $this->spreadsheet->getRealPath(),
                $this->spreadsheet->getClientOriginalName(),
            );
            $this->validationErrors = $result['errors'];
            $this->batchId = $result['batch']?->id;
            if ($this->batchId) {
                $this->message = 'Comparación preparada. Revisá las diferencias antes de confirmar.';
            }
        } catch (Throwable $exception) {
            report($exception);
            $this->error = $exception instanceof \InvalidArgumentException
                ? $exception->getMessage()
                : 'No se pudo procesar el Excel. Comprobá el formato y probá de nuevo.';
        }
    }

    public function apply(StockImportService $service): void
    {
        Gate::authorize('manage-stock');
        if (!$this->confirmed || !$this->batchId) {
            $this->error = 'Marcá la casilla de confirmación antes de modificar el inventario.';
            return;
        }

        try {
            $result = $service->apply(auth()->user(), $this->batchId);
            $this->message = $result['message'];
            $this->error = null;
            $this->batchId = null;
            $this->spreadsheet = null;
            $this->confirmed = false;
        } catch (Throwable $exception) {
            report($exception);
            $this->error = $exception instanceof \InvalidArgumentException
                ? $exception->getMessage()
                : 'No se pudieron aplicar los ajustes. No se hizo ninguna importación parcial.';
            $this->confirmed = false;
        }
    }

    public function downloadTemplate()
    {
        Gate::authorize('manage-stock');

        $products = Product::where('company_id', auth()->user()->company_id)
            ->where('status', 'active')
            ->with(['stocks' => fn ($query) => $query->where('warehouse_id', (int)$this->warehouseId),
                'stocks.lot:id,lot_code'])
            ->orderBy('name')->get();

        return response()->streamDownload(static function () use ($products) {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, ['Código', 'Producto', 'Cantidad', 'Lote', 'Presentación'], ';', '"', '');
            foreach ($products as $product) {
                if ($product->requires_lot) {
                    foreach ($product->stocks as $stock) {
                        if (!$stock->lot) continue;
                        fputcsv($handle, [
                            $product->internal_code, $product->name,
                            (string)$stock->quantity, $stock->lot->lot_code, '',
                        ], ';', '"', '');
                    }
                } else {
                    fputcsv($handle, [
                        $product->internal_code, $product->name,
                        (string)$product->stocks->sum('quantity'), '', '',
                    ], ';', '"', '');
                }
            }
            fclose($handle);
        }, 'recuento-stock-rapi-burguer.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function render()
    {
        Gate::authorize('manage-stock');
        $batch = $this->batchId
            ? StockImport::where('company_id', auth()->user()->company_id)
                ->where('user_id', auth()->id())->where('status', 'preview')
                ->find($this->batchId)
            : null;
        if (!$batch) $this->batchId = null;

        $rows = collect($batch?->rows ?? []);
        return view('livewire.inventory.stock-import-page', [
            'warehouses' => Warehouse::where('company_id', auth()->user()->company_id)
                ->orderBy('name')->get(['id', 'name']),
            'batch' => $batch,
            'previewRows' => $rows->take(100),
            'totalChanged' => $rows->filter(fn ($row) => abs((float)$row['difference']) >= 0.005)->count(),
            'totalIncreased' => $rows->filter(fn ($row) => (float)$row['difference'] >= 0.005)->count(),
            'totalDecreased' => $rows->filter(fn ($row) => (float)$row['difference'] <= -0.005)->count(),
        ]);
    }
}
