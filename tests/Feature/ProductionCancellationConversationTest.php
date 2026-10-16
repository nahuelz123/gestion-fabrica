<?php

namespace Tests\Feature;

use App\Enums\Channel;
use App\Enums\MovementType;
use App\Models\AiConversation;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductionOrder;
use App\Models\Recipe;
use App\Models\Stock;
use App\Models\StockLot;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\BotAgentService;
use App\Services\GeminiService;
use App\Services\ProductionCancellationService;
use App\Services\ProductionOrderService;
use App\Services\TelegramService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class ProductionCancellationConversationTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(bool $withLots = false): array
    {
        $company = Company::create(['name' => 'Rapi Burguer']);
        $warehouse = Warehouse::create(['company_id' => $company->id, 'name' => 'Principal']);
        $unit = Unit::create(['name' => 'Unidad', 'abbreviation' => 'u', 'type' => 'count']);
        $category = ProductCategory::create(['company_id' => $company->id, 'name' => 'Ingredientes']);
        $owner = User::create([
            'company_id' => $company->id, 'name' => 'Dueño', 'email' => 'cancel-owner@test.com',
            'password' => 'pass', 'role' => 'owner', 'status' => 'active', 'telegram_chat_id' => '123',
        ]);
        $manager = User::create([
            'company_id' => $company->id, 'name' => 'Encargado', 'email' => 'cancel-manager@test.com',
            'password' => 'pass', 'role' => 'manager', 'status' => 'active', 'telegram_chat_id' => '234',
        ]);
        $ingredient = Product::create([
            'company_id' => $company->id, 'category_id' => $category->id, 'name' => 'Caja de pan',
            'internal_code' => 'PAN-CANCEL', 'type' => 'raw_material',
            'base_unit_id' => $unit->id, 'status' => 'active', 'requires_lot' => $withLots,
        ]);
        $finished = Product::create([
            'company_id' => $company->id, 'category_id' => $category->id,
            'name' => 'Hamburguesa bacon', 'internal_code' => 'BACON-CANCEL',
            'type' => 'finished_product', 'base_unit_id' => $unit->id,
            'status' => 'active', 'requires_lot' => $withLots,
        ]);
        $recipe = Recipe::create([
            'company_id' => $company->id, 'product_id' => $finished->id,
            'yield_quantity' => 24,
        ]);
        $recipe->items()->create(['product_id' => $ingredient->id, 'quantity_base' => 24]);
        $lotId = null;
        if ($withLots) {
            $lot = StockLot::create([
                'company_id' => $company->id, 'product_id' => $ingredient->id,
                'lot_code' => 'PAN-LOTE-1', 'entry_date' => now()->toDateString(),
                'initial_quantity' => 800, 'status' => 'active',
            ]);
            $lotId = $lot->id;
        }
        Stock::create([
            'company_id' => $company->id, 'product_id' => $ingredient->id,
            'warehouse_id' => $warehouse->id, 'lot_id' => $lotId,
            'quantity' => 800,
        ]);

        $service = app(ProductionOrderService::class);
        $one = $service->registerProduction($owner, $finished, 288, [], Channel::Telegram, $warehouse->id)['order'];
        $two = $service->registerProduction($owner, $finished, 144, [], Channel::Telegram, $warehouse->id)['order'];

        return compact('company', 'warehouse', 'owner', 'manager', 'ingredient', 'finished', 'one', 'two', 'lotId');
    }

    public function test_owner_can_cancel_only_first_order_and_restore_the_exact_consumption(): void
    {
        $d = $this->fixture();
        $service = app(ProductionCancellationService::class);
        $preview = $service->preview($d['owner'], $d['one']->id, $d['two']->id);
        $this->assertTrue($preview['success']);
        $this->assertStringContainsString('Conservar intacta la orden', $preview['message']);

        // Preview is read-only; no stock or order changes.
        $this->assertSame(432.0, (float) Stock::where('product_id', $d['finished']->id)->sum('quantity'));
        $this->assertSame(368.0, (float) Stock::where('product_id', $d['ingredient']->id)->sum('quantity'));

        $result = $service->cancel($d['owner'], $d['one']->id, $d['two']->id);
        $this->assertTrue($result['success'], $result['message']);
        $this->assertSame('cancelled', $d['one']->fresh()->status);
        $this->assertSame('completed', $d['two']->fresh()->status);
        $this->assertSame(144.0, (float) Stock::where('product_id', $d['finished']->id)->sum('quantity'));
        $this->assertSame(656.0, (float) Stock::where('product_id', $d['ingredient']->id)->sum('quantity'));

        $movements = StockMovement::where('reference_type', ProductionOrder::class)
            ->where('reference_id', $d['one']->id)->get();
        $this->assertCount(4, $movements);
        $this->assertCount(1, $movements->where('type', MovementType::AdjustmentIn));
        $this->assertCount(1, $movements->where('type', MovementType::AdjustmentOut));

        $again = $service->cancel($d['owner'], $d['one']->id, $d['two']->id);
        $this->assertFalse($again['success']);
        $this->assertSame(4, StockMovement::where('reference_type', ProductionOrder::class)
            ->where('reference_id', $d['one']->id)->count());
    }

    public function test_lot_controlled_cancellation_restores_original_lot_without_touching_kept_lot(): void
    {
        $d = $this->fixture(true);
        $service = app(ProductionCancellationService::class);
        $result = $service->cancel($d['owner'], $d['one']->id, $d['two']->id);
        $this->assertTrue($result['success'], $result['message']);

        $this->assertSame(656.0, (float) Stock::where('lot_id', $d['lotId'])->sum('quantity'));
        $this->assertSame(144.0, (float) Stock::where('product_id', $d['finished']->id)->sum('quantity'));
        $this->assertSame('completed', $d['two']->fresh()->status);
        $this->assertSame('cancelled', $d['one']->fresh()->status);

        $consumption = StockMovement::where('reference_type', ProductionOrder::class)
            ->where('reference_id', $d['one']->id)->where('type', MovementType::ProductionConsumption->value)->firstOrFail();
        $reversal = StockMovement::where('reference_type', ProductionOrder::class)
            ->where('reference_id', $d['one']->id)->where('type', MovementType::AdjustmentIn->value)->firstOrFail();
        $this->assertSame($consumption->lot_id, $reversal->lot_id);
    }

    public function test_if_produced_stock_is_no_longer_available_no_partial_reversal_occurs(): void
    {
        $d = $this->fixture();
        Stock::where('product_id', $d['finished']->id)->update(['quantity' => 200]);
        $startInputs = (float) Stock::where('product_id', $d['ingredient']->id)->sum('quantity');
        $startCount = StockMovement::count();

        $result = app(ProductionCancellationService::class)->cancel($d['owner'], $d['one']->id, $d['two']->id);
        $this->assertFalse($result['success']);
        $this->assertSame('completed', $d['one']->fresh()->status);
        $this->assertSame($startCount, StockMovement::count());
        $this->assertSame($startInputs, (float) Stock::where('product_id', $d['ingredient']->id)->sum('quantity'));
    }

    public function test_manager_cannot_cancel_and_keep_order_must_match_the_product(): void
    {
        $d = $this->fixture();
        $service = app(ProductionCancellationService::class);
        $this->assertFalse($service->preview($d['manager'], $d['one']->id, $d['two']->id)['success']);
        $this->assertFalse($service->cancel($d['manager'], $d['one']->id, $d['two']->id)['success']);
        $this->assertFalse($service->preview($d['owner'], $d['one']->id, $d['one']->id)['success']);
        $this->assertSame('completed', $d['one']->fresh()->status);
    }

    public function test_telegram_question_previews_but_only_explicit_yes_reverses_the_order(): void
    {
        $d = $this->fixture();
        $gemini = Mockery::mock(GeminiService::class);
        $gemini->shouldNotReceive('analyzeConversation');
        $telegram = Mockery::mock(TelegramService::class);
        $replies = [];
        $telegram->shouldReceive('sendMessage')->twice()->andReturnUsing(function ($chat, $reply) use (&$replies) {
            $replies[] = $reply;
        });
        $this->app->instance(GeminiService::class, $gemini);
        $this->app->instance(TelegramService::class, $telegram);

        $agent = app(BotAgentService::class);
        $question = "¿Se fabricaron solamente 144 hamburguesas bacon en total, por lo que deberíamos conservar la orden #{$d['two']->id} (144 unidades) y anular la #{$d['one']->id} (288 unidades)?";
        $agent->processMessage($d['owner'], '123', $question);
        $conv = AiConversation::where('user_id', $d['owner']->id)->firstOrFail();
        $this->assertSame('ready_for_confirmation', $conv->context['status']);
        $this->assertSame('cancel_production', $conv->pending_action['name']);
        $this->assertSame($d['one']->id, $conv->pending_action['arguments']['order_id']);
        $this->assertSame($d['two']->id, $conv->pending_action['arguments']['keep_order_id']);
        $this->assertSame('completed', $d['one']->fresh()->status);
        $this->assertStringContainsString('¿Confirmás', $replies[0]);

        $agent->processMessage($d['owner'], '123', 'sí');
        $this->assertStringContainsString('anulada', $replies[1]);
        $this->assertSame('cancelled', $d['one']->fresh()->status);
        $this->assertSame('completed', $d['two']->fresh()->status);
        $this->assertSame(144.0, (float) Stock::where('product_id', $d['finished']->id)->sum('quantity'));
    }
}
