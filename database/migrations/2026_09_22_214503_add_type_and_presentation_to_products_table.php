<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('type')->default('raw_material')->after('category_id');
            $table->string('presentation')->default('unidad')->after('name');
        });

        // Migrate existing data
        $products = DB::table('products')->get();
        foreach ($products as $product) {
            $category = DB::table('product_categories')->where('id', $product->category_id)->first();
            $type = 'raw_material';
            if ($category && stripos($category->name, 'terminado') !== false) {
                $type = 'finished_product';
            }

            $pres = DB::table('product_presentations')
                ->where('product_id', $product->id)
                ->where('is_purchase_default', true)
                ->first();
            
            if (!$pres) {
                $pres = DB::table('product_presentations')
                    ->where('product_id', $product->id)
                    ->first();
            }

            DB::table('products')->where('id', $product->id)->update([
                'type' => $type,
                'presentation' => $pres ? $pres->name : 'unidad',
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['type', 'presentation']);
        });
    }
};
