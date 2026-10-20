<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cover the high-frequency date-range and warehouse queries without
 * deleting or rewriting any existing production records.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vending_sales', function (Blueprint $table) {
            $table->index(['company_id', 'sold_at'], 'sales_company_sold_idx');
        });
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->index(['company_id', 'warehouse_id', 'created_at'], 'movements_company_warehouse_date_idx');
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropIndex('movements_company_warehouse_date_idx');
        });
        Schema::table('vending_sales', function (Blueprint $table) {
            $table->dropIndex('sales_company_sold_idx');
        });
    }
};
