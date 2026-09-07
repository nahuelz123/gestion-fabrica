<?php

namespace App\Services;

use App\Enums\Channel;
use App\Enums\MovementType;
use App\Models\AiConversation;
use App\Models\Product;
use App\Models\Stock;
use App\Models\User;
use Exception;
use Illuminate\Support\Facades\DB;

class BotAgentService
{
    public function __construct(
        private TelegramService $telegramService,
        private GeminiService $geminiService,
        private StockService $stockService,
        private ProductionCalculatorService $productionCalculator
    ) {}

    public function processMessage(User $user, string $chatId, string $text): void
    {
        // FLUJO A: Evaluación de estado pendiente (Transacción corta y cerrada)
        $proceedToFlowB = false;

        DB::transaction(function () use ($user, $chatId, $text, &$proceedToFlowB) {
            $conversation = AiConversation::where('user_id', $user->id)->lockForUpdate()->first();

            if (app()->environment('testing') && env('TEST_BOT_SLEEP')) {
                sleep(3);
            }

            if ($conversation && $conversation->pending_action) {
                $textNorm = trim(mb_strtolower($text));
                $affirmative = ['si', 'sí', 'dale', 'confirmo', 'ok', 'yes'];
                $negative = ['no', 'cancelar', 'cancela'];

                if (in_array($textNorm, $affirmative)) {
                    // Confirmado
                    try {
                        $actionData = $conversation->pending_action;
                        if (isset($actionData['type']) && is_string($actionData['type'])) {
                            $actionData['type'] = MovementType::from($actionData['type']);
                        }
                        if (isset($actionData['channel']) && is_string($actionData['channel'])) {
                            $actionData['channel'] = Channel::from($actionData['channel']);
                        }

                        $this->stockService->registerMovement($actionData);
                        $conversation->update(['pending_action' => null]);
                        $this->telegramService->sendMessage($chatId, '✅ Ingreso registrado correctamente en el inventario.');
                    } catch (Exception $e) {
                        $conversation->update(['pending_action' => null]);
                        $this->telegramService->sendMessage($chatId, '❌ No pude registrarlo: ' . $e->getMessage());
                    }
                    return; // Corta flujo DB, no sigue al B
                } elseif (in_array($textNorm, $negative)) {
                    // Cancelado
                    $conversation->update(['pending_action' => null]);
                    $this->telegramService->sendMessage($chatId, '🚫 Operación cancelada.');
                    return;
                } else {
                    // Ambiguo: Limpia estado y salta a flujo B
                    $conversation->update(['pending_action' => null]);
                    $proceedToFlowB = true;
                }
            } else {
                $proceedToFlowB = true;
            }
        });

        if (!$proceedToFlowB) {
            return;
        }

        // FLUJO B: Nueva interacción (Sin locks abiertos)
        try {
            $analysis = $this->geminiService->analyzeText($text);
            $intent = $analysis['intent'] ?? 'unknown';
            $productName = $analysis['product_name'] ?? null;
            $quantity = $analysis['quantity'] ?? null;

            if ($intent === 'unknown' || !$productName) {
                $this->telegramService->sendMessage($chatId, 'No entendí lo que necesitás. Podés consultarme stock, pedirme simular una producción, o informarme ingresos de mercadería.');
                return;
            }

            // Resolución del producto
            $products = Product::where('company_id', $user->company_id)
                ->where('name', 'like', "%{$productName}%")
                ->get();

            if ($products->count() === 0) {
                $this->telegramService->sendMessage($chatId, "No encontré ningún producto registrado con el nombre '{$productName}'.");
                return;
            }

            if ($products->count() > 1) {
                $names = $products->pluck('name')->implode(', ');
                $this->telegramService->sendMessage($chatId, "Encontré varios productos que coinciden: {$names}. ¿Cuál es exactamente?");
                return;
            }

            $product = $products->first();

            // Ejecución según intent
            if ($intent === 'check_stock') {
                $stock = Stock::where('product_id', $product->id)->sum('quantity');
                $unit = $product->baseUnit->abbreviation ?? 'ud';
                $this->telegramService->sendMessage($chatId, "Tenés <b>{$stock} {$unit}</b> de {$product->name} en total.");
                
            } elseif ($intent === 'calculate_production') {
                if (!$quantity) {
                    $this->telegramService->sendMessage($chatId, "¿Qué cantidad de {$product->name} querés producir?");
                    return;
                }
                
                $result = $this->productionCalculator->calculateRequirements($product, (float) $quantity);
                
                $msg = "Simulación para <b>{$quantity} {$product->name}</b>:\n\n";
                if ($result['can_produce']) {
                    $msg .= "✅ <b>Stock suficiente</b>\n";
                } else {
                    $msg .= "❌ <b>Falta stock de insumos</b>\n";
                }
                
                foreach ($result['items'] as $item) {
                    $faltante = $item['missing'] > 0 ? " (Faltan {$item['missing']})" : "";
                    $msg .= "- {$item['ingredient']->name}: Req {$item['required']} / Hay {$item['available']}{$faltante}\n";
                }
                
                $this->telegramService->sendMessage($chatId, $msg);

            } elseif ($intent === 'propose_stock_entry') {
                if (!$quantity) {
                    $this->telegramService->sendMessage($chatId, "¿Qué cantidad de {$product->name} ingresó?");
                    return;
                }

                // Generar pending action
                // Buscamos depósito por defecto (el primero)
                $warehouseId = \App\Models\Warehouse::where('company_id', $user->company_id)->first()->id;

                $data = [
                    'company_id' => $user->company_id,
                    'product_id' => $product->id,
                    'warehouse_id' => $warehouseId,
                    'type' => MovementType::AdjustmentIn->value,
                    'quantity_base' => (float) $quantity,
                    'user_id' => $user->id,
                    'channel' => Channel::Telegram->value,
                ];

                AiConversation::updateOrCreate(
                    ['user_id' => $user->id],
                    ['telegram_chat_id' => $chatId, 'pending_action' => $data]
                );

                $unit = $product->baseUnit->abbreviation ?? 'ud';
                $this->telegramService->sendMessage($chatId, "¿Confirmo el ingreso de <b>{$quantity} {$unit}</b> de {$product->name}? (Respondé 'Sí' o 'No')");
            }

        } catch (Exception $e) {
            $this->telegramService->sendMessage($chatId, "Error: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
        }
    }
}
