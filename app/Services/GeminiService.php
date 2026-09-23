<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;

class GeminiService
{
    /**
     * @deprecated Use analyzeConversation instead. Kept for backward compatibility during refactoring.
     */
    public function analyzeText(string $text): array
    {
        $apiKey = config('services.gemini.api_key');
        if (!$apiKey) {
            throw new Exception("No Gemini API key configured.");
        }

        $url = "https://generativelanguage.googleapis.com/v1beta/openai/chat/completions";

        $payload = [
            'model' => 'gemini-3-flash-preview',
            'messages' => [
                ['role' => 'system', 'content' => 'Sos el asistente virtual de un sistema de gestión de fábrica. Respondé exclusivamente con JSON válido.'],
                ['role' => 'user', 'content' => $text],
            ],
            'response_format' => ['type' => 'json_object'],
            'temperature' => 0.1,
        ];

        $response = Http::withToken($apiKey)
                ->connectTimeout(5)
                ->timeout(45)
                ->post($url, $payload);

        if (!$response->successful()) {
            throw new Exception("Gemini API error: " . $response->body());
        }

        $data = $response->json();
        $jsonText = $data['choices'][0]['message']['content'] ?? '{}';
        
        return json_decode($jsonText, true) ?? ['intent' => 'unknown'];
    }

