<?php

namespace App\Services;

use App\Enums\Channel;
use App\Enums\MovementType;
use App\Models\Product;
use App\Models\Stock;
use App\Models\StockLot;
use App\Models\Warehouse;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Sets a stock count to an absolute quantity, writing only the signed delta.
 * Must be called under a transaction to make the recount safe under contention.
 */
class StockRecountService
{
    public function __construct(private readonly StockService $movements) {}

    public function setAbsolute(
        User $user,
        int $warehouseId,
        int $productId,
        ?int $lotId,
        float $target,
        ?float $expectedCurrent = null,
        string $reason = 'Recuento físico de stock',
    ): float {
        if (!is_finite($target) || $target < 0 || $target > 999999999) {
            throw new InvalidArgumentException('Cantidad de stock inválida.');
        }
        if (!$user->isActive() || !$user->canManageStock()) {
            throw new InvalidArgumentException('No tenés permisos para ajustar el stock.');
        }
        return DB::transaction(function () use ($user, $warehouseId, $productId, $lotId, $target, $expectedCurrent, $reason) {
            $product = Product::where('company_id', $user->company_id)
                ->where('status', 'active')->whereKey($productId)->lockForUpdate()->firstOrFail();
            if ((bool)$product->requires_lot !== ($lotId !== null)) {
                throw new InvalidArgumentException('Para productos loteados hay que indicar un lote existente.');
            }
            if (!Warehouse::where('company_id', $user->company_id)->whereKey($warehouseId)->exists()) {
                throw new InvalidArgumentException('El depósito no pertenece a esta empresa.');
            }
            if ($lotId !== null && !StockLot::where('company_id', $user->company_id)
                ->where('product_id', $productId)->whereKey($lotId)->exists()) {
                throw new InvalidArgumentException('El lote indicado no corresponde a este producto.');
            }
            $current = (float) Stock::where('company_id', $user->company_id)
                ->where('product_id', $productId)->where('warehouse_id', $warehouseId)
                ->where('lot_id', $lotId)->sum('quantity');
            if ($expectedCurrent !== null && abs($expectedCurrent - $current) >= 0.005) {
                throw new InvalidArgumentException("El stock de {$product->name} cambió desde la vista previa. Volvé a subir el Excel.");
            }
            $delta = round($target - $current, 2);
            if (abs($delta) < 0.005) return 0.0;

            $this->movements->registerMovement([
                'company_id' => $user->company_id,
                'product_id' => $productId, 'warehouse_id' => $warehouseId,
                'lot_id' => $lotId,
                'type' => $delta > 0 ? MovementType::AdjustmentIn : MovementType::AdjustmentOut,
                'quantity_base' => abs($delta),
                'user_id' => $user->id, 'channel' => Channel::Web,
                'reason' => $reason,
            ]);
            return $delta;
        }, 3);
    }
}
