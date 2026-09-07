<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained(); // Producto final a fabricar
            $table->foreignId('warehouse_id')->constrained(); // Depósito donde ocurre la producción
            $table->decimal('target_quantity', 12, 2);
            $table->string('status')->default('completed'); // 'completed' o 'cancelled'
            $table->foreignId('user_id')->constrained();
            $table->timestamps();
        });

        Schema::create('production_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('production_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained(); // Insumo consumido
            $table->decimal('required_quantity', 12, 2);
            $table->decimal('consumed_quantity', 12, 2);
            $table->timestamps();
        });

        Schema::create('production_outputs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('production_order_id')->constrained()->cascadeOnDelete();
            $table->string('lot_code')->nullable();
            $table->date('expiration_date')->nullable();
            $table->decimal('quantity_produced', 12, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_outputs');
        Schema::dropIfExists('production_order_items');
        Schema::dropIfExists('production_orders');
    }
};
