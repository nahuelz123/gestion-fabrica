<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recipes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete(); // The finished product
            $table->decimal('yield_quantity', 12, 2)->default(1);
            $table->text('notes')->nullable();
            $table->timestamps();
            
            // A product can only have one active recipe at a time in this standard model
            $table->unique(['company_id', 'product_id']);
        });

        Schema::create('recipe_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recipe_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained(); // The ingredient
            $table->decimal('quantity_base', 12, 2);
            $table->timestamps();

            // Prevent adding the same ingredient twice to the same recipe
            $table->unique(['recipe_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recipe_items');
        Schema::dropIfExists('recipes');
    }
};
