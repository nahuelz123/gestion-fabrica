<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductPresentation;
use App\Models\Unit;
use InvalidArgumentException;

class ProductService
{
    public function createProduct(int $companyId, array $data): Product
    {
        $name = trim((string) ($data['name'] ?? ''));
        $presentationName = trim((string) ($data['presentation'] ?? ''));
        if ($companyId <= 0 || $name === '' || $presentationName === '') {
            throw new InvalidArgumentException('Faltan datos obligatorios para crear el producto.');
        }

        $type = (string) ($data['type'] ?? 'raw_material');
        if (!in_array($type, ['raw_material', 'finished_product'], true)) {
            throw new InvalidArgumentException('El tipo de producto no es válido.');
        }

        $unit = Unit::firstOrCreate(
            ['abbreviation' => 'u'],
            ['name' => 'Unidad', 'type' => 'count']
        );

        $categoryName = $type === 'raw_material' ? 'Insumos' : 'Producto Terminado';
        $category = ProductCategory::firstOrCreate(
            ['company_id' => $companyId, 'name' => $categoryName]
        );

        $productData = [
            'company_id' => $companyId,
            'category_id' => $category->id,
            'type' => $type,
            'name' => mb_substr($name, 0, 255),
            'presentation' => mb_substr($presentationName, 0, 255),
            'cost' => 0,
            'price' => 0,
            'base_unit_id' => $unit->id,
        ];

        if ($type === 'finished_product') {
            $productData['barcode'] = blank($data['barcode'] ?? null) ? null : mb_substr((string) $data['barcode'], 0, 255);
            $productData['requires_lot'] = (bool) ($data['requires_lot'] ?? false);
            $productData['requires_expiration'] = (bool) ($data['requires_expiration'] ?? false);
            $productData['shelf_life_days'] = $productData['requires_expiration'] && !empty($data['shelf_life_days'])
                ? max(1, (int) $data['shelf_life_days'])
                : null;
        } else {
            // Los insumos se mantienen simples: el control de lote/vencimiento y
            // código de barras del alta rápida se reserva al producto final.
            $productData['barcode'] = null;
            $productData['requires_lot'] = false;
            $productData['requires_expiration'] = false;
            $productData['shelf_life_days'] = null;
        }

        $product = Product::create($productData);

        ProductPresentation::create([
            'product_id' => $product->id,
            'conversion_factor' => 1.0000,
            'name' => $productData['presentation'],
            'is_purchase_default' => true,
            'is_sale_default' => true,
            'barcode' => $type === 'finished_product' ? $productData['barcode'] : null,
        ]);

        return $product;
    }

    /**
     * Convierte una cantidad ingresada en una presentación (caja, paquete,
     * barra, etc.) a la unidad base que usa el ledger de stock.
     */
    public function resolveToBaseUnit(Product $product, ?ProductPresentation $presentation, float $quantity): array
    {
        if (!is_finite($quantity) || $quantity <= 0) {
            throw new InvalidArgumentException('La cantidad debe ser mayor a cero.');
        }

        $factor = 1.0;
        $presentationId = null;
        $presentationQuantity = null;

        if ($presentation) {
            if ((int) $presentation->product_id !== (int) $product->id) {
                throw new InvalidArgumentException('La presentación no corresponde al producto seleccionado.');
            }

            $factor = (float) $presentation->conversion_factor;
            if (!is_finite($factor) || $factor <= 0) {
                throw new InvalidArgumentException('La presentación tiene un factor de conversión inválido.');
            }

            $presentationId = $presentation->id;
            $presentationQuantity = $quantity;
        }

        return [
            'quantity_base' => round($quantity * $factor, 4),
            'presentation_id' => $presentationId,
            'presentation_quantity' => $presentationQuantity,
        ];
    }
}
