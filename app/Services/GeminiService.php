<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeminiService
{
    private const ENDPOINT='https://generativelanguage.googleapis.com/v1beta/openai/chat/completions';

    public function analyzeText(string $text): array
    {
        $result=$this->analyzeConversation($text,[]);
        return ['intent'=>$result['intent'] ?? 'unknown','product_name'=>$result['action']['arguments']['product_name'] ?? null,'quantity'=>$result['action']['arguments']['quantity'] ?? null];
    }

    public function analyzeConversation(string $text,array $context=[]): array
    {
        Log::info('[GeminiDiag] analyzeConversation started',['text_length'=>strlen($text),'has_context'=>!empty($context)]);
        $apiKey=(string)config('services.gemini.api_key');
        if ($apiKey==='') return $this->fallbackResponse('La IA no está configurada en este momento.');
        $payload=['messages'=>[
            ['role'=>'system','content'=>$this->getSystemInstruction()."\n\nRespondé EXCLUSIVAMENTE con JSON válido con esta estructura: ".json_encode($this->getResponseSchema(),JSON_UNESCAPED_UNICODE)],
            ['role'=>'user','content'=>$this->buildPrompt($text,$context)],
        ],'response_format'=>['type'=>'json_object'],'temperature'=>0.1];
        try {
            $response=$this->requestWithResilience($apiKey,$payload);
            if (!$response['successful']) {
                Log::error('Gemini API HTTP error',['status'=>$response['status'],'model'=>$response['model'] ?? null,'body'=>mb_substr($response['body'],0,800)]);
                if ($response['status']===429) {
                    $seconds=$this->extractRetrySeconds($response['body']);
                    return $this->fallbackResponse($seconds ? "Llegamos momentáneamente al límite de consultas de IA. Probá de nuevo en unos {$seconds} segundos." : 'Llegamos momentáneamente al límite de consultas de IA. Probá de nuevo en un momento.');
                }
                if (in_array($response['status'],[502,503,504],true)) return $this->fallbackResponse('La IA está con mucha demanda o temporalmente no disponible. Ya reintenté la consulta. Probá otra vez en unos segundos.');
                return $this->fallbackResponse();
            }
            $jsonText=$response['json']['choices'][0]['message']['content'] ?? '{}';
            $parsed=json_decode($jsonText,true);
            if (!is_array($parsed)) return $this->fallbackResponse();
            return $this->validateAndFormatResponse($parsed);
        } catch (\Throwable $e) {
            Log::error('Gemini API Exception',['class'=>get_class($e),'file'=>$e->getFile(),'line'=>$e->getLine()]);
            return $this->fallbackResponse('No pude comunicarme con la IA en este momento. Probá nuevamente en unos segundos.');
        }
    }

    private function requestWithResilience(string $apiKey,array $basePayload): array
    {
        $primary=trim((string)config('services.gemini.model','gemini-3-flash-preview')) ?: 'gemini-3-flash-preview';
        $fallback=trim((string)config('services.gemini.fallback_model',''));
        $plan=[['model'=>$primary,'delay_ms'=>0],['model'=>$primary,'delay_ms'=>1200],['model'=>$primary,'delay_ms'=>2800]];
        if ($fallback!=='' && $fallback!==$primary) $plan[]=['model'=>$fallback,'delay_ms'=>800];
        $last=null;
        foreach ($plan as $index=>$step) {
            if ($step['delay_ms']>0 && !app()->environment('testing')) usleep($step['delay_ms']*1000);
            $payload=$basePayload; $payload['model']=$step['model'];
            Log::info('[GeminiDiag] Sending HTTP POST to Gemini',['attempt'=>$index+1,'model'=>$step['model']]);
            $response=$this->postToGemini(self::ENDPOINT,$apiKey,$payload); $response['model']=$step['model']; $response['attempt']=$index+1; $last=$response;
            if ($response['successful']) return $response;
            if (!in_array($response['status'],[502,503,504],true)) return $response;
        }
        return $last ?? ['status'=>503,'successful'=>false,'body'=>'','json'=>[],'model'=>$primary,'attempt'=>0];
    }

    private function postToGemini(string $url,string $apiKey,array $payload): array
    {
        $response=Http::withToken($apiKey)->acceptJson()->asJson()->connectTimeout(10)->timeout(45)->post($url,$payload);
        $json=$response->json();
        return ['status'=>$response->status(),'successful'=>$response->successful(),'body'=>$response->body(),'json'=>is_array($json)?$json:[]];
    }

    private function getSystemInstruction(): string
    {
        return <<<'PROMPT'
Sos el asistente virtual de una fábrica. Hablás en español argentino, entendés lenguaje natural y usás el contexto de la conversación.
Tu trabajo es INTERPRETAR. Laravel valida y ejecuta. Nunca inventes SQL ni ejecutes cambios por tu cuenta.

Reglas:
1. No obligues al usuario a escribir nombres exactos. Conservá la forma natural; Laravel resolverá singular/plural, tildes y coincidencias del catálogo.
2. Usá el contexto y no vuelvas a pedir datos ya presentes.
3. Lecturas no requieren confirmación: get_stock, get_low_stock, get_expiring_products, get_stock_movements, get_recipe, calculate_production, check_production, get_max_production, get_missing_inputs, plan_production.
4. Modificaciones siempre requieren confirmación: create_product, update_product, register_stock, adjust_stock, set_stock, add_stock, remove_stock, register_production, create_recipe, update_recipe.
5. Resumen de stock => get_stock product_name="all". Stock crítico => get_low_stock. Vencimientos => get_expiring_products. Historial => get_stock_movements.
6. 1 carro = 12 bandejas = 288 unidades; 1 bandeja = 24. Escenarios "si agrego" son hipotéticos y no modifican stock.
7. Ingresos reales (compramos, entraron, recibimos, sumá) => add_stock/register_stock y requieren confirmación. Preservá SIEMPRE presentation_name cuando el usuario diga cajas, barras, paquetes, piezas, bolsas o unidades. Si una lista omite la unidad después de haberla dicho, heredala sólo mientras la frase siga coordinada. Ejemplo: "10 barras de cheddar, 4 de jamón y 5 de queso" => los tres items llevan presentation_name="barra". "60 cajas de medallón y 48 cajas de pan" => ambos llevan presentation_name="caja".
8. Si corrige una operación pendiente, actualizá la acción y volvé a pedir confirmación.
9. No inventes conversiones físicas ni conviertas por tu cuenta. Tu obligación es conservar la presentación expresada por el usuario; Laravel usa las presentaciones registradas para calcular unidades base.
10. Para crear producto usá name, type si se conoce y presentation_name; si no hay presentación puede ser "unidad".
11. Para crear/actualizar receta usá product_name, yield_quantity e items con product_name y quantity. Nunca inventes ingredientes ni cantidades.

Acciones permitidas exclusivamente:
create_product, update_product, register_stock, adjust_stock, set_stock, add_stock, remove_stock, register_production, get_stock, get_low_stock, get_expiring_products, get_stock_movements, create_recipe, update_recipe, get_recipe, calculate_production, check_production, get_max_production, get_missing_inputs, plan_production, unknown.
PROMPT;
    }

    private function buildPrompt(string $text,array $context): string { return json_encode(['current_message'=>$text,'provided_context'=>$context],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT); }

    private function getResponseSchema(): array
    {
        $productItem=['type'=>'OBJECT','properties'=>['product_name'=>['type'=>'STRING','nullable'=>true],'quantity'=>['type'=>'NUMBER','nullable'=>true],'presentation_name'=>['type'=>'STRING','nullable'=>true],'carros'=>['type'=>'NUMBER','nullable'=>true],'bandejas'=>['type'=>'NUMBER','nullable'=>true]]];
        return [
            'intent'=>'string','reply'=>'string','entities'=>'object',
            'action'=>['name'=>'string|null','arguments'=>[
                'name'=>'string|null','new_name'=>'string|null','product_name'=>'string|null','type'=>'string|null','presentation_name'=>'string|null','barcode'=>'string|null',
                'quantity'=>'number|null','yield_quantity'=>'number|null','min_stock'=>'number|null','shelf_life_days'=>'number|null','requires_lot'=>'boolean|null','requires_expiration'=>'boolean|null','notes'=>'string|null',
                'carros'=>'number|null','bandejas'=>'number|null','days'=>'number|null','limit'=>'number|null','items'=>[$productItem],'production_items'=>[$productItem],'actual_consumptions'=>[$productItem],'hypothetical_stock_additions'=>[$productItem],
            ]],
            'missing'=>['string'],'requires_confirmation'=>'boolean','confidence'=>'number',
        ];
    }

    private function validateAndFormatResponse(array $parsed): array
    {
        $valid=['create_product','update_product','register_stock','adjust_stock','set_stock','add_stock','remove_stock','register_production','get_stock','get_low_stock','get_expiring_products','get_stock_movements','create_recipe','update_recipe','get_recipe','calculate_production','check_production','get_max_production','get_missing_inputs','plan_production','unknown'];
        $intent=in_array($parsed['intent'] ?? '', $valid,true) ? $parsed['intent'] : 'unknown';
        $action=null;
        if (isset($parsed['action']) && is_array($parsed['action'])) {
            $name=$parsed['action']['name'] ?? null; $args=$parsed['action']['arguments'] ?? [];
            if (is_string($name) && in_array($name,$valid,true) && is_array($args)) $action=['name'=>$name,'arguments'=>$args];
        }
        return ['intent'=>$intent,'reply'=>(string)($parsed['reply'] ?? 'Tuve un problema procesando eso. Probá nuevamente.'),'entities'=>is_array($parsed['entities'] ?? null)?$parsed['entities']:[],'action'=>$action,'missing'=>is_array($parsed['missing'] ?? null)?$parsed['missing']:[],'requires_confirmation'=>(bool)($parsed['requires_confirmation'] ?? false),'confidence'=>(float)($parsed['confidence'] ?? 0.0)];
    }

    private function extractRetrySeconds(string $body): ?int
    {
        if (preg_match('/retry in\s+([0-9.]+)s/i',$body,$matches)) return max(1,(int)ceil((float)$matches[1]));
        $json=json_decode($body,true); $delay=data_get($json,'error.details.0.retryDelay');
        if (is_string($delay) && preg_match('/^([0-9.]+)s$/',$delay,$matches)) return max(1,(int)ceil((float)$matches[1]));
        return null;
    }

    private function fallbackResponse(string $reply='Tuve un problema procesando eso. Probá nuevamente.'): array
    {
        return ['intent'=>'unknown','reply'=>$reply,'entities'=>[],'action'=>null,'missing'=>[],'requires_confirmation'=>false,'confidence'=>0.0];
    }
}
