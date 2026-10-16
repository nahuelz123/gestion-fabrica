<?php

namespace App\Services;

use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Builds a CSV stock recount template without allowing spreadsheet formulas. */
class StockTemplateExportService
{
    public function download(User $user, int $warehouseId): StreamedResponse
    {
        if (!$user->isActive() || !$user->canManageStock()) {
            throw new InvalidArgumentException('No tenés permiso para exportar el inventario.');
        }
        if (!Warehouse::where('company_id', $user->company_id)->whereKey($warehouseId)->exists()) {
            throw new InvalidArgumentException('El depósito seleccionado no pertenece a tu empresa.');
        }

        $products = Product::where('company_id', $user->company_id)
            ->where('status', 'active')
            ->with(['stocks' => fn ($query) => $query->where('warehouse_id', $warehouseId),
                'stocks.lot:id,lot_code'])
            ->orderBy('name')->get();

        return response()->streamDownload(static function () use ($products) {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, ['Código', 'Producto', 'Cantidad', 'Lote', 'Presentación'], ';', '"', '');
            // Prevent Excel from interpreting user-provided names as spreadsheet formulas.
            $safe = static fn ($value) => preg_match('/^[\\s]*[=+@-]/u', (string)$value)
                ? "'" . (string)$value
                : (string)$value;
            foreach ($products as $product) {
                if ($product->requires_lot) {
                    foreach ($product->stocks as $stock) {
                        if (!$stock->lot) continue;
                        fputcsv($handle, [
                            $safe($product->internal_code), $safe($product->name),
                            (string)$stock->quantity, $safe($stock->lot->lot_code), '',
                        ], ';', '"', '');
                    }
                } else {
                    fputcsv($handle, [
                        $safe($product->internal_code), $safe($product->name),
                        (string)$product->stocks->sum('quantity'), '', '',
                    ], ';', '"', '');
                }
            }
            fclose($handle);
        }, 'recuento-stock-rapi-burguer.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
