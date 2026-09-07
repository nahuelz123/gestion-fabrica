<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class PurchaseServiceTest extends TestCase
{
    use DatabaseTruncation;

    public function test_concurrent_purchase_confirm_prevents_double_stock()
    {
        // 1. Seed base data
        $company = Company::create(['name' => 'Test Company']);
        $user = User::create(['company_id' => $company->id, 'name' => 'Test', 'email' => 'test2@test.com', 'password' => 'pass']);
        $warehouse = Warehouse::create(['company_id' => $company->id, 'name' => 'Test Warehouse']);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'Test Supplier']);
        $unit = Unit::create(['name' => 'Unit', 'abbreviation' => 'u', 'type' => 'count']);
        $category = ProductCategory::create(['company_id' => $company->id, 'name' => 'Test Category']);
        
        $product = Product::create([
            'company_id' => $company->id,
            'category_id' => $category->id,
            'name' => 'Test Product',
            'internal_code' => 'TEST-P',
            'base_unit_id' => $unit->id,
            'requires_lot' => false,
            'cost' => 10,
            'price' => 20,
        ]);

        $purchase = Purchase::create([
            'company_id' => $company->id,
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
            'purchase_date' => now()->toDateString(),
            'status' => 'draft',
            'user_id' => $user->id,
        ]);

        PurchaseItem::create([
            'purchase_id' => $purchase->id,
            'product_id' => $product->id,
            'quantity' => 50,
            'unit_cost' => 10,
        ]);

        $purchaseId = $purchase->id;
        $userId = $user->id;

        // Ensure no stock exists yet
        $this->assertEquals(0, \App\Models\StockMovement::where('reference_type', Purchase::class)->where('reference_id', $purchaseId)->count());

        // 2. We use tinker to spawn child processes to confirm the purchase simultaneously.
        // First process will lock the purchase, sleep for 2 seconds. Second process will queue for lock.
        // Once first commits, purchase is 'confirmed'. Second process gets lock, checks status, throws Exception.
        $script = <<<PHP
        try {
            app(\App\Services\PurchaseService::class)->confirm({$purchaseId}, {$userId});
        } catch (\Exception \$e) {
            echo "EXCEPTION: " . \$e->getMessage();
        }
        PHP;

        $escapedScript = str_replace('"', '\"', $script);
        $tinkerCmd = "php artisan tinker --execute=\"{$escapedScript}\"";

        // Spawn Process 1
        $process1 = Process::env([
            'APP_ENV' => 'testing',
            'TEST_CONCURRENCY_SLEEP' => '2',
            'DB_CONNECTION' => 'mysql',
            'DB_DATABASE' => 'gestion_fabrica_testing'
        ])->start($tinkerCmd);
        
        usleep(500000); // 0.5 seconds to ensure P1 has the lock

        // Spawn Process 2
        $process2 = Process::env([
            'APP_ENV' => 'testing',
            'TEST_CONCURRENCY_SLEEP' => '0',
            'DB_CONNECTION' => 'mysql',
            'DB_DATABASE' => 'gestion_fabrica_testing'
        ])->start($tinkerCmd);

        $process1->wait();
        $process2->wait();

        // 3. Asserts
        // Only one process should have generated a stock movement
        $movementCount = \App\Models\StockMovement::where('reference_type', Purchase::class)->where('reference_id', $purchaseId)->count();
        $this->assertEquals(1, $movementCount, 'There should be exactly one stock movement.');

        // Stock should be 50, not 100
        $stock = \App\Models\Stock::where('product_id', $product->id)->first();
        $this->assertEquals(50, $stock->quantity, 'The total stock should be 50.');

        // P2 output should contain the Exception message
        $this->assertStringContainsString('La compra ya no está en borrador', $process2->output());
    }
}
