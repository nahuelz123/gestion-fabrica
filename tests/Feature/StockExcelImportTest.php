<?php

namespace Tests\Feature;

use App\Enums\Channel;
use App\Enums\MovementType;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductPresentation;
use App\Models\Stock;
use App\Models\StockImport;
use App\Models\StockLot;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\StockImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\TestCase;
use ZipArchive;

class StockExcelImportTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $company = Company::create(['name' => 'Rapi Burguer']);
        $warehouse = Warehouse::create(['company_id' => $company->id, 'name' => 'Depósito central']);
        $otherWarehouse = Warehouse::create(['company_id' => $company->id, 'name' => 'Sucursal']);
        $owner = User::create([
            'company_id' => $company->id, 'name' => 'Dueño', 'email' => 'excel-owner@test.com',
            'password' => 'pass', 'role' => 'owner', 'status' => 'active',
        ]);
        $unit = Unit::create(['name' => 'Unidad', 'abbreviation' => 'u', 'type' => 'count']);
        $category = ProductCategory::create(['company_id' => $company->id, 'name' => 'Insumos']);
        $bread = Product::create([
            'company_id' => $company->id, 'category_id' => $category->id, 'name' => 'Pan',
            'internal_code' => 'PAN-XLS', 'type' => 'raw_material', 'base_unit_id' => $unit->id, 'status' => 'active',
        ]);
        $cheese = Product::create([
            'company_id' => $company->id, 'category_id' => $category->id, 'name' => 'Queso',
            'internal_code' => 'QUE-XLS', 'type' => 'raw_material', 'base_unit_id' => $unit->id, 'status' => 'active',
        ]);
        $lotProduct = Product::create([
            'company_id' => $company->id, 'category_id' => $category->id, 'name' => 'Hamburguesas',
            'internal_code' => 'HAM-XLS', 'type' => 'finished_product', 'base_unit_id' => $unit->id,
            'status' => 'active', 'requires_lot' => true,
        ]);
        $lot = StockLot::create([
            'company_id' => $company->id, 'product_id' => $lotProduct->id,
            'lot_code' => 'B-01', 'entry_date' => now()->toDateString(), 'initial_quantity' => 20,
        ]);
        ProductPresentation::create([
            'product_id' => $cheese->id, 'name' => 'barra de 200',
            'conversion_factor' => 200,
        ]);
        Stock::create(['company_id' => $company->id, 'warehouse_id' => $warehouse->id, 'product_id' => $bread->id, 'quantity' => 100]);
        Stock::create(['company_id' => $company->id, 'warehouse_id' => $warehouse->id, 'product_id' => $cheese->id, 'quantity' => 800]);
        Stock::create(['company_id' => $company->id, 'warehouse_id' => $warehouse->id, 'product_id' => $lotProduct->id, 'lot_id' => $lot->id, 'quantity' => 20]);
        Stock::create(['company_id' => $company->id, 'warehouse_id' => $otherWarehouse->id, 'product_id' => $bread->id, 'quantity' => 50]);
        return compact('company', 'warehouse', 'otherWarehouse', 'owner', 'bread', 'cheese', 'lot', 'lotProduct');
    }

    private function csv(string $body): string
    {
        $path = tempnam(sys_get_temp_dir(), 'stock-import-');
        file_put_contents($path, $body);
        return $path;
    }

    public function test_preview_is_read_only_and_apply_sets_only_given_products_in_given_warehouse(): void
    {
        $d = $this->fixture();
        $csv = $this->csv("Código;Producto;Cantidad;Lote;Presentación\nPAN-XLS;Pan;90;;\nQUE-XLS;Queso;4,5;;barra de 200\n");
        try {
            $service = app(StockImportService::class);
            $result = $service->preview($d['owner'], $d['warehouse']->id, $csv, 'stock.csv');
            $this->assertEmpty($result['errors']);
            $batch = $result['batch'];
            $this->assertNotNull($batch);
            $this->assertCount(2, $batch->rows);
            $this->assertEquals(-10, $batch->rows[0]['difference']);
            $this->assertEquals(100, $batch->rows[1]['difference']);
            $this->assertDatabaseCount('stock_movements', 0);

            $applied = $service->apply($d['owner'], $batch->id);
            $this->assertSame(2, $applied['changed']);
            $this->assertSame('applied', $batch->fresh()->status);
            $this->assertEquals(90, Stock::where('product_id', $d['bread']->id)->where('warehouse_id', $d['warehouse']->id)->sum('quantity'));
            $this->assertEquals(900, Stock::where('product_id', $d['cheese']->id)->where('warehouse_id', $d['warehouse']->id)->sum('quantity'));
            $this->assertEquals(50, Stock::where('product_id', $d['bread']->id)->where('warehouse_id', $d['otherWarehouse']->id)->sum('quantity'));
            $this->assertEquals(20, Stock::where('product_id', $d['lotProduct']->id)->sum('quantity'));
            $this->assertSame(2, StockMovement::count());

            $this->expectException(\InvalidArgumentException::class);
            $service->apply($d['owner'], $batch->id);
        } finally {
            unlink($csv);
        }
    }

    public function test_same_file_cannot_be_reapplied_even_with_new_preview(): void
    {
        $d = $this->fixture();
        $file = $this->csv("codigo;cantidad\nPAN-XLS;40\n");
        try {
            $service = app(StockImportService::class);
            $first = $service->preview($d['owner'], $d['warehouse']->id, $file, 'recuento.csv')['batch'];
            $service->apply($d['owner'], $first->id);
            $repeat = $service->preview($d['owner'], $d['warehouse']->id, $file, 'recuento.csv');
            $this->assertNull($repeat['batch']);
            $this->assertStringContainsString('ya fue aplicado', implode(' ', $repeat['errors']));
            $this->assertSame(1, StockMovement::count());
        } finally { unlink($file); }
    }

    public function test_stale_preview_aborts_all_rows_without_side_effects(): void
    {
        $d = $this->fixture();
        $file = $this->csv("codigo;cantidad\nPAN-XLS;30\nQUE-XLS;1000\n");
        try {
            $service = app(StockImportService::class);
            $batch = $service->preview($d['owner'], $d['warehouse']->id, $file, 'stock.csv')['batch'];
            // Simulate real physical stock modification after preview.
            Stock::where('product_id', $d['cheese']->id)->update(['quantity' => 700]);
            $before = StockMovement::count();

            try {
                $service->apply($d['owner'], $batch->id);
                $this->fail('Se esperaba conflicto de stock.');
            } catch (\InvalidArgumentException $error) {
                $this->assertStringContainsString('cambió desde la vista previa', $error->getMessage());
            }
            $this->assertSame($before, StockMovement::count());
            $this->assertEquals(100, Stock::where('product_id', $d['bread']->id)->where('warehouse_id', $d['warehouse']->id)->sum('quantity'));
            $this->assertEquals(700, Stock::where('product_id', $d['cheese']->id)->sum('quantity'));
            $this->assertSame('preview', $batch->fresh()->status);
        } finally { unlink($file); }
    }

    public function test_lot_rows_are_individually_reconciled_and_unknown_lots_block_entire_file(): void
    {
        $d = $this->fixture();
        $service = app(StockImportService::class);
        $bad = $this->csv("codigo;cantidad;lote\nPAN-XLS;20;\nHAM-XLS;10;INEXISTENTE\n");
        try {
            $result = $service->preview($d['owner'], $d['warehouse']->id, $bad, 'bad.csv');
            $this->assertNull($result['batch']);
            $this->assertStringContainsString('no existe', implode(' ', $result['errors']));
            $this->assertDatabaseCount('stock_imports', 0);
        } finally { unlink($bad); }

        $good = $this->csv("codigo;cantidad;lote\nHAM-XLS;16;B-01\n");
        try {
            $result = $service->preview($d['owner'], $d['warehouse']->id, $good, 'good.csv');
            $this->assertEmpty($result['errors']);
            $service->apply($d['owner'], $result['batch']->id);
            $this->assertEquals(16, Stock::where('lot_id', $d['lot']->id)->sum('quantity'));
            $this->assertDatabaseHas('stock_movements', [
                'product_id' => $d['lotProduct']->id,
                'lot_id' => $d['lot']->id, 'quantity_base' => -4,
            ]);
        } finally { unlink($good); }
    }

    public function test_duplicate_and_unknown_rows_prevent_every_adjustment(): void
    {
        $d = $this->fixture();
        foreach ([
            "codigo;cantidad\nPAN-XLS;2\nPAN-XLS;3\n",
            "codigo;cantidad\nPAN-XLS;2\nNOT-IN-DB;3\n",
            "codigo;cantidad\nPAN-XLS;-1\n",
            "codigo;cantidad\nHAM-XLS;12\n",
        ] as $csv) {
            $path = $this->csv($csv);
            try {
                $result = app(StockImportService::class)->preview($d['owner'], $d['warehouse']->id, $path, 'recuento.csv');
                $this->assertNotEmpty($result['errors']);
                $this->assertNull($result['batch']);
            } finally { unlink($path); }
        }
        $this->assertDatabaseCount('stock_imports', 0);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_xlsx_first_sheet_imports_shared_strings_and_rejects_formulas(): void
    {
        $d = $this->fixture();
        $path = tempnam(sys_get_temp_dir(), 'excel-');
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true);
        $zip->addFromString('xl/sharedStrings.xml',
            '<?xml version="1.0"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' .
            '<si><t>Codigo</t></si><si><t>Cantidad</t></si><si><t>PAN-XLS</t></si></sst>');
        $zip->addFromString('xl/worksheets/sheet1.xml',
            '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>' .
            '<row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c></row>' .
            '<row r="2"><c r="A2" t="s"><v>2</v></c><c r="B2"><v>40</v></c></row>' .
            '</sheetData></worksheet>');
        $zip->close();

        try {
            $result = app(StockImportService::class)->preview($d['owner'], $d['warehouse']->id, $path, 'recuento.xlsx');
            $this->assertEmpty($result['errors']);
            $this->assertEquals(-60, $result['batch']->rows[0]['difference']);
        } finally { unlink($path); }
    }

    public function test_import_service_denies_inactive_user_and_cross_tenant_warehouse(): void
    {
        $d = $this->fixture();
        $otherCompany = Company::create(['name' => 'Otra fábrica']);
        $other = Warehouse::create(['company_id' => $otherCompany->id, 'name' => 'Otro']);
        $file = $this->csv("codigo;cantidad\nPAN-XLS;50\n");
        try {
            try {
                app(StockImportService::class)->preview($d['owner'], $other->id, $file, 'stock.csv');
                $this->fail('Debe impedir depósito de otra empresa');
            } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
                $this->assertDatabaseCount('stock_imports', 0);
            }
            $d['owner']->update(['status' => 'inactive']);
            $this->expectException(\InvalidArgumentException::class);
            app(StockImportService::class)->preview($d['owner']->fresh(), $d['warehouse']->id, $file, 'stock.csv');
        } finally { unlink($file); }
    }

    public function test_livewire_rejects_import_without_confirmation(): void
    {
        $d = $this->fixture();
        Livewire::actingAs($d['owner'])
            ->test(\App\Livewire\Inventory\StockImportPage::class)
            ->set('warehouseId', (string)$d['warehouse']->id)
            ->set('spreadsheet', UploadedFile::fake()->createWithContent(
                'recuento.csv', "codigo;cantidad\nPAN-XLS;20\n"
            ))
            ->call('preview')
            ->assertHasNoErrors()
            ->assertSet('batchId', fn ($id) => $id !== null)
            ->call('apply')
            ->assertSee('Marcá la casilla');

        $this->assertDatabaseCount('stock_movements', 0);
    }
}
