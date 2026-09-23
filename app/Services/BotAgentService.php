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
        $conversation = AiConversation::firstOrCreate(
            ['user_id' => $user->id],
            ['telegram_chat_id' => $chatId]
        );

        // Fallback for legacy conversations (pending_action exists, but no context)
        if (!empty($conversation->pending_action) && empty($conversation->context)) {
            $this->legacyProcessMessage($conversation, $user, $chatId, $text);
            return;
        }

        // --- NEW CONVERSATIONAL FLOW ---
        $context = $conversation->context ?? [];
        $context = $this->addRecentMessage($context, 'user', $text);

        // Manual strict cancellation check
        $textNorm = trim(mb_strtolower($text));
        $cancelWords = ['no', 'cancelar', 'cancelá', 'cancela', 'dejá', 'deja', 'mejor no'];
        
        $currentStatus = $context['status'] ?? 'idle';

        // Confirmaciones cortas ("sí", "dale", etc.) se resuelven en Laravel usando
        // la acción pendiente canónica. No dependemos de que Gemini reconstruya
        // correctamente la acción a partir de una sola palabra.
        $affirmativeWords = ['si', 'sí', 'dale', 'confirmo', 'confirmar', 'ok', 'okay', 'yes'];
        if ($currentStatus === 'ready_for_confirmation'
            && in_array($textNorm, $affirmativeWords, true)
            && !empty($context['pending_action'])) {
            $executor = app(BotActionExecutor::class);
            $result = $executor->execute($user, $chatId, $context['pending_action'], $context);
            $reply = $result['message'];
            $context['status'] = $result['success'] ? 'completed' : 'idle';
            if ($result['success']) {
                $context['pending_action'] = null;
            }
            $context = $this->addRecentMessage($context, 'assistant', $reply);

            DB::transaction(function () use ($conversation, $context) {
                $lockedConv = AiConversation::whereKey($conversation->id)->lockForUpdate()->firstOrFail();
                $lockedConv->update(['context' => $context]);
            });

            $this->telegramService->sendMessage($chatId, $reply);
            return;
        }
        
        if (in_array($textNorm, $cancelWords) && in_array($currentStatus, ['collecting', 'ready_for_confirmation'])) {
            $context['status'] = 'cancelled';
            $reply = 'Operación cancelada.';
            $context = $this->addRecentMessage($context, 'assistant', $reply);
            
            DB::transaction(function () use ($conversation, $context) {
                $lockedConv = AiConversation::whereKey($conversation->id)->lockForUpdate()->firstOrFail();
                $lockedConv->update(['context' => $context]);
            });
            
            $this->telegramService->sendMessage($chatId, $reply);
            return;
        }

        $analysis = $this->geminiService->analyzeConversation($text, $context);

        if ($analysis['intent'] === 'unknown' && empty($analysis['action'])) {
            $context = $this->addRecentMessage($context, 'assistant', $analysis['reply']);
            
            DB::transaction(function () use ($conversation, $context) {
                $lockedConv = AiConversation::whereKey($conversation->id)->lockForUpdate()->firstOrFail();
                $lockedConv->update(['context' => $context]);
            });
            
            $this->telegramService->sendMessage($chatId, $analysis['reply']);
            return;
        }

        $previousStatus = $context['status'] ?? 'idle';
        $previousIntent = $context['intent'] ?? null;

        // Update context based on Gemini analysis
        $context['intent'] = $analysis['intent'];
        
        // Merge entities to preserve previous ones
        $context['entities'] = array_merge($context['entities'] ?? [], array_filter($analysis['entities'] ?? []));

        $status = 'idle';
        $actionToExecute = null;
        $readActions = ['get_stock', 'get_low_stock', 'get_recipe', 'calculate_production', 'check_production', 'get_max_production', 'get_missing_inputs'];
        // Stock adjustment actions go through the normal confirmation flow (not readActions)
        // register_production also goes through confirmation because it modifies stock.
        // set_stock, add_stock, remove_stock each require user confirmation before execution.

        if ($analysis['requires_confirmation']) {
            $status = 'ready_for_confirmation';
            $context['pending_action'] = $analysis['action'];
        } elseif (!empty($analysis['action']) && !$analysis['requires_confirmation']) {
            $actionName = $analysis['action']['name'] ?? '';
            
            if (in_array($actionName, $readActions)) {
                // Read actions can be executed immediately
                $status = 'confirmed';
                $actionToExecute = $analysis['action'];
            } else {
                // Mutating actions MUST have been previously confirmed by the user
                if ($previousStatus === 'ready_for_confirmation' && $previousIntent === $analysis['intent']) {
                    // Safe to execute. Use the stored pending_action as the canonical source
                    // to avoid Gemini injecting a different action at the last second
                    $status = 'confirmed';
                    $actionToExecute = $context['pending_action'] ?? $analysis['action'];
                } else {
                    // Gemini sent a mutating action without confirmation!
                    // Demote to ready_for_confirmation
                    $status = 'ready_for_confirmation';
                    $context['pending_action'] = $analysis['action'];
                }
            }
        } elseif (!empty($analysis['missing'])) {
            $status = 'collecting';
        }

        $context['status'] = $status;
        $reply = $analysis['reply'];

        // EXECUTION
        if ($status === 'confirmed' && $actionToExecute) {
            $executor = app(BotActionExecutor::class);
            $result = $executor->execute($user, $chatId, $actionToExecute, $context);
            
            $reply = $result['message'];
            
            if ($result['success']) {
                $context['status'] = 'completed';
                $context['pending_action'] = null;
            } else {
                // Keep it idle or ready to let the user retry
                $context['status'] = 'idle';
            }
        }

        $context = $this->addRecentMessage($context, 'assistant', $reply);
        
        DB::transaction(function () use ($conversation, $context) {
            $lockedConv = AiConversation::whereKey($conversation->id)->lockForUpdate()->firstOrFail();
            $lockedConv->update(['context' => $context]);
        });

        $this->telegramService->sendMessage($chatId, $reply);
    }

    private function addRecentMessage(array $context, string $role, string $text): array
    {
        $messages = $context['recent_messages'] ?? [];
        $messages[] = ['role' => $role, 'text' => $text];
        
        if (count($messages) > 10) {
            $messages = array_slice($messages, -10);
        }
        
        $context['recent_messages'] = $messages;
        return $context;
    }

    /**
     * @deprecated Legacy flow
     */
    private function legacyProcessMessage(AiConversation $conversation, User $user, string $chatId, string $text): void
    {
        $proceedToFlowB = false;

        DB::transaction(function () use ($conversation, $user, $chatId, $text, &$proceedToFlowB) {
            $conversation = AiConversation::where('user_id', $user->id)->lockForUpdate()->first();

            if (app()->environment('testing') && env('TEST_BOT_SLEEP')) {
                sleep(3);
            }

            if ($conversation && $conversation->pending_action) {
                $textNorm = trim(mb_strtolower($text));
                $affirmative = ['si', 'sí', 'dale', 'confirmo', 'ok', 'yes'];
                $negative = ['no', 'cancelar', 'cancela'];

                if (in_array($textNorm, $affirmative)) {
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
                    return; 
                } elseif (in_array($textNorm, $negative)) {
                    $conversation->update(['pending_action' => null]);
                    $this->telegramService->sendMessage($chatId, '❌ Operación cancelada.');
                    return;
                } else {
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

        try {
            $analysis = $this->geminiService->analyzeText($text);
            $intent = $analysis['intent'] ?? 'unknown';
            $productName = $analysis['product_name'] ?? null;
            $quantity = $analysis['quantity'] ?? null;

            if ($intent === 'unknown' || !$productName) {
                $this->telegramService->sendMessage($chatId, 'No entendí lo que necesitás. Podés consultarme stock, pedirme simular una producción, o informarme ingresos de mercadería.');
                return;
            }

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