    public function analyzeConversation(string $text, array $context = []): array
    {
        // [DIAG] Step 1: start
        Log::info('[GeminiDiag] analyzeConversation started', [
            'text_length' => strlen($text),
            'has_context' => !empty($context),
        ]);

        $apiKey = config('services.gemini.api_key');
        if (!$apiKey) {
            Log::error('GeminiService: No API key configured.');
            Log::warning('[GeminiDiag] FALLBACK reason: no API key configured');
            return $this->fallbackResponse();
        }

        // [DIAG] Step 2: API key exists (do NOT log the key itself)
        Log::info('[GeminiDiag] API key present, preparing HTTP request');

        $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-3-flash-preview:generateContent";

        $prompt = $this->buildPrompt($text, $context);

        $payload = [
            'model' => 'gemini-3-flash-preview',
            'messages' => [
                ['role' => 'system', 'content' => $this->getSystemInstruction() . "\n\nRespondé EXCLUSIVAMENTE con un objeto JSON válido que respete esta estructura: " . json_encode($this->getResponseSchema(), JSON_UNESCAPED_UNICODE)],
                ['role' => 'user', 'content' => $prompt],
            ],
            'response_format' => ['type' => 'json_object'],
            'temperature' => 0.1,
        ];

        try {
            // [DIAG] Step 2: sending request
            Log::info('[GeminiDiag] Sending HTTP POST to Gemini');

            $response = Http::withToken($apiKey)
                ->connectTimeout(5)
                ->timeout(45)
                ->post($url, $payload);

            // [DIAG] Step 3: HTTP status received
            Log::info('[GeminiDiag] Gemini HTTP response received', [
                'status' => $response->status(),
                'successful' => $response->successful(),
            ]);

            if (!$response->successful()) {
                // [DIAG] Step 4: non-2xx — log status and safe body excerpt
                $rawBody = $response->body();
                $safeBody = mb_substr($rawBody, 0, 800); // Truncate to avoid flooding logs
                Log::error('Gemini API HTTP error', ['status' => $response->status(), 'body' => $safeBody]);
                Log::warning('[GeminiDiag] FALLBACK reason: HTTP non-successful', ['status' => $response->status()]);
                return $this->fallbackResponse();
            }

            $data = $response->json();

            // [DIAG] Step 5a: successful response — log top-level structure (no credentials)
            $candidateCount = count($data['choices'] ?? []);
            $hasTextPart = isset($data['choices'][0]['message']['content']);
            Log::info('[GeminiDiag] Gemini successful response structure', [
                'candidate_count' => $candidateCount,
                'has_text_part' => $hasTextPart,
                'finish_reason' => $data['choices'][0]['finish_reason'] ?? 'N/A',
                'prompt_feedback' => null,
            ]);

            $jsonText = $data['choices'][0]['message']['content'] ?? '{}';

            // [DIAG] Step 5b: log parsed keys (not values) so we can see what Gemini returned
            $previewParsed = json_decode($jsonText, true);
            if (is_array($previewParsed)) {
                Log::info('[GeminiDiag] Gemini JSON keys received', [
                    'keys' => array_keys($previewParsed),
                    'has_reply' => isset($previewParsed['reply']),
                    'has_action' => isset($previewParsed['action']) && !is_null($previewParsed['action']),
                    'intent' => $previewParsed['intent'] ?? '(missing)',
                    'action_name' => $previewParsed['action']['name'] ?? null,
                    'action_args_keys' => isset($previewParsed['action']['arguments'])
                        ? array_keys((array) $previewParsed['action']['arguments'])
                        : null,
                ]);
            }

            $parsed = json_decode($jsonText, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                // [DIAG] Step 6: JSON decode failure
                Log::error('Gemini API JSON parse error', ['raw' => $jsonText]);
                Log::warning('[GeminiDiag] FALLBACK reason: JSON decode failed', [
                    'json_error' => json_last_error_msg(),
                ]);
                return $this->fallbackResponse();
            }

            Log::info('[GeminiDiag] JSON decoded successfully, passing to validateAndFormatResponse');
            return $this->validateAndFormatResponse($parsed);

        } catch (\Throwable $e) {
            // [DIAG] Step 7: exception
            Log::error('Gemini API Exception', [
                'class' => get_class($e),
                // Never log the raw exception message here: HTTP client exceptions may
                // contain the full request URL and could expose credentials.
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            Log::warning('[GeminiDiag] FALLBACK reason: exception thrown', [
                'exception_class' => get_class($e),
            ]);
            return $this->fallbackResponse();
        }
    }

    private function getSystemInstruction(): string
    {
        return "Sos el asistente virtual experto de una fábrica.
Reglas estrictas:
1. NUNCA ejecutes sentencias SQL ni código. Eres un intérprete de lenguaje natural.
2. Comprendé el contexto. Si el usuario te da una cantidad, y estábamos creando un producto, es el stock inicial o rendimiento, según el contexto.
3. Si el usuario corrige información ('me equivoqué, eran 25', 'no, paquete de 1000', 'insumo'), actualizá las entidades en lugar de iniciar una acción nueva.
4. Identificá referencias naturales ('ese producto', 'el anterior', 'lo mismo pero') indicando la necesidad de resolver la entidad.
5. NO inventes acciones ni nombres. Usá solo los definidos.
6. Si una acción requiere confirmación y los datos están listos, pedí confirmación. NO preguntes datos que ya se encuentran en el contexto.
7. Las acciones (action.name) PERMITIDAS EXCLUSIVAMENTE SON:
create_product, update_product, register_stock, adjust_stock, set_stock, add_stock, remove_stock, register_production, get_stock, get_low_stock, get_expiring_products, create_recipe, update_recipe, get_recipe, calculate_production, check_production, get_missing_inputs, unknown.
8. Si el usuario confirma ('sí', 'dale') y hay una acción lista, emite el action correspondiente y requiere confirmación false.
9. Para set_stock: cuando el usuario dice 'el stock de X es Y' o 'establecé stock de X en Y'. Si informa varios ('Nuevo inventario: X es Y, Z es W'), pasá un array 'items' dentro de arguments con [{product_name, quantity}].
10. Para add_stock: cuando el usuario dice 'sumá/agregá N de X'.
11. Para remove_stock: cuando el usuario dice 'usamos/restá N de X' o informa un consumo.
12. Para register_production: cuando el usuario informa producción terminada. Incluir carros y bandejas como argumentos (carros, bandejas, quantity). 1 carro = 12 bandejas = 288 u. Si el usuario informa consumos reales (ej: 'usamos 7 piezas de bacon'), pasá un array 'actual_consumptions' en arguments con [{product_name, quantity, presentation_name}]. Si corrige un consumo, actualizalo y pedí confirmación de nuevo (requires_confirmation=true).";
    }

    private function buildPrompt(string $text, array $context): string
    {
        return json_encode([
            'current_message' => $text,
            'provided_context' => $context
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    private function getResponseSchema(): array
    {
        return [
            'type' => 'OBJECT',
            'properties' => [
                'intent' => [
                    'type' => 'STRING',
                    'enum' => [
                        'create_product', 'update_product', 'register_stock', 'adjust_stock',
                        'set_stock', 'add_stock', 'remove_stock', 'register_production',
                        'get_stock', 'get_low_stock', 'get_expiring_products', 'create_recipe',
                        'update_recipe', 'get_recipe', 'calculate_production', 'check_production',
                        'get_missing_inputs', 'unknown'
                    ]
                ],
                'reply' => ['type' => 'STRING', 'description' => 'Respuesta amigable para el usuario'],
                'entities' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'name' => ['type' => 'STRING', 'nullable' => true],
                        'presentation' => ['type' => 'STRING', 'nullable' => true],
                        'type' => ['type' => 'STRING', 'nullable' => true],
                        'quantity' => ['type' => 'NUMBER', 'nullable' => true],
                        'initial_stock' => ['type' => 'NUMBER', 'nullable' => true]
                    ]
                ],
                'action' => [
                    'type' => 'OBJECT',
                    'nullable' => true,
                    'properties' => [
                        'name' => ['type' => 'STRING'],
                        'arguments' => [
                            'type' => 'OBJECT',
                            'properties' => [
                                'name' => ['type' => 'STRING', 'nullable' => true],
                                'presentation' => ['type' => 'STRING', 'nullable' => true],
                                'product_name' => ['type' => 'STRING', 'nullable' => true],
                                'presentation_name' => ['type' => 'STRING', 'nullable' => true],
                                'quantity' => ['type' => 'NUMBER', 'nullable' => true],
                                'initial_stock' => ['type' => 'NUMBER', 'nullable' => true],
                                'type' => ['type' => 'STRING', 'nullable' => true],
                                'yield_quantity' => ['type' => 'NUMBER', 'nullable' => true],
                                'target_quantity' => ['type' => 'NUMBER', 'nullable' => true],
                                'carros' => ['type' => 'NUMBER', 'nullable' => true],
                                'bandejas' => ['type' => 'NUMBER', 'nullable' => true],
                                'items' => [
                                    'type' => 'ARRAY',
                                    'nullable' => true,
                                    'items' => [
                                        'type' => 'OBJECT',
                                        'properties' => [
                                            'product_name' => ['type' => 'STRING', 'nullable' => true],
                                            'quantity' => ['type' => 'NUMBER', 'nullable' => true],
                                            'presentation_name' => ['type' => 'STRING', 'nullable' => true],
                                        ]
                                    ]
                                ],
                                'actual_consumptions' => [
                                    'type' => 'ARRAY',
                                    'nullable' => true,
                                    'items' => [
                                        'type' => 'OBJECT',
                                        'properties' => [
                                            'product_name' => ['type' => 'STRING', 'nullable' => true],
                                            'quantity' => ['type' => 'NUMBER', 'nullable' => true],
                                            'presentation_name' => ['type' => 'STRING', 'nullable' => true],
                                        ]
                                    ]
                                ]
                            ]
                        ]
                    ]
                ],
                'missing' => [
                    'type' => 'ARRAY',
                    'items' => ['type' => 'STRING']
                ],
                'requires_confirmation' => ['type' => 'BOOLEAN'],
                'confidence' => ['type' => 'NUMBER']
            ],
            'required' => ['intent', 'reply', 'entities', 'missing', 'requires_confirmation', 'confidence']
        ];
    }

    private function validateAndFormatResponse(array $parsed): array
    {
        $validActionsAndIntents = [
            'create_product', 'update_product', 'register_stock', 'adjust_stock',
            'set_stock', 'add_stock', 'remove_stock', 'register_production',
            'get_stock', 'get_low_stock', 'get_expiring_products', 'create_recipe',
            'update_recipe', 'get_recipe', 'calculate_production', 'check_production',
            'get_missing_inputs', 'unknown'
        ];

        $intent = in_array($parsed['intent'] ?? '', $validActionsAndIntents) ? $parsed['intent'] : 'unknown';
        
        $action = null;
        if (isset($parsed['action']) && is_array($parsed['action'])) {
            $actionName = $parsed['action']['name'] ?? null;
            $actionArgs = $parsed['action']['arguments'] ?? [];
            
            if (
                is_string($actionName) && 
                in_array($actionName, $validActionsAndIntents) && 
                is_array($actionArgs)
            ) {
                $action = [
                    'name' => $actionName,
                    'arguments' => $actionArgs
                ];
            }
        }

        return [
            'intent' => $intent,
            'reply' => $parsed['reply'] ?? 'Tuve un problema procesando eso. Probá nuevamente.',
            'entities' => $parsed['entities'] ?? [],
            'action' => $action,
            'missing' => is_array($parsed['missing'] ?? null) ? $parsed['missing'] : [],
            'requires_confirmation' => (bool) ($parsed['requires_confirmation'] ?? false),
            'confidence' => (float) ($parsed['confidence'] ?? 0.0)
        ];
    }

    private function fallbackResponse(): array
    {
        return [
            'intent' => 'unknown',
            'reply' => 'Tuve un problema procesando eso. Probá nuevamente.',
            'entities' => [],
            'action' => null,
            'missing' => [],
            'requires_confirmation' => false,
            'confidence' => 0.0
        ];
    }
}
