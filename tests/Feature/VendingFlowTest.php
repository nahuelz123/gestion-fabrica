<?php

namespace Tests\Feature;

use App\Jobs\NotifyVendingSaleJob;
use App\Jobs\ProcessMercadoPagoWebhookJob;
use App\Jobs\RefreshVendingMachineOrderJob;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Unit;
use App\Models\VendingMachine;
use App\Models\VendingPartner;
use App\Models\VendingPaymentOrder;
use App\Models\VendingSale;
use App\Services\MercadoPagoVendingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class VendingFlowTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Product $product;
    private VendingPartner $partner;
    private VendingMachine $machine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['name' => 'Hamburguesería Test']);
        $unit = Unit::create(['name' => 'Unidad', 'abbreviation' => 'u', 'type' => 'count']);
        $category = ProductCategory::create([
            'company_id' => $this->company->id,
            'name' => 'Producto Terminado',
        ]);

        $this->product = Product::create([
            'company_id' => $this->company->id,
            'category_id' => $category->id,
            'type' => 'finished_product',
            'name' => 'Hamburguesa cheddar',
            'presentation' => 'unidad',
            'base_unit_id' => $unit->id,
            'cost' => 0,
            'price' => 0,
            'status' => 'active',
        ]);

        $this->partner = VendingPartner::create([
            'company_id' => $this->company->id,
            'name' => 'Kiosco Test',
            'street_name' => 'San Martín',
            'street_number' => '1234',
            'city_name' => 'Mar del Plata',
            'state_name' => 'Buenos Aires',
            'latitude' => -38.0055,
            'longitude' => -57.5426,
            'commission_percent' => 0,
            'mercadopago_user_id' => '999001',
            'mercadopago_access_token' => 'APP_USR-test-token',
            'mercadopago_refresh_token' => 'refresh-test',
            'mercadopago_token_expires_at' => now()->addMonths(3),
            'status' => 'active',
        ]);

        $this->machine = VendingMachine::create([
            'company_id' => $this->company->id,
            'vending_partner_id' => $this->partner->id,
            'product_id' => $this->product->id,
            'code' => 'MAQ-TEST-1',
            'name' => 'Máquina Centro',
            'sale_price' => 10000,
            'loaded_units' => 0,
            'status' => 'active',
        ]);
    }

    public function test_provision_creates_store_pos_qr_and_payment_order_without_stock_requirement(): void
    {
        Http::fake(function (Request $request) {
            $url = $request->url();

            if ($request->method() === 'GET' && str_contains($url, '/stores/search')) {
                return Http::response(['results' => []], 200);
            }
            if ($request->method() === 'POST' && str_ends_with($url, '/stores')) {
                return Http::response(['id' => 'STORE-001'], 201);
            }
            if ($request->method() === 'GET' && str_contains($url, '/v2/pos')) {
                return Http::response(['data' => []], 200);
            }
            if ($request->method() === 'POST' && str_ends_with($url, '/v2/pos')) {
                return Http::response([
                    'id' => 12345,
                    'external_id' => 'GF1M1',
                    'qr_response' => [
                        'image' => 'https://example.test/qr.png',
                        'template_image' => 'https://example.test/template.png',
                        'qr_code' => '000201TEST',
                    ],
                ], 201);
            }
            if ($request->method() === 'POST' && str_ends_with($url, '/v1/orders')) {
                return Http::response([
                    'id' => 'ORDER-001',
                    'status' => 'created',
                    'external_reference' => 'server-ref',
                ], 201);
            }

            return Http::response(['message' => 'Unexpected fake request: ' . $url], 500);
        });

        $machine = app(MercadoPagoVendingService::class)
            ->provisionMachine($this->machine->fresh(['partner', 'product']));

        $this->assertSame('STORE-001', $this->partner->fresh()->mercadopago_store_id);
        $this->assertSame('12345', $machine->mercadopago_pos_id);
        $this->assertSame('https://example.test/qr.png', $machine->mercadopago_qr_image_url);
        $this->assertDatabaseCount('vending_payment_orders', 1);
        $this->assertDatabaseHas('vending_payment_orders', [
            'vending_machine_id' => $this->machine->id,
            'mercadopago_order_id' => 'ORDER-001',
            'amount' => 10000,
        ]);
    }

    public function test_processed_order_creates_one_sale_without_touching_stock(): void
    {
        Queue::fake([RefreshVendingMachineOrderJob::class, NotifyVendingSaleJob::class]);

        $order = VendingPaymentOrder::create([
            'company_id' => $this->company->id,
            'vending_partner_id' => $this->partner->id,
            'vending_machine_id' => $this->machine->id,
            'external_reference' => 'VM1_TESTREF',
            'mercadopago_order_id' => 'ORDER-777',
            'amount' => 10000,
            'status' => 'created',
            'expires_at' => now()->addDay(),
        ]);

        Http::fake([
            'https://api.mercadopago.com/v1/orders/ORDER-777' => Http::response([
                'id' => 'ORDER-777',
                'status' => 'processed',
                'status_detail' => 'accredited',
                'external_reference' => $order->external_reference,
                'total_amount' => '10000.00',
                'total_paid_amount' => '10000.00',
                'transactions' => ['payments' => [['id' => 'PAY-777', 'amount' => '10000.00']]],
            ], 200),
        ]);

        $service = app(MercadoPagoVendingService::class);
        $payload = ['id' => 'evt-777', 'data' => ['id' => 'ORDER-777']];

        $first = $service->processVerifiedWebhook($payload);
        $second = $service->processVerifiedWebhook($payload);

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertDatabaseCount('vending_sales', 1);
        $this->assertSame(0, $this->machine->fresh()->loaded_units);

        $sale = VendingSale::firstOrFail();
        $this->assertSame('approved', $sale->status);
        $this->assertSame(10000.0, (float) $sale->gross_amount);
        $this->assertSame(0.0, (float) $sale->commission_amount);
        $this->assertSame(10000.0, (float) $sale->factory_amount);
        $this->assertNotEmpty($sale->receipt_number);
    }

    public function test_processed_order_with_wrong_amount_is_rejected_and_creates_no_sale(): void
    {
        Queue::fake([RefreshVendingMachineOrderJob::class, NotifyVendingSaleJob::class]);

        $order = VendingPaymentOrder::create([
            'company_id' => $this->company->id,
            'vending_partner_id' => $this->partner->id,
            'vending_machine_id' => $this->machine->id,
            'external_reference' => 'VM1_BAD_AMOUNT',
            'mercadopago_order_id' => 'ORDER-BAD-AMOUNT',
            'amount' => 10000,
            'status' => 'created',
            'expires_at' => now()->addDay(),
        ]);

        Http::fake([
            'https://api.mercadopago.com/v1/orders/ORDER-BAD-AMOUNT' => Http::response([
                'id' => 'ORDER-BAD-AMOUNT',
                'status' => 'processed',
                'status_detail' => 'accredited',
                'external_reference' => $order->external_reference,
                'total_amount' => '15000.00',
                'total_paid_amount' => '15000.00',
                'transactions' => ['payments' => [['id' => 'PAY-BAD', 'amount' => '15000.00']]],
            ], 200),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('importe confirmado por Mercado Pago no coincide');

        try {
            app(MercadoPagoVendingService::class)->processVerifiedWebhook([
                'id' => 'evt-bad-amount',
                'data' => ['id' => 'ORDER-BAD-AMOUNT'],
            ]);
        } finally {
            $this->assertDatabaseCount('vending_sales', 0);
        }
    }

    public function test_refund_updates_sale_without_touching_machine_stock(): void
    {
        Queue::fake([RefreshVendingMachineOrderJob::class, NotifyVendingSaleJob::class]);

        $paymentOrder = VendingPaymentOrder::create([
            'company_id' => $this->company->id,
            'vending_partner_id' => $this->partner->id,
            'vending_machine_id' => $this->machine->id,
            'external_reference' => 'VM1_REFUND',
            'mercadopago_order_id' => 'ORDER-REFUND',
            'amount' => 10000,
            'status' => 'processed',
            'processed_at' => now(),
        ]);

        VendingSale::create([
            'receipt_number' => 'VM-TEST-1',
            'company_id' => $this->company->id,
            'vending_partner_id' => $this->partner->id,
            'vending_machine_id' => $this->machine->id,
            'product_id' => $this->product->id,
            'vending_payment_order_id' => $paymentOrder->id,
            'mercadopago_order_id' => 'ORDER-REFUND',
            'external_reference' => 'VM1_REFUND',
            'gross_amount' => 10000,
            'refunded_amount' => 0,
            'commission_percent' => 0,
            'commission_amount' => 0,
            'factory_amount' => 10000,
            'status' => 'approved',
            'sold_at' => now(),
        ]);

        $this->machine->update(['loaded_units' => 4]);

        Http::fake([
            'https://api.mercadopago.com/v1/orders/ORDER-REFUND' => Http::response([
                'id' => 'ORDER-REFUND',
                'status' => 'refunded',
                'status_detail' => 'refunded',
                'external_reference' => 'VM1_REFUND',
                'total_amount' => '10000.00',
                'total_paid_amount' => '10000.00',
                'transactions' => ['payments' => [['id' => 'PAY-REFUND']]],
            ], 200),
        ]);

        app(MercadoPagoVendingService::class)->processVerifiedWebhook([
            'id' => 'evt-refund',
            'data' => ['id' => 'ORDER-REFUND'],
        ]);

        $sale = VendingSale::firstOrFail();
        $this->assertSame('refunded', $sale->status);
        $this->assertSame(10000.0, (float) $sale->refunded_amount);
        $this->assertSame(0.0, (float) $sale->commission_amount);
        $this->assertSame(0.0, (float) $sale->factory_amount);
        $this->assertSame(4, $this->machine->fresh()->loaded_units);
    }

    public function test_tablet_is_public_only_through_random_machine_token(): void
    {
        $this->machine->update([
            'mercadopago_pos_id' => 'POS-1',
            'mercadopago_external_pos_id' => 'GF1M1',
            'mercadopago_qr_image_url' => 'https://example.test/qr.png',
        ]);

        VendingPaymentOrder::create([
            'company_id' => $this->company->id,
            'vending_partner_id' => $this->partner->id,
            'vending_machine_id' => $this->machine->id,
            'external_reference' => 'VM_TABLET',
            'mercadopago_order_id' => 'ORDER-TABLET',
            'amount' => 10000,
            'status' => 'created',
            'expires_at' => now()->addDay(),
        ]);

        $this->get(route('vending.tablet', ['token' => $this->machine->public_token]))
            ->assertOk()
            ->assertSee('Hamburguesa cheddar')
            ->assertSee('$10.000', false);

        $this->get(route('vending.tablet', ['token' => 'token-inexistente']))
            ->assertNotFound();
    }

    public function test_webhook_rejects_invalid_signature_and_queues_valid_event_once(): void
    {
        Queue::fake([ProcessMercadoPagoWebhookJob::class]);
        config(['services.mercadopago.webhook_secret' => 'webhook-test-secret']);

        $payload = [
            'id' => 'EVENT-ABC',
            'action' => 'order.processed',
            'data' => ['id' => 'ORDER-ABC'],
        ];

        $this->postJson('/mercadopago/webhook?data_id=ORDER-ABC', $payload, [
            'x-request-id' => 'REQ-ABC',
            'x-signature' => 'ts=123,v1=invalida',
        ])->assertUnauthorized();

        $timestamp = '1234567890';
        $manifest = 'id:order-abc;request-id:REQ-ABC;ts:' . $timestamp . ';';
        $signature = hash_hmac('sha256', $manifest, 'webhook-test-secret');
        $headers = [
            'x-request-id' => 'REQ-ABC',
            'x-signature' => 'ts=' . $timestamp . ',v1=' . $signature,
        ];

        $this->postJson('/mercadopago/webhook?data_id=ORDER-ABC', $payload, $headers)
            ->assertStatus(202);

        $this->postJson('/mercadopago/webhook?data_id=ORDER-ABC', $payload, $headers)
            ->assertStatus(202)
            ->assertJson(['status' => 'retry_accepted']);

        $this->assertDatabaseCount('mercadopago_webhook_events', 1);
        Queue::assertPushed(ProcessMercadoPagoWebhookJob::class, 2);

        \Illuminate\Support\Facades\DB::table('mercadopago_webhook_events')
            ->where('event_id', 'EVENT-ABC')
            ->update(['processed_at' => now()]);

        $this->postJson('/mercadopago/webhook?data_id=ORDER-ABC', $payload, $headers)
            ->assertOk()
            ->assertJson(['status' => 'duplicate']);

        Queue::assertPushed(ProcessMercadoPagoWebhookJob::class, 2);
    }
}
