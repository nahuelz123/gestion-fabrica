<?php

namespace App\Services;

use App\Enums\Channel;
use App\Enums\MovementType;
use App\Models\ProductionOrder;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Reverts only the signed movements belonging to one completed order.
 *
 * Never deletes the order, lots or immutable movement history. If the produced
 * stock has been spent or moved and cannot be withdrawn, the transaction
 * fails without returning any inputs or changing the order.
 */
class ProductionCancellationService
{
    public function __construct(private readonly StockService $stockService) {}

    public function preview(User $user, int $orderId, ?int $keepOrderId = null): array
    {
        if (!$user->isActive() || !$user->isOwner()) {
            return ['success' => false, 'message' => 'Solo el dueño puede anular una orden de producción.'];
        }

        $order = ProductionOrder::where('company_id', $user->company_id)
            ->with('product:id,name')->find($orderId);
        if (!$order) return ['success' => false, 'message' => "No encontré la orden #{$orderId} en tu empresa."];
        if ($order->status !== 'completed') {
            return ['success' => false, 'message' => "La orden #{$orderId} no está completada y no se puede volver a anular."];
        }
        if ($keepOrderId !== null && $this->getKeptOrder($user, $order, $keepOrderId) === null) {
            return ['success' => false, 'message' => "La orden a conservar #{$keepOrderId} debe existir, estar completada y ser del mismo producto."];
        }

        $movements = $this->originalMovements($order);
        if (!$this->hasValidOutput($order, $movements)) {
            return ['success' => false, 'message' => "La orden #{$orderId} no tiene movimientos originales de producción consistentes. Requiere revisión manual."];
        }

        $consumptions = $movements->where('type', MovementType::ProductionConsumption);
        $lines = [
            "Revisión de anulación de orden #{$orderId}:",
            "- Producto: {$order->product->name}.",
            "- Retirar del stock " . $this->number((float) $order->target_quantity) . ' unidades que se habían registrado como producidas.',
        ];
        if ($keepOrderId !== null) {
            $lines[] = "- Conservar intacta la orden #{$keepOrderId}.";
        }
        foreach ($consumptions->groupBy('product_id') as $group) {
            $lines[] = "- Devolver " . $this->number(abs((float)$group->sum('quantity_base'))) .
                ' unidades de ' . ($group->first()->product?->name ?? 'insumo') . ' (al depósito y lote originales).';
        }
        $lines[] = 'Se guardarán movimientos inversos y la orden quedará ANULADA, sin borrar el historial.';
        $lines[] = 'Si el stock producido ya fue retirado o vendido, se rechazará la anulación sin modificar nada.';
        $lines[] = "¿Confirmás que anulamos SOLO la orden #{$orderId}? Respondé «sí» para ejecutar o «cancelar» para salir.";

        return ['success' => true, 'message' => implode("\n", $lines)];
    }

