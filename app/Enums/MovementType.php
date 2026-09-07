<?php

namespace App\Enums;

enum MovementType: string
{
    case PurchaseIn = 'purchase_in';
    case SaleOut = 'sale_out';
    case ProductionConsumption = 'production_consumption';
    case ProductionOutput = 'production_output';
    case Waste = 'waste';
    case AdjustmentIn = 'adjustment_in';
    case AdjustmentOut = 'adjustment_out';

    public function label(): string
    {
        return match ($this) {
            self::PurchaseIn => 'Entrada por compra',
            self::SaleOut => 'Salida por venta',
            self::ProductionConsumption => 'Consumo de producción',
            self::ProductionOutput => 'Salida de producción',
            self::Waste => 'Merma',
            self::AdjustmentIn => 'Ajuste manual (Entrada)',
            self::AdjustmentOut => 'Ajuste manual (Salida)',
        };
    }

    /**
     * Whether this movement type subtracts from stock.
     */
    public function isOutbound(): bool
    {
        return match ($this) {
            self::SaleOut, self::ProductionConsumption, self::Waste, self::AdjustmentOut => true,
            default => false,
        };
    }
}
