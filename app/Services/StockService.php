<?php

namespace App\Services;

use App\Enums\Channel;
use App\Enums\MovementType;
use App\Models\Product;
use App\Models\ProductPresentation;
use App\Models\Stock;
use App\Models\StockLot;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use Exception;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class StockService
{
    public function registerMovement(array $data): StockMovement
    {
        return DB::transaction(function () use ($data) {
            $companyId = (int) ($data['company_id'] ?? 0);
            $productId = (int) ($data['product_id'] ?? 0);
            $warehouseId = (int) ($data['warehouse_id'] ?? 0);
            $userId = (int) ($data['user_id'] ?? 0);
            $type = $data['type'] ?? null;
            $channel = $data['channel'] ?? Channel::Web;
            $rawQuantity = (float) ($data['quantity_base'] ?? 0);
            if ($companyId <= 0 || $productId <= 0 || $warehouseId <= 0 || $userId <= 0) throw new InvalidArgumentException('Faltan datos obligatorios para registrar el movimiento de stock.');
            if (!$type instanceof MovementType) throw new InvalidArgumentException('El tipo de movimiento de stock no es válido.');
            if (!$channel instanceof Channel) throw new InvalidArgumentException('El canal del movimiento de stock no es válido.');
            if (!is_finite($rawQuantity) || abs($rawQuantity) <= 0) throw new InvalidArgumentException('La cantidad del movimiento debe ser mayor a cero.');

            $product = Product::where('company_id', $companyId)->whereKey($productId)->lockForUpdate()->first();
            if (!$product) throw new InvalidArgumentException('El producto no pertenece a la empresa activa.');
            if (!Warehouse::where('company_id', $companyId)->whereKey($warehouseId)->exists()) throw new InvalidArgumentException('El depósito no pertenece a la empresa activa.');
            if (!User::where('company_id', $companyId)->whereKey($userId)->exists()) throw new InvalidArgumentException('El usuario que registra el movimiento no pertenece a la empresa activa.');

            $presentationId = isset($data['presentation_id']) && $data['presentation_id'] !== null ? (int) $data['presentation_id'] : null;
            if ($presentationId && !ProductPresentation::where('product_id', $productId)->whereKey($presentationId)->exists()) {
                throw new InvalidArgumentException('La presentación no corresponde al producto seleccionado.');
            }
            if (app()->environment('testing') && env('TEST_CONCURRENCY_SLEEP')) sleep((int) env('TEST_CONCURRENCY_SLEEP'));

            $lotId = isset($data['lot_id']) && $data['lot_id'] !== null && $data['lot_id'] !== '' ? (int) $data['lot_id'] : null;
            if ($product->requires_lot) {
                if ($lotId) {
                    if (!StockLot::where('company_id', $companyId)->where('product_id', $productId)->whereKey($lotId)->exists()) {
                        throw new InvalidArgumentException('El lote no corresponde al producto o a la empresa activa.');
                    }
                } else {
                    if ($type->isOutbound()) throw new InvalidArgumentException('Para retirar este producto tenés que seleccionar un lote existente.');
                    $lotCode = trim((string) data_get($data, 'lot_data.lot_code', ''));
                    if ($lotCode === '') throw new InvalidArgumentException('El producto requiere un código de lote.');
                    $lot = StockLot::where('company_id', $companyId)->where('product_id', $productId)->where('lot_code', $lotCode)->first();
                    if (!$lot) {
                        $lot = StockLot::create([
                            'company_id' => $companyId, 'product_id' => $productId, 'lot_code' => $lotCode,
                            'production_date' => data_get($data, 'lot_data.production_date'), 'entry_date' => now()->toDateString(),
                            'expiration_date' => data_get($data, 'lot_data.expiration_date'), 'initial_quantity' => abs($rawQuantity),
                        ]);
                    }
                    $lotId = $lot->id;
                }
            } else $lotId = null;

            $quantityBase = abs($rawQuantity);
            if ($type->isOutbound()) $quantityBase *= -1;
            $stock = Stock::where('company_id', $companyId)->where('product_id', $productId)->where('warehouse_id', $warehouseId)
                ->where('lot_id', $lotId)->lockForUpdate()->first();
            if (!$stock) {
                if ($quantityBase < 0) throw new Exception("Stock insuficiente para el producto '{$product->name}'. Disponible: 0. Requerido: " . abs($quantityBase));
                $stock = Stock::create(['company_id'=>$companyId,'product_id'=>$productId,'warehouse_id'=>$warehouseId,'lot_id'=>$lotId,'quantity'=>0]);
            }
            $newQuantity = (float) $stock->quantity + $quantityBase;
            if ($newQuantity < -0.000001) throw new Exception("Stock insuficiente para el producto '{$product->name}'. Disponible: {$stock->quantity}. Requerido: " . abs($quantityBase));

            $movement = StockMovement::create([
                'company_id'=>$companyId,'product_id'=>$productId,'warehouse_id'=>$warehouseId,'lot_id'=>$lotId,'type'=>$type,
                'quantity_base'=>$quantityBase,'presentation_id'=>$presentationId,'presentation_quantity'=>$data['presentation_quantity'] ?? null,
                'reference_type'=>$data['reference_type'] ?? null,'reference_id'=>$data['reference_id'] ?? null,
                'user_id'=>$userId,'channel'=>$channel,'reason'=>isset($data['reason']) ? mb_substr((string)$data['reason'],0,2000) : null,'created_at'=>now(),
            ]);
            $stock->update(['quantity' => max(0, $newQuantity)]);
            return $movement;
        });
    }
}
