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
        $analysis=$this->repairProductionLanguage($analysis,$textNorm);

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

    /**
     * Production is a critical factory workflow, so common phrases are repaired
     * deterministically instead of relying exclusively on the LLM.
     *
     * Examples:
     * - "quiero hacer 3 carritos de bacon" => check_production, 864 u
     * - "hicimos 3 carros de bacon" => register_production (confirmation required)
     */
    private function repairProductionLanguage(array $analysis,string $text): array
    {
        $parsed=$this->parseProductionPhrase($text);
        if (!$parsed) return $analysis;

        $action=is_array($analysis['action'] ?? null) ? $analysis['action'] : null;
        $existingName=is_string($action['name'] ?? null) ? $action['name'] : null;
        $productionActions=[
            'calculate_production','check_production','get_missing_inputs',
            'get_max_production','register_production',
        ];

        $completed=$parsed['completed'];
        $name=$completed
            ? 'register_production'
            : (in_array($existingName,$productionActions,true) && $existingName !== 'register_production'
                ? $existingName
                : 'check_production');

        // "Quiero hacer..." is operational language: by default we answer whether
        // the requested production is feasible, not just list ingredients.
        if (!$completed && $parsed['planning']) {
            $name='check_production';
        }

        $args=is_array($action['arguments'] ?? null) ? $action['arguments'] : [];
        $args['product_name']=$parsed['product_name'];
        if ($parsed['carros'] > 0) $args['carros']=$parsed['carros'];
        if ($parsed['bandejas'] > 0) $args['bandejas']=$parsed['bandejas'];
        $args['quantity']=$parsed['quantity'];

        if ($name==='register_production' && empty($args['actual_consumptions'])) {
            $actual=$this->parseSimpleActualConsumption($text,$parsed['product_name']);
            if ($actual) $args['actual_consumptions']=[$actual];
        }

        $analysis['intent']=$name;
        $analysis['action']=['name'=>$name,'arguments'=>$args];
        $analysis['missing']=[];
        $analysis['requires_confirmation']=$name==='register_production';
        $analysis['confidence']=max((float)($analysis['confidence'] ?? 0),0.99);

        return $analysis;
    }

    private function parseProductionPhrase(string $text): ?array
    {
        $normalized=trim(mb_strtolower($text));
        $number='(?:\\d+(?:[\\.,]\\d+)?|un|una|uno|dos|tres|cuatro|cinco|seis|siete|ocho|nueve|diez|once|doce)';

        $carros=0.0;
        $bandejas=0.0;
        $product=null;

        if (preg_match('/\\b(?<carros>'.$number.')\\s+(?:carritos?|carros?)\\s*(?:y|\\+)?\\s*(?<bandejas>'.$number.')\\s+bandejas?\\s+(?:de\\s+)?(?<product>.+)$/iu',$normalized,$match)) {
            $carros=$this->spanishNumber((string)$match['carros']);
            $bandejas=$this->spanishNumber((string)$match['bandejas']);
            $product=(string)$match['product'];
        }
        // "3 carros y medio de bacon"
        elseif (preg_match('/\\b(?<carros>'.$number.')\\s+(?:carritos?|carros?)\\s+y\\s+medio\\s+(?:de\\s+)?(?<product>.+)$/iu',$normalized,$match)) {
            $carros=$this->spanishNumber((string)$match['carros']) + 0.5;
            $product=(string)$match['product'];
        }
        // "3 carritos de bacon" / "2 bandejas de cheddar"
        elseif (preg_match('/\\b(?<amount>'.$number.')\\s+(?<unit>carritos?|carros?|bandejas?)\\s+(?:de\\s+)?(?<product>.+)$/iu',$normalized,$match)) {
            $amount=$this->spanishNumber((string)$match['amount']);
            if ($amount <= 0) return null;

            if (str_starts_with(mb_strtolower((string)$match['unit']),'bandeja')) {
                $bandejas=$amount;
            } else {
                $carros=$amount;
            }
            $product=(string)$match['product'];
        } else {
            return null;
        }

        if ($carros <= 0 && $bandejas <= 0) return null;

        $product=trim((string)$product);
        // Closing-production details belong to actual_consumptions and must not
        // become part of the product name. "jamón y queso" remains intact.
        $product=preg_split('/\\s+y\\s+(?:usamos|gastamos|consumimos|qued[oó]|sobr[oó])\\b/iu',$product,2)[0] ?? $product;
        $product=trim($product," \t\n\r\0\x0B.,;:!?");
        if ($product==='') return null;

        $completed=(bool)preg_match('/\\b(hicimos|terminamos|producimos|fabricamos|salieron|salio|salió)\\b/iu',$normalized);
        $planning=(bool)preg_match('/\\b(quiero|queremos|vamos|necesito|necesitamos|podemos|puedo|hacer|producir|alcanza|alcanzan)\\b/iu',$normalized);

        return [
            'carros'=>$carros,
            'bandejas'=>$bandejas,
            'quantity'=>($carros * 288) + ($bandejas * 24),
            'product_name'=>$product,
            'completed'=>$completed,
            'planning'=>$planning,
        ];
    }

    /**
     * Factory convention: when the operator says "usamos 8 piezas y quedó
     * media", 8 is the number of pieces opened/taken and the effective
     * consumption is 7.5. The remaining 0.5 stays in stock.
     */
    private function parseSimpleActualConsumption(string $text,string $finishedProduct): ?array
    {
        if (!preg_match('/\\b(?:usamos|gastamos|consumimos|abrimos)\\s+(?<amount>\\d+(?:[\\.,]\\d+)?)\\s+(?<unit>piezas?|barras?|cajas?|unidades?|fetas?)(?:\\s+de\\s+(?<ingredient>[^,.;]+?))?(?=\\s+y\\s+(?:qued[oó]|sobr[oó])\\b|$)/iu',$text,$match)) {
            return null;
        }

        $amount=(float)str_replace(',','.',(string)$match['amount']);
        if ($amount <= 0) return null;

        $remainder=0.0;
        if (preg_match('/\\b(?:qued[oó]|sobr[oó])\\s+(?<remainder>media|medio|un cuarto|0[\\.,]5|0[\\.,]25)\\s*(?:pieza|barra|caja|unidad|feta)?/iu',$text,$remaining)) {
            $value=mb_strtolower((string)$remaining['remainder']);
            $remainder=str_contains($value,'cuarto') || str_replace(',','.',$value)==='0.25' ? 0.25 : 0.5;
        }

        $effective=$amount-$remainder;
        if ($effective <= 0) return null;

        $ingredient=trim((string)($match['ingredient'] ?? ''));
        if ($ingredient==='') {
            $normalized=$this->normalizeFactoryProductName($finishedProduct);
            if (str_contains($normalized,'bacon')) $ingredient='bacon';
            elseif (str_contains($normalized,'lomito')) $ingredient='lomito';
            elseif (str_contains($normalized,'cheddar') || str_contains($normalized,'chedar')) $ingredient='cheddar';
            else return null; // Jamón y queso is ambiguous without naming the ingredient.
        }

        return [
            'product_name'=>trim($ingredient),
            'quantity'=>$effective,
            'presentation_name'=>$this->singularPresentationWord((string)$match['unit']),
        ];
    }

    private function normalizeFactoryProductName(string $value): string
    {
        $value=mb_strtolower($value);
        $value=strtr($value,['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ñ'=>'n']);
        return trim((string)preg_replace('/\\s+/u',' ',$value));
    }

    private function spanishNumber(string $value): float
    {
        $value=str_replace(',','.',mb_strtolower(trim($value)));
        if (is_numeric($value)) return (float)$value;

        return match($value) {
            'un','una','uno' => 1.0,
            'dos' => 2.0,
            'tres' => 3.0,
            'cuatro' => 4.0,
            'cinco' => 5.0,
            'seis' => 6.0,
            'siete' => 7.0,
            'ocho' => 8.0,
            'nueve' => 9.0,
            'diez' => 10.0,
            'once' => 11.0,
            'doce' => 12.0,
            default => 0.0,
        };
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
            ? 'Tu rol de encargado permite consultar y gestionar stock y registrar producción, pero no administrar productos, recetas, compras ni otras configuraciones.'
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
        if (in_array($name,['add_stock','register_stock','adjust_stock'],true) && !empty($args['items']) && is_array($args['items'])) {
            $args['items']=$this->inferStockPresentationsFromText($args['items'],$textNorm);
        }

        $action['arguments']=array_filter($args,fn($value)=>$value!==null); return $action;
    }

    /**
     * Refuerza una debilidad típica del lenguaje natural: en frases como
     * "10 barras de cheddar, 4 de jamón y 5 de queso", la unidad se expresa
     * una sola vez. Conservamos esa unidad para los elementos coordinados,
     * pero sólo cuando el siguiente número aparece como "N de ...".
     */
    private function inferStockPresentationsFromText(array $items,string $text): array
    {
        $cursor=0; $lastPresentation=null;
        $unitPattern='cajas?|barras?|piezas?|paquetes?|bolsas?|unidades?|fetas?';

        foreach ($items as $index=>$item) {
            if (!is_array($item) || !isset($item['quantity'])) continue;

            $quantity=(float)$item['quantity'];
            if ($quantity <= 0) continue;

            $quantityPattern=preg_quote($this->plainNumber($quantity),'/');
            $remaining=substr($text,$cursor);

            if (!preg_match('/\\b'.$quantityPattern.'\\b(?:\\s+(?<unit>'.$unitPattern.'))?(?<de>\\s+de\\b)?/iu',$remaining,$match,PREG_OFFSET_CAPTURE)) {
                continue;
            }

            $full=$match[0][0] ?? '';
            $offset=(int)($match[0][1] ?? 0);
            $cursor += $offset + strlen($full);

            $unit=isset($match['unit'][0]) ? trim((string)$match['unit'][0]) : '';
            $hasDe=isset($match['de'][0]) && trim((string)$match['de'][0]) !== '';

            if ($unit !== '') {
                $lastPresentation=$this->singularPresentationWord($unit);
                if (empty($item['presentation_name'])) {
                    $items[$index]['presentation_name']=$lastPresentation;
                }
                continue;
            }

            if (empty($item['presentation_name']) && $hasDe && $lastPresentation) {
                $items[$index]['presentation_name']=$lastPresentation;
            } elseif (!$hasDe) {
                // "2000 bolsitas" empieza una nueva expresión y no debe heredar
                // "pieza" de "10 de lomito".
                $lastPresentation=null;
            }

            if (!empty($item['presentation_name'])) {
                $lastPresentation=$this->singularPresentationWord((string)$item['presentation_name']);
            }
        }

        return $items;
    }

    private function singularPresentationWord(string $value): string
    {
        $value=mb_strtolower(trim($value));
        return match (true) {
            str_starts_with($value,'caja') => 'caja',
            str_starts_with($value,'barra') => 'barra',
            str_starts_with($value,'pieza') => 'pieza',
            str_starts_with($value,'paquete') => 'paquete',
            str_starts_with($value,'bolsa') => 'bolsa',
            str_starts_with($value,'feta') => 'feta',
            default => 'unidad',
        };
    }

    private function plainNumber(float $value): string
    {
        return abs($value-round($value)) < 0.00001
            ? (string)(int)round($value)
            : rtrim(rtrim(number_format($value,2,'.',''),'0'),'.');
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