    public function cancel(User $user, int $orderId, ?int $keepOrderId = null, Channel $channel = Channel::Telegram): array
    {
        if (!$user->isActive() || !$user->isOwner()) {
            return ['success' => false, 'message' => 'Solo el dueño puede anular una orden de producción.'];
        }
        if ($orderId <= 0 || ($keepOrderId !== null && $keepOrderId <= 0)) {
            return ['success' => false, 'message' => 'Indicá números de orden válidos.'];
        }

        try {
            return DB::transaction(function () use ($user, $orderId, $keepOrderId, $channel) {
                $user = User::where('company_id', $user->company_id)
                    ->whereKey($user->id)->lockForUpdate()->firstOrFail();
                if (!$user->isActive() || !$user->isOwner()) {
                    throw new InvalidArgumentException('Solo el dueño puede anular una orden.');
                }

                $order = ProductionOrder::where('company_id', $user->company_id)
                    ->whereKey($orderId)->lockForUpdate()->first();
                if (!$order) throw new InvalidArgumentException("No existe la orden #{$orderId}.");
                if ($order->status !== 'completed') {
                    throw new InvalidArgumentException("La orden #{$orderId} ya fue anulada o no está completada.");
                }
                if ($keepOrderId !== null && $this->getKeptOrder($user, $order, $keepOrderId) === null) {
                    throw new InvalidArgumentException("La orden #{$keepOrderId} a conservar no es válida.");
                }

                $movements = $this->originalMovements($order);
                if (!$this->hasValidOutput($order, $movements)) {
                    throw new InvalidArgumentException('Los movimientos originales de esa orden no están completos.');
                }

                $output = $movements->first(fn ($movement) => $movement->type === MovementType::ProductionOutput);
                // If an output is no longer in the same warehouse/lot, refuse the
                // entire cancellation. StockService verifies its availability.
                $this->reverseMovement($user, $order, $output, MovementType::AdjustmentOut, $channel);

                foreach ($movements->where('type', MovementType::ProductionConsumption) as $consumption) {
                    $this->reverseMovement($user, $order, $consumption, MovementType::AdjustmentIn, $channel);
                }

                $order->update(['status' => 'cancelled']);

                return [
                    'success' => true,
                    'message' => "Orden #{$orderId} anulada. Se retiraron " .
                        $this->number((float) $order->target_quantity) .
                        ' unidades del producto final y se devolvieron los insumos registrados en sus depósitos y lotes originales.' .
                        ($keepOrderId !== null ? " La orden #{$keepOrderId} se conservó intacta." : '') .
                        ' Los movimientos de reversión quedaron en el historial.',
                ];
            }, 3);
        } catch (\Throwable $error) {
            report($error);
            return [
                'success' => false,
                'message' => "No pude anular la orden #{$orderId}. No se realizó una anulación parcial. " .
                    'Verificá que las unidades producidas continúen disponibles en el mismo depósito y lote; si hubo ventas o traslados, hace falta una conciliación manual.',
            ];
        }
    }

    private function getKeptOrder(User $user, ProductionOrder $cancelled, int $keepOrderId): ?ProductionOrder
    {
        if ($keepOrderId === $cancelled->id) return null;

        return ProductionOrder::where('company_id', $user->company_id)
            ->whereKey($keepOrderId)
            ->where('product_id', $cancelled->product_id)
            ->where('status', 'completed')->first();
    }

    private function originalMovements(ProductionOrder $order): \Illuminate\Support\Collection
    {
        return StockMovement::where('company_id', $order->company_id)
            ->where('reference_type', ProductionOrder::class)
            ->where('reference_id', $order->id)
            ->whereIn('type', [
                MovementType::ProductionConsumption->value,
                MovementType::ProductionOutput->value,
            ])
            ->with('product:id,name')
            ->orderBy('id')->get();
    }

    private function hasValidOutput(ProductionOrder $order, \Illuminate\Support\Collection $movements): bool
    {
        $outputs = $movements->where('type', MovementType::ProductionOutput);
        if ($outputs->count() !== 1) return false;
        $output = $outputs->first();

        return (int)$output->product_id === (int)$order->product_id
            && (int)$output->warehouse_id === (int)$order->warehouse_id
            && abs((float)$output->quantity_base - (float)$order->target_quantity) < 0.001
            && $movements->where('type', MovementType::ProductionConsumption)
                ->every(fn ($movement) => (float)$movement->quantity_base < 0);
    }

    private function reverseMovement(
        User $user,
        ProductionOrder $order,
        StockMovement $original,
        MovementType $reversalType,
        Channel $channel,
    ): void {
        $this->stockService->registerMovement([
            'company_id' => $order->company_id,
            'product_id' => $original->product_id,
            'warehouse_id' => $original->warehouse_id,
            'lot_id' => $original->lot_id,
            'type' => $reversalType,
            'quantity_base' => abs((float)$original->quantity_base),
            'user_id' => $user->id,
            'channel' => $channel,
            'reference_type' => ProductionOrder::class,
            'reference_id' => $order->id,
            'reason' => "Anulación orden #{$order->id}; reversión movimiento #{$original->id}",
        ]);
    }

    private function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
