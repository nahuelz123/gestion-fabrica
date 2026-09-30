<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductAlias;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Throwable;

class AuthorizedBotActionExecutor extends BulkBotActionExecutor
{
    public function execute(User $user, string $chatId, array $action, array $context): array
    {
        $name = $action['name'] ?? null;
        if (!$user->canUseBotAction(is_string($name) ? $name : null)) {
            return [
                'success' => false,
                'message' => $user->isManager()
                    ? 'Tu rol de encargado sólo permite consultar y gestionar stock.'
                    : 'No tenés permiso para realizar esa operación.',
            ];
        }

        try {
            return match ($name) {
                'create_product' => $this->executeCreateProductSafe($user, (array) ($action['arguments'] ?? [])),
                'update_product' => $this->executeUpdateProductSafe($user, (array) ($action['arguments'] ?? [])),
                'create_recipe', 'update_recipe' => $this->executeUpsertRecipeSafe($user, (array) ($action['arguments'] ?? [])),
                default => parent::execute($user, $chatId, $action, $context),
            };
        } catch (Throwable $e) {
            report($e);
            return ['success' => false, 'message' => 'No pude completar la operación debido a un problema interno.'];
        }
    }

    private function executeCreateProductSafe(User $user, array $args): array
    {
        $name = trim((string) ($args['name'] ?? $args['product_name'] ?? ''));
        if ($name === '') return ['success'=>false,'message'=>'Me falta el nombre del producto.'];

        $type = (string) ($args['type'] ?? 'raw_material');
        if (!in_array($type, ['raw_material', 'finished_product'], true)) $type = 'raw_material';
        $presentation = trim((string) ($args['presentation_name'] ?? $args['presentation'] ?? 'unidad')) ?: 'unidad';

        $exists = Product::where('company_id', $user->company_id)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->exists();
        if ($exists) return ['success'=>false,'message'=>"Ya existe un producto llamado {$name}."];

        $product = app(ProductService::class)->createProduct($user->company_id, [
            'name'=>$name,'presentation'=>$presentation,'type'=>$type,
            'barcode'=>$args['barcode'] ?? null,
            'requires_lot'=>(bool)($args['requires_lot'] ?? false),
            'requires_expiration'=>(bool)($args['requires_expiration'] ?? false),
            'shelf_life_days'=>isset($args['shelf_life_days']) ? (int)$args['shelf_life_days'] : null,
        ]);

        if (isset($args['min_stock']) && is_numeric($args['min_stock'])) {
            $product->update(['min_stock'=>max(0, (float)$args['min_stock'])]);
        }

        return ['success'=>true,'message'=>"Listo. Creé {$product->name} y generé su código interno automáticamente."];
    }

    private function executeUpdateProductSafe(User $user, array $args): array
    {
        $lookup = trim((string) ($args['product_name'] ?? $args['name'] ?? ''));
        if ($lookup === '') return ['success'=>false,'message'=>'Decime qué producto querés actualizar.'];
        $resolved = $this->resolveProduct($user->company_id, $lookup);
        if (!$resolved['success']) return $resolved;
        /** @var Product $product */
        $product = $resolved['product'];

        $updates = [];
        if (!empty($args['new_name'])) $updates['name'] = mb_substr(trim((string)$args['new_name']), 0, 255);
        if (array_key_exists('min_stock', $args) && is_numeric($args['min_stock'])) $updates['min_stock'] = max(0, (float)$args['min_stock']);
        if ($product->type->value === 'finished_product') {
            if (array_key_exists('barcode', $args)) $updates['barcode'] = blank($args['barcode']) ? null : mb_substr((string)$args['barcode'],0,255);
            if (array_key_exists('shelf_life_days', $args) && is_numeric($args['shelf_life_days'])) $updates['shelf_life_days'] = max(1,(int)$args['shelf_life_days']);
        }
        $presentationName = trim((string)($args['presentation_name'] ?? ''));

        if (!$updates && $presentationName === '') return ['success'=>false,'message'=>'No encontré ningún dato para actualizar.'];

        DB::transaction(function () use ($product, $updates, $presentationName) {
            if ($updates) $product->update($updates);
            if ($presentationName !== '') {
                $presentation = $product->presentations()->where('is_purchase_default', true)->first() ?? $product->presentations()->first();
                if ($presentation) $presentation->update(['name'=>mb_substr($presentationName,0,255)]);
                $product->update(['presentation'=>mb_substr($presentationName,0,255)]);
            }
        });

        return ['success'=>true,'message'=>"Actualicé {$product->fresh()->name}."];
    }

