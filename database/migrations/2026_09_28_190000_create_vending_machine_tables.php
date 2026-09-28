<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vending_partners', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('contact_name')->nullable();
            $table->string('phone')->nullable();
            $table->string('address')->nullable();
            $table->decimal('commission_percent', 5, 2)->default(0);
            $table->string('mercadopago_user_id')->nullable()->index();
            $table->text('mercadopago_access_token')->nullable();
            $table->text('mercadopago_refresh_token')->nullable();
            $table->timestamp('mercadopago_token_expires_at')->nullable();
            $table->string('mercadopago_store_id')->nullable();
            $table->string('status')->default('active')->index();
            $table->timestamps();

            $table->index(['company_id', 'name']);
        });

        Schema::create('vending_machines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vending_partner_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained();
            $table->string('code');
            $table->string('name');
            $table->string('location')->nullable();
            $table->decimal('sale_price', 12, 2);
            $table->unsignedInteger('capacity')->nullable();
            $table->unsignedInteger('loaded_units')->default(0);
            $table->string('mercadopago_pos_id')->nullable();
            $table->string('mercadopago_external_pos_id')->nullable();
            $table->text('mercadopago_qr_image_url')->nullable();
            $table->text('mercadopago_qr_code')->nullable();
            $table->string('status')->default('active')->index();
            $table->timestamps();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'vending_partner_id']);
        });

        Schema::create('vending_payment_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vending_partner_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vending_machine_id')->constrained()->cascadeOnDelete();
            $table->string('external_reference')->unique();
            $table->string('mercadopago_order_id')->nullable()->unique();
            $table->decimal('amount', 12, 2);
            $table->string('status')->default('created')->index();
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->index(['vending_machine_id', 'status']);
        });

        Schema::create('vending_sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vending_partner_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vending_machine_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained();
            $table->foreignId('vending_payment_order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('mercadopago_order_id')->unique();
            $table->string('mercadopago_payment_id')->nullable()->index();
            $table->string('external_reference')->index();
            $table->decimal('gross_amount', 12, 2);
            $table->decimal('commission_percent', 5, 2);
            $table->decimal('commission_amount', 12, 2);
            $table->decimal('factory_amount', 12, 2);
            $table->string('status')->default('approved')->index();
            $table->timestamp('sold_at')->index();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'sold_at']);
            $table->index(['vending_partner_id', 'sold_at']);
        });

        Schema::create('mercadopago_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_id')->unique();
            $table->string('resource_id')->nullable()->index();
            $table->string('action')->nullable();
            $table->json('payload');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mercadopago_webhook_events');
        Schema::dropIfExists('vending_sales');
        Schema::dropIfExists('vending_payment_orders');
        Schema::dropIfExists('vending_machines');
        Schema::dropIfExists('vending_partners');
    }
};
