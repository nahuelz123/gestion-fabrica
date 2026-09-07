<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\Stock;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class StockServiceTest extends TestCase
{
    use DatabaseTruncation;

    public function test_concurrent_first_movements_do_not_create_duplicate_stock_rows()
    {
        // 1. Setup seed data
        $company = Company::create(['name' => 'Test Company']);
        $user = User::create(['company_id' => $company->id, 'name' => 'Test', 'email' => 'test@test.com', 'password' => 'pass']);
        $warehouse = Warehouse::create(['company_id' => $company->id, 'name' => 'Test Warehouse']);
        $unit = Unit::create(['name' => 'Unit', 'abbreviation' => 'u', 'type' => 'count']);
        $category = ProductCategory::create(['company_id' => $company->id, 'name' => 'Test Category']);
        
        $product = Product::create([
            'company_id' => $company->id,
            'category_id' => $category->id,
            'name' => 'Test Concurrent Product',
            'internal_code' => 'TEST-C',
            'base_unit_id' => $unit->id,
            'requires_lot' => false,
            'cost' => 0,
            'price' => 0,
        ]);

        $productId = $product->id;
        $warehouseId = $warehouse->id;
        $companyId = $company->id;
        $userId = $user->id;

        $this->assertDatabaseMissing('stock', [
            'product_id' => $productId,
            'warehouse_id' => $warehouseId,
        ]);

        // 2. We use tinker to spawn child processes.
        // We pass TEST_CONCURRENCY_SLEEP=2 and APP_ENV=testing to the child processes env,
        // so the first transaction sleeps for 2 seconds WHILE HOLDING THE LOCK.
        // The second process will start 0.5s later, and since the first holds the lock, it MUST WAIT.

        $script = <<<PHP
        app(\App\Services\StockService::class)->registerMovement([
            'company_id' => $companyId,
            'product_id' => $productId,
            'warehouse_id' => $warehouseId,
            'type' => \App\Enums\MovementType::AdjustmentIn,
            'quantity_base' => 10,
            'user_id' => $userId,
            'channel' => \App\Enums\Channel::System,
        ]);
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
        
        // Ensure Process 1 starts and acquires the lock before Process 2 hits
        usleep(500000); // 0.5 seconds

        // Spawn Process 2
        $process2 = Process::env([
            'APP_ENV' => 'testing',
            'TEST_CONCURRENCY_SLEEP' => '0', // No sleep for the second process
            'DB_CONNECTION' => 'mysql',
            'DB_DATABASE' => 'gestion_fabrica_testing'
        ])->start($tinkerCmd);

        // Wait for both
        $process1->wait();
        $process2->wait();

        if (!empty($process1->errorOutput())) {
            dump("P1 Error: " . $process1->errorOutput());
        }
        if (!empty($process2->errorOutput())) {
            dump("P2 Error: " . $process2->errorOutput());
        }
        if (!empty($process1->output())) {
            // Uncomment to debug
            // dump("P1 Output: " . $process1->output());
        }
        if (!empty($process2->output())) {
            // Uncomment to debug
            // dump("P2 Output: " . $process2->output());
        }

        // 3. Asserts
        $stockCount = Stock::where('product_id', $productId)
            ->where('warehouse_id', $warehouseId)
            ->count();
            
        $this->assertEquals(1, $stockCount, 'There should be exactly one stock row, proving the lock worked.');

        $stock = Stock::where('product_id', $productId)
            ->where('warehouse_id', $warehouseId)
            ->first();
            
        $this->assertEquals(20, $stock->quantity, 'The quantity should be exactly 20.');
    }
}
