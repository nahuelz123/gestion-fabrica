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
        private ProductionCalculatorService $productionCalculator,
        private BotActionPreviewService $previewService,
    ) {}

    public function processMessage(User $user, string $chatId, string $text): void
    {
        $conversation = AiConversation::firstOrCreate(['user_id'=>$user->id], ['telegram_chat_id'=>$chatId]);
        $context = is_array($conversation->context) ? $conversation->context : [];
        if (empty($context) && !empty($conversation->pending_action)) $context = ['status'=>'ready_for_confirmation','pending_action'=>$conversation->pending_action,'recent_messages'=>[]];
        $context = $this->addRecentMessage($context,'user',$text);
        $textNorm = trim(mb_strtolower($text));
        $currentStatus = $context['status'] ?? 'idle';

        $cancelWords = ['no','cancelar','cancelá','cancela','dejá','deja','mejor no'];
        if (in_array($textNorm,$cancelWords,true) && in_array($currentStatus,['collecting','ready_for_confirmation'],true)) {
            $context['status']='cancelled'; $context['pending_action']=null;
            $reply='Operación cancelada.'; $context=$this->addRecentMessage($context,'assistant',$reply);
            $this->saveContext($conversation,$context); $this->telegramService->sendMessage($chatId,$reply); return;
        }

        $affirmativeWords = ['si','sí','dale','confirmo','confirmar','ok','okay','yes'];
        if ($currentStatus === 'ready_for_confirmation' && in_array($textNorm,$affirmativeWords,true)) {
            $claimed = $this->claimPendingAction($conversation);
            if (!$claimed) {
                $reply='No hay una operación pendiente para confirmar.';
                $this->telegramService->sendMessage($chatId,$reply); return;
            }
            $pendingAction = $claimed['action'];
            $context = $claimed['context'];
            if (!$user->canUseBotAction($pendingAction['name'] ?? null)) {
                $reply=$this->permissionReply($user); $context['status']='idle'; $context['pending_action']=null;
                $context=$this->addRecentMessage($context,'assistant',$reply); $this->saveContext($conversation,$context);
                $this->telegramService->sendMessage($chatId,$reply); return;
            }
            $result=app(BotActionExecutor::class)->execute($user,$chatId,$pendingAction,$context);
            $reply=$result['message']; $context['status']=$result['success'] ? 'completed' : 'idle';
            if ($result['success']) { $context['last_completed_action']=$pendingAction; $context['pending_action']=null; }
            $context=$this->addRecentMessage($context,'assistant',$reply); $this->saveContext($conversation,$context);
            $this->telegramService->sendMessage($chatId,$reply); return;
        }

        $analysis=$this->geminiService->analyzeConversation($text,$context);
        if (($analysis['intent'] ?? 'unknown') === 'unknown' && empty($analysis['action'])) {
            $reply=$analysis['reply'] ?? 'Tuve un problema procesando eso. Probá nuevamente.';
            $context=$this->addRecentMessage($context,'assistant',$reply); $this->saveContext($conversation,$context);
            $this->telegramService->sendMessage($chatId,$reply); return;
        }

        $context['intent']=$analysis['intent'] ?? 'unknown';
        $context['entities']=array_merge($context['entities'] ?? [],array_filter($analysis['entities'] ?? [],fn($value)=>$value!==null && $value!==''));
        $action=is_array($analysis['action'] ?? null) ? $analysis['action'] : null;
        if ($action) $action=$this->enrichActionFromContext($action,$context,$textNorm);
        if ($action && !$user->canUseBotAction($action['name'] ?? null)) {
            $reply=$this->permissionReply($user); $context['status']='idle'; $context['pending_action']=null;
            $context=$this->addRecentMessage($context,'assistant',$reply); $this->saveContext($conversation,$context);
            $this->telegramService->sendMessage($chatId,$reply); return;
        }

        $readActions=['get_stock','get_low_stock','get_expiring_products','get_stock_movements','get_recipe','calculate_production','check_production','get_max_production','get_missing_inputs','plan_production'];
        $status='idle'; $actionToExecute=null; $actionName=$action['name'] ?? ''; $previewReply=null;
        if ($action && in_array($actionName,$readActions,true)) { $status='confirmed'; $actionToExecute=$action; }
        elseif ($action) { $status='ready_for_confirmation'; $context['pending_action']=$action; $previewReply=$this->previewService->preview($user,$action); }
        elseif (!empty($analysis['missing'])) $status='collecting';

        $context['status']=$status;
        $reply=$previewReply ?? ($analysis['reply'] ?? '¿Podés darme un poco más de detalle?');
        if ($status === 'confirmed' && $actionToExecute) {
            $result=app(BotActionExecutor::class)->execute($user,$chatId,$actionToExecute,$context);
            $reply=$result['message'];
            if ($result['success']) {
                $context['status']='completed'; $context['pending_action']=null; $context['last_read_action']=$actionToExecute;
                $this->rememberUsefulReferences($context,$actionToExecute);
            } else $context['status']='idle';
        }
        $context=$this->addRecentMessage($context,'assistant',$reply); $this->saveContext($conversation,$context);
        $this->telegramService->sendMessage($chatId,$reply);
    }

    private function claimPendingAction(AiConversation $conversation): ?array
    {
        return DB::transaction(function () use ($conversation) {
            $locked=AiConversation::whereKey($conversation->id)->lockForUpdate()->firstOrFail();
            $ctx=is_array($locked->context) ? $locked->context : [];
            if (($ctx['status'] ?? '') !== 'ready_for_confirmation' || empty($ctx['pending_action']) || !is_array($ctx['pending_action'])) return null;
            $action=$ctx['pending_action'];
            $ctx['status']='executing';
            $locked->update(['context'=>$ctx,'pending_action'=>$action]);
            return ['action'=>$action,'context'=>$ctx];
        });
    }

    private function permissionReply(User $user): string
    {
        return $user->isManager()
            ? 'Tu rol de encargado permite consultar y gestionar stock, pero no administrar productos, recetas, producción, compras ni otras configuraciones.'
            : 'No tenés permiso para realizar esa operación.';
    }

    private function enrichActionFromContext(array $action,array $context,string $textNorm): array
    {
        $name=$action['name'] ?? ''; $args=is_array($action['arguments'] ?? null) ? $action['arguments'] : [];
        $last=is_array($context['last_read_action'] ?? null) ? $context['last_read_action'] : []; $lastArgs=is_array($last['arguments'] ?? null) ? $last['arguments'] : [];
        if (empty($args['product_name']) && in_array($name,['get_max_production','calculate_production','check_production','get_missing_inputs'],true)) $args['product_name']=$lastArgs['product_name'] ?? ($context['last_product_name'] ?? null);
        $isContinuation=str_starts_with($textNorm,'y ') || str_contains($textNorm,'además') || str_contains($textNorm,'ademas') || str_contains($textNorm,'sumale') || str_contains($textNorm,'agregale') || str_contains($textNorm,'agregá') || str_contains($textNorm,'agrega');
        if (in_array($name,['get_max_production','plan_production'],true) && $isContinuation) {
            $previous=is_array($lastArgs['hypothetical_stock_additions'] ?? null) ? $lastArgs['hypothetical_stock_additions'] : [];
            $current=is_array($args['hypothetical_stock_additions'] ?? null) ? $args['hypothetical_stock_additions'] : [];
            if ($previous && $current) $args['hypothetical_stock_additions']=array_values(array_merge($previous,$current));
        }
        $action['arguments']=array_filter($args,fn($value)=>$value!==null); return $action;
    }

    private function rememberUsefulReferences(array &$context,array $action): void
    {
        $args=is_array($action['arguments'] ?? null) ? $action['arguments'] : [];
        if (!empty($args['product_name'])) $context['last_product_name']=$args['product_name'];
        if (($action['name'] ?? '') === 'plan_production' && !empty($args['production_items'])) $context['last_production_plan']=$args['production_items'];
    }

    private function addRecentMessage(array $context,string $role,string $text): array
    {
        $messages=is_array($context['recent_messages'] ?? null) ? $context['recent_messages'] : [];
        $messages[]=['role'=>$role,'text'=>$text];
        if (count($messages)>12) $messages=array_slice($messages,-12);
        $context['recent_messages']=$messages; return $context;
    }

    private function saveContext(AiConversation $conversation,array $context): void
    {
        DB::transaction(function () use ($conversation,$context) {
            $locked=AiConversation::whereKey($conversation->id)->lockForUpdate()->firstOrFail();
            $locked->update(['context'=>$context,'pending_action'=>$context['pending_action'] ?? null]);
        });
    }
}