    private function executeUpsertRecipeSafe(User $user, array $args): array
    {
        $name = trim((string)($args['product_name'] ?? ''));
        $items = is_array($args['items'] ?? null) ? $args['items'] : [];
        $yield = (float)($args['yield_quantity'] ?? 1);
        if ($name === '' || !$items || $yield <= 0) {
            return ['success'=>false,'message'=>'Necesito el producto final, el rendimiento y al menos un ingrediente para guardar la receta.'];
        }

        $resolved = $this->resolveProduct($user->company_id, $name, true);
        if (!$resolved['success']) return $resolved;
        /** @var Product $finished */
        $finished = $resolved['product'];
        if ($finished->type->value !== 'finished_product') {
            return ['success'=>false,'message'=>"{$finished->name} no está marcado como producto terminado."];
        }

        $prepared = [];
        foreach ($items as $item) {
            if (!is_array($item)) continue;
            $ingredientName = trim((string)($item['product_name'] ?? ''));
            $quantity = (float)($item['quantity'] ?? $item['quantity_base'] ?? 0);
            if ($ingredientName === '' || $quantity <= 0) return ['success'=>false,'message'=>'Cada ingrediente necesita nombre y cantidad mayor a cero.'];
            $ingredientResult = $this->resolveProduct($user->company_id, $ingredientName);
            if (!$ingredientResult['success']) return $ingredientResult;
            $ingredient = $ingredientResult['product'];
            if ($ingredient->id === $finished->id) return ['success'=>false,'message'=>'Un producto no puede ser ingrediente de sí mismo.'];
            if (isset($prepared[$ingredient->id])) return ['success'=>false,'message'=>"{$ingredient->name} está repetido en la receta."];
            $prepared[$ingredient->id] = $quantity;
        }
        if (!$prepared) return ['success'=>false,'message'=>'No encontré ingredientes válidos para guardar.'];

        DB::transaction(function () use ($user, $finished, $yield, $prepared, $args) {
            $recipe = Recipe::updateOrCreate(
                ['product_id'=>$finished->id],
                ['company_id'=>$user->company_id,'yield_quantity'=>$yield,'notes'=>isset($args['notes']) ? mb_substr((string)$args['notes'],0,2000) : null]
            );
            $recipe->items()->delete();
            foreach ($prepared as $productId=>$quantity) {
                $recipe->items()->create(['product_id'=>$productId,'quantity_base'=>$quantity]);
            }
        });

        return ['success'=>true,'message'=>"Receta de {$finished->name} guardada correctamente."];
    }

    private function resolveProduct(int $companyId, string $input, bool $finishedFirst = false): array
    {
        $input = trim($input);
        $query = Product::where('company_id',$companyId);
        if ($finishedFirst) $query->orderByRaw("CASE WHEN type = 'finished_product' THEN 0 ELSE 1 END");
        $exact = (clone $query)->whereRaw('LOWER(name) = ?', [mb_strtolower($input)])->first();
        if ($exact) return ['success'=>true,'product'=>$exact];

        $alias = ProductAlias::where('company_id',$companyId)->whereRaw('LOWER(alias) = ?', [mb_strtolower($input)])->with('product')->first();
        if ($alias?->product) return ['success'=>true,'product'=>$alias->product];

        $matches = (clone $query)->where('name','like','%'.$input.'%')->limit(3)->get();
        if ($matches->isEmpty()) return ['success'=>false,'message'=>"No encontré ningún producto llamado '{$input}' en tu empresa."];
        if ($matches->count() > 1) return ['success'=>false,'message'=>"Encontré varios productos que coinciden con '{$input}'. ¿Cuál querés?"];
        return ['success'=>true,'product'=>$matches->first()];
    }
}
