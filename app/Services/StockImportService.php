<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Stock;
use App\Models\StockImport;
use App\Models\StockLot;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class StockImportService
{
    public function __construct(
        private readonly StockSpreadsheetReader $reader,
        private readonly StockRecountService $recounts,
    ) {}

    /**
     * Parse, identify and compare a complete file. No inventory movements
     * are performed until the owner explicitly confirms this preview.
     *
     * @return array{batch:StockImport|null,errors:list<string>}
     */
    public function preview(User $user, int $warehouseId, string $path, string $filename): array
    {
        $this->authorize($user);
        Warehouse::where('company_id', $user->company_id)->findOrFail($warehouseId);

        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $fileRows = $this->reader->read($path, $extension);
        $checksum = hash_file('sha256', $path);

        if (StockImport::where('company_id', $user->company_id)
            ->where('warehouse_id', $warehouseId)->where('sha256', $checksum)
            ->where('status', 'applied')->exists()) {
            return [
                'batch' => null,
                'errors' => ['Ese mismo archivo ya fue aplicado en este depósito. Para un nuevo recuento, generá un Excel actualizado.'],
            ];
        }

        $products = Product::where('company_id', $user->company_id)
            ->where('status', 'active')
            ->with('presentations:id,product_id,name,conversion_factor')
            ->get(['id', 'company_id', 'internal_code', 'barcode', 'name', 'requires_lot']);
        $byCode = $products->keyBy(fn ($p) => mb_strtolower(trim((string)$p->internal_code)));
        $byBarcode = $products->filter(fn ($p) => filled($p->barcode))
            ->groupBy(fn ($p) => mb_strtolower(trim((string)$p->barcode)));
        $byName = $products->groupBy(fn ($p) => mb_strtolower(trim((string)$p->name)));

        $errors = [];
        $resolved = [];
        $used = [];
        foreach ($fileRows as $row) {
            $data = $row['data'];
            $line = $row['line'];
            $code = mb_strtolower(trim($data['code'] ?? ''));
            $barcode = mb_strtolower(trim($data['barcode'] ?? ''));
            $name = mb_strtolower(trim($data['name'] ?? ''));

            $candidate = null;
            if ($code !== '') {
                $candidate = $byCode->get($code);
            } elseif ($barcode !== '') {
                $matches = $byBarcode->get($barcode, collect());
                $candidate = $matches->count() === 1 ? $matches->first() : null;
            } elseif ($name !== '') {
                $matches = $byName->get($name, collect());
                $candidate = $matches->count() === 1 ? $matches->first() : null;
            }

            if (!$candidate) {
                $errors[] = "Fila {$line}: producto no encontrado o nombre/código ambiguo. Usá el código interno exacto.";
                continue;
            }
            if ($barcode !== '' && $candidate->barcode !== null && mb_strtolower((string)$candidate->barcode) !== $barcode) {
                $errors[] = "Fila {$line}: el código de barras no coincide con {$candidate->name}.";
                continue;
            }
            if ($name !== '' && mb_strtolower($candidate->name) !== $name && $code === '' && $barcode === '') {
                $errors[] = "Fila {$line}: el nombre del producto no coincide.";
                continue;
            }

            $lotCode = trim($data['lot'] ?? '');
            $lotId = null;
            if ($candidate->requires_lot) {
                if ($lotCode === '') {
                    $errors[] = "Fila {$line}: {$candidate->name} requiere columna Lote con un código existente.";
                    continue;
                }
                $lot = StockLot::where('company_id', $user->company_id)
                    ->where('product_id', $candidate->id)
                    ->where('lot_code', $lotCode)->first();
                if (!$lot) {
                    $errors[] = "Fila {$line}: el lote {$lotCode} de {$candidate->name} no existe. Cargalo primero desde Entrada.";
                    continue;
                }
                $lotId = $lot->id;
            } elseif ($lotCode !== '') {
                $errors[] = "Fila {$line}: {$candidate->name} no utiliza lotes; dejá esa celda vacía.";
                continue;
            }

            $key = $candidate->id . ':' . ($lotId ?? 'none');
            if (isset($used[$key])) {
                $errors[] = "Fila {$line}: {$candidate->name}" . ($lotCode !== '' ? " / {$lotCode}" : '') .
                    " está duplicado (primera aparición: fila {$used[$key]}).";
                continue;
            }
            $used[$key] = $line;

            $quantity = $this->parseQuantity($data['quantity'] ?? '');
            if ($quantity === null) {
                $errors[] = "Fila {$line}: cantidad inválida. Usá números no negativos con hasta 2 decimales.";
                continue;
            }

            $presentation = trim($data['presentation'] ?? '');
            $unitLabel = '';
            if ($presentation !== '') {
                $matches = $candidate->presentations
                    ->filter(fn ($p) => mb_strtolower($p->name) === mb_strtolower($presentation));
                if ($matches->count() !== 1) {
                    $errors[] = "Fila {$line}: presentación «{$presentation}» no encontrada en {$candidate->name}.";
                    continue;
                }
                $factor = (float)$matches->first()->conversion_factor;
                if ($factor <= 0 || !is_finite($factor)) {
                    $errors[] = "Fila {$line}: presentación sin conversión válida.";
                    continue;
                }
                $quantity *= $factor;
                $unitLabel = $presentation;
            }
            if ($quantity > 999999999 || abs(round($quantity, 2) - $quantity) >= 0.005) {
                $errors[] = "Fila {$line}: cantidad convertida fuera de rango o con más de 2 decimales.";
                continue;
            }

            $resolved[] = [
                'line' => $line, 'product_id' => $candidate->id,
                'product_name' => $candidate->name,
                'lot_id' => $lotId, 'lot_code' => $lotCode,
                'source_unit' => $unitLabel,
                'target' => round($quantity, 2),
            ];
        }

        if ($errors) return ['batch' => null, 'errors' => array_slice($errors, 0, 50)];

        $ids = array_column($resolved, 'product_id');
        $existing = Stock::where('company_id', $user->company_id)
            ->where('warehouse_id', $warehouseId)
            ->whereIn('product_id', $ids)
            ->get(['product_id', 'lot_id', 'quantity'])
            ->groupBy(fn ($row) => $row->product_id . ':' . ($row->lot_id ?? 'none'));

        foreach ($resolved as &$row) {
            $key = $row['product_id'] . ':' . ($row['lot_id'] ?? 'none');
            $current = round((float)$existing->get($key, collect())->sum('quantity'), 2);
            $row['current'] = $current;
            $row['difference'] = round($row['target'] - $current, 2);
        }
        unset($row);

        $batch = StockImport::create([
            'company_id' => $user->company_id, 'warehouse_id' => $warehouseId,
            'user_id' => $user->id, 'filename' => mb_substr(basename($filename), 0, 255),
            'sha256' => $checksum, 'status' => 'preview',
            'row_count' => count($resolved), 'rows' => $resolved,
        ]);
        return ['batch' => $batch, 'errors' => []];
    }

    /**
     * Applies all deltas in one transaction, or none if another operation
     * changed even one row after preview.
     */
    public function apply(User $user, int $batchId): array
    {
        $this->authorize($user);

        return DB::transaction(function () use ($user, $batchId) {
            $batch = StockImport::where('company_id', $user->company_id)
                ->where('user_id', $user->id)->whereKey($batchId)->lockForUpdate()->firstOrFail();

            if ($batch->status !== 'preview') {
                throw new InvalidArgumentException('Esta importación ya fue procesada. Volvé a subir un Excel actualizado.');
            }
            if ($batch->created_at->lt(now()->subHours(24))) {
                throw new InvalidArgumentException('La comparación venció después de 24 horas. Volvé a subir el archivo.');
            }
            if (StockImport::where('company_id', $batch->company_id)
                ->where('warehouse_id', $batch->warehouse_id)
                ->where('sha256', $batch->sha256)
                ->where('status', 'applied')->exists()) {
                throw new InvalidArgumentException('Este Excel ya fue aplicado anteriormente.');
            }

            $rows = $batch->rows;
            if (!$rows || count($rows) !== (int)$batch->row_count || count($rows) > StockSpreadsheetReader::MAX_ROWS) {
                throw new InvalidArgumentException('La importación no tiene filas válidas.');
            }

            usort($rows, static fn ($a, $b) => [$a['product_id'], $a['lot_id'] ?? 0] <=> [$b['product_id'], $b['lot_id'] ?? 0]);
            $changed = 0;
            foreach ($rows as $row) {
                $delta = $this->recounts->setAbsolute(
                    $user, $batch->warehouse_id, (int)$row['product_id'],
                    isset($row['lot_id']) ? (int)$row['lot_id'] : null,
                    (float)$row['target'], (float)$row['current'],
                    'Conciliación Excel importación #' . $batch->id . ' fila ' . $row['line']
                );
                if (abs($delta) >= 0.005) $changed++;
            }

            $batch->update(['status' => 'applied', 'applied_at' => now()]);

            return [
                'changed' => $changed, 'total' => count($rows), 'batch_id' => $batch->id,
                'message' => "Se aplicaron {$changed} ajustes sobre {$batch->row_count} filas del Excel. Cada diferencia quedó en el historial.",
            ];
        }, 3);
    }

    private function authorize(User $user): void
    {
        if (!$user->isActive() || !$user->canManageStock()) {
            throw new InvalidArgumentException('No tenés permiso para importar ni ajustar stock.');
        }
    }

    private function parseQuantity(string $input): ?float
    {
        $text = trim(str_replace(["\u{00A0}", ' '], '', $input));
        if ($text === '' || !preg_match('/^\d+(?:[.,]\d+)*$/', $text)) return null;
        if (str_contains($text, ',') && str_contains($text, '.')) {
            $decimal = strrpos($text, ',') > strrpos($text, '.') ? ',' : '.';
            $thousands = $decimal === ',' ? '.' : ',';
            $text = str_replace($thousands, '', $text);
            $text = str_replace($decimal, '.', $text);
        } elseif (preg_match('/^\d{1,3}(?:[.,]\d{3})+$/', $text)) {
            // "1.500" / "1,500" are unambiguously grouped thousands in physical counts.
            $text = str_replace([',', '.'], '', $text);
        } else {
            $text = str_replace(',', '.', $text);
        }
        if (!is_numeric($text)) return null;
        $value = (float)$text;
        if (!is_finite($value) || $value < 0 || $value > 999999999) return null;
        if (abs($value - round($value, 2)) >= 0.000001) return null;
        return $value;
    }
}
