<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vending_partners', function (Blueprint $table) {
            $table->string('street_name')->nullable()->after('address');
            $table->string('street_number')->nullable()->after('street_name');
            $table->string('city_name')->nullable()->after('street_number');
            $table->string('state_name')->nullable()->after('city_name');
            $table->string('location_reference')->nullable()->after('state_name');
            $table->decimal('latitude', 10, 7)->nullable()->after('location_reference');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            $table->string('mercadopago_external_store_id')->nullable()->after('mercadopago_store_id');
            $table->index(['company_id', 'mercadopago_user_id']);
        });

        Schema::table('vending_machines', function (Blueprint $table) {
            $table->string('public_token', 64)->nullable()->after('code');
            $table->text('mercadopago_qr_template_image_url')->nullable()->after('mercadopago_qr_image_url');
            $table->timestamp('last_sale_at')->nullable()->after('status');
            $table->timestamp('last_provisioned_at')->nullable()->after('last_sale_at');
            $table->unique('public_token');
            $table->index('mercadopago_external_pos_id');
        });

        Schema::table('vending_sales', function (Blueprint $table) {
            $table->string('receipt_number')->nullable()->unique()->after('id');
            $table->decimal('refunded_amount', 12, 2)->default(0)->after('gross_amount');
            $table->timestamp('settled_at')->nullable()->after('sold_at');
            $table->foreignId('settled_by_user_id')->nullable()->after('settled_at')->constrained('users')->nullOnDelete();
            $table->index(['company_id', 'settled_at']);
            $table->index(['company_id', 'status', 'sold_at']);
        });

        Schema::table('mercadopago_webhook_events', function (Blueprint $table) {
            $table->unsignedSmallInteger('attempts')->default(0)->after('payload');
            $table->text('processing_error')->nullable()->after('attempts');
        });

        // Conserva todos los datos de prueba y sólo agrega una URL pública segura
        // a las máquinas ya existentes.
        \App\Models\VendingMachine::whereNull('public_token')
            ->orderBy('id')
            ->chunkById(100, function ($machines) {
                foreach ($machines as $machine) {
                    $machine->forceFill(['public_token' => Str::random(48)])->saveQuietly();
                }
            });
    }

    public function down(): void
    {
        Schema::table('mercadopago_webhook_events', function (Blueprint $table) {
            $table->dropColumn(['attempts', 'processing_error']);
        });

        Schema::table('vending_sales', function (Blueprint $table) {
            $table->dropForeign(['settled_by_user_id']);
            $table->dropIndex(['company_id', 'settled_at']);
            $table->dropIndex(['company_id', 'status', 'sold_at']);
            $table->dropUnique(['receipt_number']);
            $table->dropColumn(['receipt_number', 'refunded_amount', 'settled_at', 'settled_by_user_id']);
        });

        Schema::table('vending_machines', function (Blueprint $table) {
            $table->dropUnique(['public_token']);
            $table->dropIndex(['mercadopago_external_pos_id']);
            $table->dropColumn([
                'public_token', 'mercadopago_qr_template_image_url',
                'last_sale_at', 'last_provisioned_at',
            ]);
        });

        Schema::table('vending_partners', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'mercadopago_user_id']);
            $table->dropColumn([
                'street_name', 'street_number', 'city_name', 'state_name',
                'location_reference', 'latitude', 'longitude',
                'mercadopago_external_store_id',
            ]);
        });
    }
};
