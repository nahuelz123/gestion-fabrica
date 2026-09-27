<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_aliases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('alias');
            $table->timestamps();

            $table->unique(['company_id', 'alias']);
            $table->index(['company_id', 'product_id']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->index(['company_id', 'name'], 'products_company_name_idx');
            $table->index(['company_id', 'status'], 'products_company_status_idx');
            $table->index(['company_id', 'type'], 'products_company_type_idx');
            $table->index(['company_id', 'min_stock'], 'products_company_min_stock_idx');
        });

        Schema::table('product_presentations', function (Blueprint $table) {
            $table->index(['product_id', 'is_purchase_default'], 'presentations_product_purchase_idx');
        });

        Schema::table('stock', function (Blueprint $table) {
            $table->index(['company_id', 'product_id'], 'stock_company_product_idx');
            $table->index(['company_id', 'warehouse_id'], 'stock_company_warehouse_idx');
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->index(['company_id', 'created_at'], 'movements_company_created_idx');
            $table->index(['company_id', 'product_id', 'created_at'], 'movements_company_product_created_idx');
        });

        Schema::table('stock_lots', function (Blueprint $table) {
            $table->index(['company_id', 'expiration_date'], 'lots_company_expiration_idx');
        });
    }

    public function down(): void
    {
        Schema::table('stock_lots', function (Blueprint $table) {
            $table->dropIndex('lots_company_expiration_idx');
        });
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropIndex('movements_company_created_idx');
            $table->dropIndex('movements_company_product_created_idx');
        });
        Schema::table('stock', function (Blueprint $table) {
            $table->dropIndex('stock_company_product_idx');
            $table->dropIndex('stock_company_warehouse_idx');
        });
        Schema::table('product_presentations', function (Blueprint $table) {
            $table->dropIndex('presentations_product_purchase_idx');
        });
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex('products_company_name_idx');
            $table->dropIndex('products_company_status_idx');
            $table->dropIndex('products_company_type_idx');
            $table->dropIndex('products_company_min_stock_idx');
        });

        Schema::dropIfExists('product_aliases');
    }
};