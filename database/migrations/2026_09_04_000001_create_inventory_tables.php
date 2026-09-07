<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('type')->default('main'); // main, production, distribution
            $table->string('address')->nullable();
            $table->timestamps();
        });

        Schema::create('stock_lots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained();
            $table->string('lot_code');
            $table->date('production_date')->nullable();
            $table->date('entry_date');
            $table->date('expiration_date')->nullable();
            $table->decimal('initial_quantity', 12, 2);
            $table->unsignedBigInteger('supplier_id')->nullable(); // FK to suppliers (table created in Prompt 4)
            $table->string('status')->default('active'); // active, expired, depleted, blocked
            $table->timestamps();

            $table->unique(['product_id', 'lot_code']);
        });

        Schema::create('stock', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained();
            $table->foreignId('warehouse_id')->constrained();
            $table->foreignId('lot_id')->nullable()->constrained('stock_lots');
            $table->decimal('quantity', 12, 2)->default(0);
            $table->timestamps();

            // Unique index: note that MySQL does NOT enforce uniqueness
            // when lot_id is NULL — two rows with lot_id=NULL won't collide.
            // For products without lots, uniqueness is guaranteed by the
            // StockService via lockForUpdate() on the product row,
            // not by this index alone.
            $table->unique(['product_id', 'warehouse_id', 'lot_id']);
        });

        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained();
            $table->foreignId('warehouse_id')->constrained();
            $table->foreignId('lot_id')->nullable()->constrained('stock_lots');
            $table->string('type'); // purchase_in, sale_out, production_consumption, production_output, waste, adjustment
            $table->decimal('quantity_base', 12, 2); // signed: positive=in, negative=out
            $table->foreignId('presentation_id')->nullable()->constrained('product_presentations');
            $table->decimal('presentation_quantity', 12, 2)->nullable();
            $table->nullableMorphs('reference'); // polymorphic to purchase/sale/production_order
            $table->foreignId('user_id')->constrained();
            $table->string('channel')->default('web'); // web, telegram, system
            $table->text('reason')->nullable();
            $table->timestamp('created_at')->nullable();

            // No updated_at: this record is immutable (insert-only ledger)
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('stock');
        Schema::dropIfExists('stock_lots');
        Schema::dropIfExists('warehouses');
    }
};
