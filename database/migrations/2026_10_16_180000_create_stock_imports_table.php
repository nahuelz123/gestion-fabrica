<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained();
            $table->foreignId('user_id')->constrained();
            $table->string('filename', 255);
            $table->char('sha256', 64);
            $table->string('status', 16)->default('preview');
            $table->unsignedSmallInteger('row_count');
            $table->json('rows');
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'warehouse_id', 'sha256', 'status'], 'stock_import_duplicate_idx');
            $table->index(['company_id', 'user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_imports');
    }
};
