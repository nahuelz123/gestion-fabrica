<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\Log;

class GeminiService
{
    private const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/openai/chat/completions';
    private const MODEL = 'gemini-3-flash-preview';

    /**
     * Compatibilidad con el flujo legado.
     */
    public function analyzeText(string $text): array
    {
        $result = $this->analyzeConversation($text, []);

        return [
            'intent' => $result['intent'] ?? 'unknown',
            'product_name' => $result['action']['arguments']['product_name'] ?? null,
            'quantity' => $result['action']['arguments']['quantity'] ?? null,
        ];
    }

    public function analyzeConversation(string $text, array $context = []): array
    {
        Log::info('[GeminiDiag] analyzeConversation started', [
            'text_length' => strlen($text),
            'has_context' => !empty($context),
        ]);

        $apiKey = (string) config('services.gemini.api_key');
        if ($apiKey === '') {
            Log::error('GeminiService: No API key configured.');
            return $this->fallbackResponse('La IA no está configurada en este momento.');
        }

        $payload = [
            'model' => self::MODEL,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => $this->getSystemInstruction()
                        . "\n\nRespondé EXCLUSIVAMENTE con JSON válido con esta estructura: "
                        . json_encode($this->getResponseSchema(), JSON_UNESCAPED_UNICODE),
                ],
                [
                    'role' => 'user',
                    'content' => $this->buildPrompt($text, $context),
                ],
            ],
            'response_format' => ['type' => 'json_object'],
            'temperature' => 0.1,
        ];

        try {
            Log::info('[GeminiDiag] Sending HTTP POST to Gemini');
            $response = $this->postToGemini(self::ENDPOINT, $apiKey, $payload);

            Log::info('[GeminiDiag] Gemini HTTP response received', [
                'status' => $response['status'],
                'successful' => $response['successful'],
            ]);

            if (!$response['successful']) {
                Log::error('Gemini API HTTP error', [
                    'status' => $response['status'],
                    'body' => mb_substr($response['body'], 0, 800),
                ]);

                if ($response['status'] === 429) {
                    $seconds = $this->extractRetrySeconds($response['body']);
                    $reply = $seconds
                        ? "Llegamos momentáneamente al límite de consultas de IA. Probá de nuevo en unos {$seconds} segundos."
                        : 'Llegamos momentáneamente al límite de consultas de IA. Probá de nuevo en un momento.';

                    return $this->fallbackResponse($reply);
                }

                return $this->fallbackResponse();
            }

            $data = $response['json'];
            $jsonText = $data['choices'][0]['message']['content'] ?? '{}';
            $parsed = json_decode($jsonText, true);

            if (!is_array($parsed)) {
                Log::error('Gemini API JSON parse error', ['raw' => mb_substr($jsonText, 0, 800)]);
                return $this->fallbackResponse();
            }

            Log::info('[GeminiDiag] Gemini JSON keys received', [
                'keys' => array_keys($parsed),
                'intent' => $parsed['intent'] ?? '(missing)',
                'action_name' => $parsed['action']['name'] ?? null,
                'action_args_keys' => isset($parsed['action']['arguments'])
                    ? array_keys((array) $parsed['action']['arguments'])
                    : null,
            ]);

            return $this->validateAndFormatResponse($parsed);
        } catch (\Throwable $e) {
            Log::error('Gemini API Exception', [
                'class' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return $this->fallbackResponse();
        }
    }

    private function postToGemini(string $url, string $apiKey, array $payload): array
    {
        $encodedPayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encodedPayload === false) {
            throw new Exception('Could not encode Gemini payload.');
        }

        $ch = curl_init($url);
        if ($ch === false) {
            throw new Exception('Could not initialize cURL for Gemini.');
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $encodedPayload,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 45,
        ]);

        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno = curl_errno($ch);
        curl_close($ch);

        if ($body === false || $errno !== 0) {
            Log::error('Gemini native cURL transport error', ['curl_errno' => $errno]);
            throw new Exception('Gemini connection failed.');
        }

        $json = json_decode($body, true);

        return [
            'status' => $status,
            'successful' => $status >= 200 && $status < 300,
            'body' => $body,
            'json' => is_array($json) ? $json : [],
        ];
    }

    private function getSystemInstruction(): string
    {
        return <<<'PROMPT'
Sos el asistente virtual de una fábrica. Hablás en español argentino, entendés lenguaje natural y usás el contexto de la conversación.

Tu trabajo es INTERPRETAR. Laravel valida y ejecuta. Nunca inventes SQL ni ejecutes cambios por tu cuenta.

Reglas:
1. No obligues al usuario a escribir nombres exactos. Conservá la forma natural; Laravel resolverá singular/plural, tildes y coincidencias del catálogo.
2. Usá el contexto. Frases como "ese", "el anterior", "de todos", "y si además...", "sumale...", "lo mismo pero..." deben continuar la conversación anterior.
3. No preguntes un dato que ya aparece en provided_context, last_read_action, pending_action o mensajes recientes.
4. Las acciones de LECTURA no requieren confirmación: get_stock, get_low_stock, get_expiring_products, get_stock_movements, get_recipe, calculate_production, check_production, get_max_production, get_missing_inputs, plan_production.
5. Las acciones que MODIFICAN datos siempre requieren confirmación: create_product, update_product, register_stock, adjust_stock, set_stock, add_stock, remove_stock, register_production, create_recipe, update_recipe.
6. "Dame un resumen del stock", "stock de todo", "cómo estamos de mercadería", "inventario completo" => get_stock con product_name="all".
7. "Qué tenemos bajo", "qué falta", "stock crítico" => get_low_stock.
8. "Qué vence", "próximos vencimientos" => get_expiring_products. Si menciona días, mandá days.
9. "Qué movimientos hubo", "qué se cargó hoy", "historial de stock" => get_stock_movements. Podés mandar limit.
10. "Cuántos carros/carritos/bandejas podemos hacer" => get_max_production. 1 carro = 12 bandejas = 288 unidades; 1 bandeja = 24.
11. Escenarios hipotéticos como "si agrego 1000 bolsitas" NO cambian stock. Usá hypothetical_stock_additions dentro de get_max_production o plan_production.
12. Si el usuario continúa una simulación con "y si además...", preservá las simulaciones anteriores desde el contexto y agregá la nueva.
13. "Mañana quiero hacer 4 carros de cheddar, 2 de bacon y 3 de pollo" => plan_production con production_items. No modifica stock.
14. Para ingresos reales: "compramos", "entraron", "recibimos", "sumá" => add_stock/register_stock y requiere confirmación. Conservá presentation_name (caja, paquete, barra, etc.).
15. Si el usuario corrige una operación pendiente ("no, eran 8 cajas"), actualizá la misma acción y volvé a pedir confirmación.
16. Cuando diga "Sí", "dale", "confirmo", si hay pending_action, devolvé esa misma acción con requires_confirmation=false.
17. No inventes conversiones físicas. Si el usuario dice caja/paquete/barra, preservá presentation_name para que Laravel use la presentación registrada.

Acciones permitidas exclusivamente:
create_product, update_product, register_stock, adjust_stock, set_stock, add_stock, remove_stock, register_production, get_stock, get_low_stock, get_expiring_products, get_stock_movements, create_recipe, update_recipe, get_recipe, calculate_production, check_production, get_max_production, get_missing_inputs, plan_production, unknown.
PROMPT;
    }

    private function buildPrompt(string $text, array $context): string
    {
        return json_encode([
            'current_message' => $text,
            'provided_context' => $context,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    private function getResponseSchema(): array
    {
        $productItem = [
            'type' => 'OBJECT',
            'properties' => [
                'product_name' => ['type' => 'STRING', 'nullable' => true],
                'quantity' => ['type' => 'NUMBER', 'nullable' => true],
                'presentation_name' => ['type' => 'STRING', 'nullable' => true],
                'carros' => ['type' => 'NUMBER', 'nullable' => true],
                'bandejas' => ['type' => 'NUMBER', 'nullable' => true],
            ],
        ];

        return [
            'intent' => 'string',
            'reply' => 'string',
            'entities' => 'object',
            'action' => [
                'name' => 'string|null',
                'arguments' => [
                    'product_name' => 'string|null',
                    'presentation_name' => 'string|null',
                    'quantity' => 'number|null',
                    'carros' => 'number|null',
                    'bandejas' => 'number|null',
                    'days' => 'number|null',
                    'limit' => 'number|null',
                    'items' => [$productItem],
                    'production_items' => [$productItem],
                    'actual_consumptions' => [$productItem],
                    'hypothetical_stock_additions' => [$productItem],
                ],
            ],
            'missing' => ['string'],
            'requires_confirmation' => 'boolean',
            'confidence' => 'number',
        ];
    }

    private function validateAndFormatResponse(array $parsed): array
    {
        $valid = [
            'create_product', 'update_product', 'register_stock', 'adjust_stock',
            'set_stock', 'add_stock', 'remove_stock', 'register_production',
            'get_stock', 'get_low_stock', 'get_expiring_products', 'get_stock_movements',
            'create_recipe', 'update_recipe', 'get_recipe', 'calculate_production',
            'check_production', 'get_max_production', 'get_missing_inputs',
            'plan_production', 'unknown',
        ];

        $intent = in_array($parsed['intent'] ?? '', $valid, true)
            ? $parsed['intent']
            : 'unknown';

        $action = null;
        if (isset($parsed['action']) && is_array($parsed['action'])) {
            $name = $parsed['action']['name'] ?? null;
            $args = $parsed['action']['arguments'] ?? [];
            if (is_string($name) && in_array($name, $valid, true) && is_array($args)) {
                $action = ['name' => $name, 'arguments' => $args];
            }
        }

        return [
            'intent' => $intent,
            'reply' => (string) ($parsed['reply'] ?? 'Tuve un problema procesando eso. Probá nuevamente.'),
            'entities' => is_array($parsed['entities'] ?? null) ? $parsed['entities'] : [],
            'action' => $action,
            'missing' => is_array($parsed['missing'] ?? null) ? $parsed['missing'] : [],
            'requires_confirmation' => (bool) ($parsed['requires_confirmation'] ?? false),
            'confidence' => (float) ($parsed['confidence'] ?? 0.0),
        ];
    }

    private function extractRetrySeconds(string $body): ?int
    {
        if (preg_match('/retry in\s+([0-9.]+)s/i', $body, $matches)) {
            return max(1, (int) ceil((float) $matches[1]));
        }

        return null;
    }

    private function fallbackResponse(string $reply = 'Tuve un problema procesando eso. Probá nuevamente.'): array
    {
        return [
            'intent' => 'unknown',
            'reply' => $reply,
            'entities' => [],
            'action' => null,
            'missing' => [],
            'requires_confirmation' => false,
            'confidence' => 0.0,
        ];
    }
}
