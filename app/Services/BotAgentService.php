<?php

namespace App\Services;

use App\Models\AiConversation;
use App\Models\User;
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

        $context = is_array($conversation->context) ? $conversation->context : [];

        // Compatibilidad con conversaciones viejas que sólo tenían pending_action.
        if (empty($context) && !empty($conversation->pending_action)) {
            $context = [
                'status' => 'ready_for_confirmation',
                'pending_action' => $conversation->pending_action,
                'recent_messages' => [],
            ];
        }

        $context = $this->addRecentMessage($context, 'user', $text);
        $textNorm = trim(mb_strtolower($text));
        $currentStatus = $context['status'] ?? 'idle';

        $cancelWords = ['no', 'cancelar', 'cancelá', 'cancela', 'dejá', 'deja', 'mejor no'];
        if (in_array($textNorm, $cancelWords, true)
            && in_array($currentStatus, ['collecting', 'ready_for_confirmation'], true)) {
            $context['status'] = 'cancelled';
            $context['pending_action'] = null;
            $reply = 'Operación cancelada.';
            $context = $this->addRecentMessage($context, 'assistant', $reply);
            $this->saveContext($conversation, $context);
            $this->telegramService->sendMessage($chatId, $reply);
            return;
        }

        // Las confirmaciones cortas se resuelven en Laravel con la acción pendiente
        // exacta. Así no dependemos de que Gemini reconstruya una operación sensible.
        $affirmativeWords = ['si', 'sí', 'dale', 'confirmo', 'confirmar', 'ok', 'okay', 'yes'];
        if ($currentStatus === 'ready_for_confirmation'
            && in_array($textNorm, $affirmativeWords, true)
            && !empty($context['pending_action'])) {
            $result = app(BotActionExecutor::class)->execute(
                $user,
                $chatId,
                $context['pending_action'],
                $context
            );

            $reply = $result['message'];
            $context['status'] = $result['success'] ? 'completed' : 'idle';
            if ($result['success']) {
                $context['last_completed_action'] = $context['pending_action'];
                $context['pending_action'] = null;
            }

            $context = $this->addRecentMessage($context, 'assistant', $reply);
            $this->saveContext($conversation, $context);
            $this->telegramService->sendMessage($chatId, $reply);
            return;
        }

        $analysis = $this->geminiService->analyzeConversation($text, $context);

        if (($analysis['intent'] ?? 'unknown') === 'unknown' && empty($analysis['action'])) {
            $reply = $analysis['reply'] ?? 'Tuve un problema procesando eso. Probá nuevamente.';
            $context = $this->addRecentMessage($context, 'assistant', $reply);
            $this->saveContext($conversation, $context);
            $this->telegramService->sendMessage($chatId, $reply);
            return;
        }

        $previousStatus = $context['status'] ?? 'idle';
        $previousIntent = $context['intent'] ?? null;
        $context['intent'] = $analysis['intent'] ?? 'unknown';
        $context['entities'] = array_merge(
            $context['entities'] ?? [],
            array_filter($analysis['entities'] ?? [], fn ($value) => $value !== null && $value !== '')
        );

        $action = is_array($analysis['action'] ?? null) ? $analysis['action'] : null;
        if ($action) {
            $action = $this->enrichActionFromContext($action, $context, $textNorm);
        }

        $readActions = [
            'get_stock',
            'get_low_stock',
            'get_expiring_products',
            'get_stock_movements',
            'get_recipe',
            'calculate_production',
            'check_production',
            'get_max_production',
            'get_missing_inputs',
            'plan_production',
        ];

        $status = 'idle';
        $actionToExecute = null;
        $actionName = $action['name'] ?? '';

        if (!empty($analysis['requires_confirmation']) && $action) {
            $status = 'ready_for_confirmation';
            $context['pending_action'] = $action;
        } elseif ($action && in_array($actionName, $readActions, true)) {
            $status = 'confirmed';
            $actionToExecute = $action;
        } elseif ($action) {
            // Toda modificación necesita confirmación previa. Incluso si Gemini se olvida
            // de pedirla, Laravel la fuerza.
            if ($previousStatus === 'ready_for_confirmation'
                && $previousIntent === ($analysis['intent'] ?? null)
                && !empty($context['pending_action'])) {
                $status = 'confirmed';
                $actionToExecute = $context['pending_action'];
            } else {
                $status = 'ready_for_confirmation';
                $context['pending_action'] = $action;
            }
        } elseif (!empty($analysis['missing'])) {
            $status = 'collecting';
        }

        $context['status'] = $status;
        $reply = $analysis['reply'] ?? '¿Podés darme un poco más de detalle?';

        if ($status === 'confirmed' && $actionToExecute) {
            $result = app(BotActionExecutor::class)->execute(
                $user,
                $chatId,
                $actionToExecute,
                $context
            );

            $reply = $result['message'];
            if ($result['success']) {
                $context['status'] = 'completed';
                $context['pending_action'] = null;

                if (in_array($actionToExecute['name'] ?? '', $readActions, true)) {
                    $context['last_read_action'] = $actionToExecute;
                    $this->rememberUsefulReferences($context, $actionToExecute);
                } else {
                    $context['last_completed_action'] = $actionToExecute;
                }
            } else {
                $context['status'] = 'idle';
            }
        }

        $context = $this->addRecentMessage($context, 'assistant', $reply);
        $this->saveContext($conversation, $context);
        $this->telegramService->sendMessage($chatId, $reply);
    }

    private function enrichActionFromContext(array $action, array $context, string $textNorm): array
    {
        $name = $action['name'] ?? '';
        $args = is_array($action['arguments'] ?? null) ? $action['arguments'] : [];
        $last = is_array($context['last_read_action'] ?? null) ? $context['last_read_action'] : [];
        $lastArgs = is_array($last['arguments'] ?? null) ? $last['arguments'] : [];

        // Referencias como "¿y si agrego...?" conservan el producto terminado anterior.
        if (empty($args['product_name'])
            && in_array($name, ['get_max_production', 'calculate_production', 'check_production', 'get_missing_inputs'], true)) {
            $args['product_name'] = $lastArgs['product_name']
                ?? ($context['last_product_name'] ?? null);
        }

        // Las simulaciones se pueden encadenar: +1000 bolsitas, y después +10 cajas de medallones.
        $isContinuation = str_starts_with($textNorm, 'y ')
            || str_contains($textNorm, 'además')
            || str_contains($textNorm, 'ademas')
            || str_contains($textNorm, 'sumale')
            || str_contains($textNorm, 'agregale')
            || str_contains($textNorm, 'agregá')
            || str_contains($textNorm, 'agrega');

        if ($name === 'get_max_production' && $isContinuation) {
            $previousAdditions = is_array($lastArgs['hypothetical_stock_additions'] ?? null)
                ? $lastArgs['hypothetical_stock_additions']
                : [];
            $currentAdditions = is_array($args['hypothetical_stock_additions'] ?? null)
                ? $args['hypothetical_stock_additions']
                : [];

            if ($previousAdditions && $currentAdditions) {
                $args['hypothetical_stock_additions'] = array_values(array_merge(
                    $previousAdditions,
                    $currentAdditions
                ));
            }
        }

        // Una continuación de un plan conserva los escenarios hipotéticos previos.
        if ($name === 'plan_production' && $isContinuation) {
            $previousAdditions = is_array($lastArgs['hypothetical_stock_additions'] ?? null)
                ? $lastArgs['hypothetical_stock_additions']
                : [];
            $currentAdditions = is_array($args['hypothetical_stock_additions'] ?? null)
                ? $args['hypothetical_stock_additions']
                : [];
            if ($previousAdditions && $currentAdditions) {
                $args['hypothetical_stock_additions'] = array_values(array_merge($previousAdditions, $currentAdditions));
            }
        }

        $action['arguments'] = array_filter(
            $args,
            fn ($value) => $value !== null,
        );

        return $action;
    }

    private function rememberUsefulReferences(array &$context, array $action): void
    {
        $args = is_array($action['arguments'] ?? null) ? $action['arguments'] : [];

        if (!empty($args['product_name'])) {
            $context['last_product_name'] = $args['product_name'];
        }

        if (($action['name'] ?? '') === 'plan_production' && !empty($args['production_items'])) {
            $context['last_production_plan'] = $args['production_items'];
        }
    }

    private function addRecentMessage(array $context, string $role, string $text): array
    {
        $messages = is_array($context['recent_messages'] ?? null)
            ? $context['recent_messages']
            : [];

        $messages[] = ['role' => $role, 'text' => $text];
        if (count($messages) > 12) {
            $messages = array_slice($messages, -12);
        }

        $context['recent_messages'] = $messages;
        return $context;
    }

    private function saveContext(AiConversation $conversation, array $context): void
    {
        DB::transaction(function () use ($conversation, $context) {
            $locked = AiConversation::whereKey($conversation->id)
                ->lockForUpdate()
                ->firstOrFail();

            $locked->update([
                'context' => $context,
                'pending_action' => $context['pending_action'] ?? null,
            ]);
        });
    }
}
