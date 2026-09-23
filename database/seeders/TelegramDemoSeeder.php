<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Company;
use App\Models\User;
use App\Models\Unit;
use App\Models\ProductCategory;
use App\Models\Product;
use App\Models\ProductPresentation;
use App\Models\Recipe;
use App\Models\RecipeItem;
use App\Models\Warehouse;
use App\Models\Stock;

class TelegramDemoSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Resolve Company and User
        $user = User::first();
        if (!$user) {
            $this->command->error("No hay usuarios en la base de datos.");
            return;
        }
        $company = $user->company;
        $warehouse = Warehouse::firstOrCreate(
            ['company_id' => $company->id],
            ['name' => 'Depósito Principal']
        );

        // 2. Units
        $unitU = Unit::firstOrCreate(['abbreviation' => 'u', 'type' => 'count'], ['name' => 'Unidad']);

        // 3. Categories
        $catEnv = ProductCategory::firstOrCreate(['company_id' => $company->id, 'name' => 'Envasado']);
        $catMP = ProductCategory::firstOrCreate(['company_id' => $company->id, 'name' => 'Materias Primas']);
        $catPT = ProductCategory::firstOrCreate(['company_id' => $company->id, 'name' => 'Producto Terminado']);

        // Helpers
        $createProd = function($name, $cat, $type, $presName, $convFactor, $stockQty = 0) use ($company, $unitU, $warehouse) {
            $product = Product::firstOrCreate(
                ['company_id' => $company->id, 'name' => $name],
                [
                    'category_id' => $cat->id,
                    'internal_code' => 'DEMO-' . strtoupper(substr(md5($name), 0, 4)),
                    'base_unit_id' => $unitU->id,
                    'type' => $type,
                    'requires_lot' => false
                ]
            );

            ProductPresentation::firstOrCreate(
                ['product_id' => $product->id, 'name' => $presName],
                ['conversion_factor' => $convFactor, 'is_purchase_default' => true, 'is_sale_default' => true]
            );

            if ($stockQty > 0) {
                // $stockQty is given in PRESENTATIONS, we must save in BASE UNIT in Stock table
                $baseQty = $stockQty * $convFactor;
                $stock = Stock::firstOrNew(['company_id' => $company->id, 'warehouse_id' => $warehouse->id, 'product_id' => $product->id]);
                $stock->quantity += $baseQty;
                $stock->save();
            }

            return $product;
        };

        // 1. PRODUCTOS / INSUMOS DE ENVASADO
        $bolsitasH = $createProd('Bolsitas para hamburguesas', $catEnv, 'raw_material', 'bolsa', 1, 500);
        $bolsitasE = $createProd('Bolsitas etiquetadas', $catEnv, 'raw_material', 'bolsa', 1, 300);
        $cajasPT = $createProd('Cajas para producto terminado', $catEnv, 'raw_material', 'caja de 24', 24, 100);
        $papelM = $createProd('Papel manteca', $catEnv, 'raw_material', 'paquete', 100, 10); // Assume 100 sheets per package -> 1000 total
        $guantes = $createProd('Guantes de látex', $catEnv, 'raw_material', 'caja', 100, 10);
        $cofias = $createProd('Cofias', $catEnv, 'raw_material', 'paquete', 100, 10);
        $bolsasC = $createProd('Bolsas de consorcio', $catEnv, 'raw_material', 'bolsa', 1, 100);
        $etiqCB = $createProd('Etiqueta Bacon y Cheddar', $catEnv, 'raw_material', 'unidad', 1, 100);
        $etiqC = $createProd('Etiqueta Cheddar', $catEnv, 'raw_material', 'unidad', 1, 100);
        $etiqCL = $createProd('Etiqueta Lomito y Cheddar', $catEnv, 'raw_material', 'unidad', 1, 100);
        $etiqP = $createProd('Etiqueta Pollo y Cheddar', $catEnv, 'raw_material', 'unidad', 1, 100);
        $etiqJQ = $createProd('Etiqueta Jam' . "\xc3\xb3" . 'n y Queso', $catEnv, 'raw_material', 'unidad', 1, 100);

        // 2. MATERIAS PRIMAS
        $pan = $createProd('Caja de pan', $catMP, 'raw_material', 'caja de 9 bolsas de 4', 36, 20);
        $fCheddar = $createProd('Fiambre cheddar', $catMP, 'raw_material', 'barra de 200 fetas', 200, 5);
        $bacon = $createProd('Bacon', $catMP, 'raw_material', 'pieza', 50, 30); // 1 pieza = 50 fetas (assumed)
        $lomito = $createProd('Lomito', $catMP, 'raw_material', 'pieza', 50, 30); // 1 pieza = 50 fetas (assumed)
        $qFiambre = $createProd('Queso fiambre', $catMP, 'raw_material', 'barra', 100, 5); // 1 barra = 100 fetas (assumed)
        $jFiambre = $createProd('Jamón fiambre', $catMP, 'raw_material', 'barra', 100, 5); // 1 barra = 100 fetas (assumed)
        $mCarne = $createProd('Medallón de carne', $catMP, 'raw_material', 'caja de 60', 60, 10);
        $mPollo = $createProd('Medallón de pollo', $catMP, 'raw_material', 'caja de 60', 60, 5);

        // 3. PRODUCTOS TERMINADOS (Stock inicial en base units)
        // Cajas de 24.
        $hCheddar = $createProd('Hamburguesa cheddar', $catPT, 'finished_product', 'unidad', 1, 48); // 48 u = 2 cajas
        $hLomito = $createProd('Hamburguesa lomito', $catPT, 'finished_product', 'unidad', 1, 24);
        $hBacon = $createProd('Hamburguesa bacon', $catPT, 'finished_product', 'unidad', 1, 48);
        $hPollo = $createProd('Hamburguesa pollo', $catPT, 'finished_product', 'unidad', 1, 24);
        $hJQ = $createProd('Hamburguesa jamón y queso', $catPT, 'finished_product', 'unidad', 1, 24);

        // 4. RECETAS
        // Para solucionar el límite de decimales (1/24 cajas), definiremos la receta para un lote (yield) de 24 hamburguesas (1 caja).
        $createRecipe = function($prodFin, $ingredients) use ($company) {
            $recipe = Recipe::firstOrCreate(
                ['company_id' => $company->id, 'product_id' => $prodFin->id],
                ['yield_quantity' => 24] // Lote de 24 hamburguesas (1 caja)
            );
            
            // Limpiar items existentes por si se corre de nuevo
            $recipe->items()->delete();

            foreach ($ingredients as $ingId => $qty) {
                RecipeItem::create([
                    'recipe_id' => $recipe->id,
                    'product_id' => $ingId,
                    'quantity_base' => $qty // Base units required for 24 units
                ]);
            }
        };

        // Receta Cheddar (yield = 24 u)
        $createRecipe($hCheddar, [
            $pan->id => 24,
            $mCarne->id => 24,
            $fCheddar->id => 24,
            $papelM->id => 24,
            $bolsitasH->id => 24, // Usamos la común por defecto
            $cajasPT->id => 24, // 24 / conversion_factor(24) = 1 caja real. En DB se guardan base_units. Así que quantity_base = 24 unidades base de caja. Wait, if $cajasPT base_unit is "unidad" y conversion_factor es 24, significa que 1 caja = 24 unidades base. Entonces la receta necesita 24 unidades base. Correcto.
            $etiqC->id => 1 // 1 etiqueta por caja
        ]);

        // Receta Lomito (yield = 24 u)
        $createRecipe($hLomito, [
            $pan->id => 24,
            $mCarne->id => 24,
            $fCheddar->id => 24,
            $lomito->id => 24,
            $papelM->id => 24,
            $bolsitasH->id => 24,
            $cajasPT->id => 24,
            $etiqCL->id => 1
        ]);

        // Receta Bacon (yield = 24 u)
        $createRecipe($hBacon, [
            $pan->id => 24,
            $mCarne->id => 24,
            $fCheddar->id => 24,
            $bacon->id => 24,
            $papelM->id => 24,
            $bolsitasH->id => 24,
            $cajasPT->id => 24,
            $etiqCB->id => 1
        ]);

        // Receta Pollo (yield = 24 u)
        $createRecipe($hPollo, [
            $pan->id => 24,
            $mPollo->id => 24,
            $fCheddar->id => 24,
            $papelM->id => 24,
            $bolsitasH->id => 24,
            $cajasPT->id => 24,
            $etiqP->id => 1
        ]);

        // Receta Jamón y Queso (yield = 24 u)
        $createRecipe($hJQ, [
            $pan->id => 24,
            $mCarne->id => 24,
            $qFiambre->id => 24,
            $jFiambre->id => 24,
            $papelM->id => 24,
            $bolsitasH->id => 24,
            $cajasPT->id => 24,
            $etiqJQ->id => 1
        ]);

        $this->command->info("Datos de demostración cargados exitosamente.");
    }
}
