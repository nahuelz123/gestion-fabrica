<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->foreignId('parent_id')->nullable()->constrained('product_categories')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('units', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('abbreviation');
            $table->string('type'); // count, weight, volume
            $table->timestamps();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->constrained('product_categories');
            $table->string('name');
            $table->string('internal_code');
            $table->string('barcode')->nullable()->unique();
            $table->foreignId('base_unit_id')->constrained('units');
            $table->boolean('requires_lot')->default(false);
            $table->boolean('requires_expiration')->default(false);
            $table->unsignedInteger('shelf_life_days')->nullable();
            $table->decimal('cost', 12, 2)->default(0);
            $table->decimal('price', 12, 2)->default(0);
            $table->decimal('min_stock', 12, 2)->nullable();
            $table->string('status')->default('active');
            $table->timestamps();

            $table->unique(['company_id', 'internal_code']);
        });

        Schema::create('product_presentations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('barcode')->nullable()->unique();
            $table->decimal('conversion_factor', 12, 4);
            $table->boolean('is_purchase_default')->default(false);
            $table->boolean('is_sale_default')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_presentations');
        Schema::dropIfExists('products');
        Schema::dropIfExists('units');
        Schema::dropIfExists('product_categories');
    }
};
