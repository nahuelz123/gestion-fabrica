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

        $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-3-flash-preview:generateContent?key={$apiKey}";

        $payload = [
            'contents' => [
                ['parts' => [['text' => $text]]]
            ],
            'systemInstruction' => [
                'parts' => [
                    ['text' => 'Sos el asistente virtual de un sistema de gestión de fábrica...']
                ]
            ],
            'generationConfig' => [
                'responseMimeType' => 'application/json',
                'responseSchema' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'intent' => [
                            'type' => 'STRING',
                            'enum' => ['check_stock', 'propose_stock_entry', 'calculate_production', 'unknown']
                        ],
                        'product_name' => [
                            'type' => 'STRING',
                            'nullable' => true,
                            'description' => 'El nombre del insumo o producto mencionado.'
                        ],
                        'quantity' => [
                            'type' => 'NUMBER',
                            'nullable' => true,
                            'description' => 'La cantidad mencionada. Si no se especifica, nulo.'
                        ]
                    ],
                    'required' => ['intent']
                ]
            ]
        ];

        $response = Http::timeout(10)->post($url, $payload);

        if (!$response->successful()) {
            throw new Exception("Gemini API error: " . $response->body());
        }

        $data = $response->json();
        $jsonText = $data['candidates'][0]['content']['parts'][0]['text'] ?? '{}';
        
        return json_decode($jsonText, true) ?? ['intent' => 'unknown'];
    }

    public function analyzeConversation(string $text, array $context = []): array
    {
        $apiKey = config('services.gemini.api_key');
        if (!$apiKey) {
            Log::error('GeminiService: No API key configured.');
            return $this->fallbackResponse();
        }

        $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-3-flash-preview:generateContent?key={$apiKey}";

        $prompt = $this->buildPrompt($text, $context);

        $payload = [
            'contents' => [
                ['parts' => [['text' => $prompt]]]
            ],
            'systemInstruction' => [
                'parts' => [
                    ['text' => $this->getSystemInstruction()]
                ]
            ],
            'generationConfig' => [
                'responseMimeType' => 'application/json',
                'responseSchema' => $this->getResponseSchema()
            ]
        ];

        try {
            $response = Http::timeout(15)->post($url, $payload);

            if (!$response->successful()) {
                Log::error('Gemini API HTTP error', ['status' => $response->status(), 'body' => $response->body()]);
                return $this->fallbackResponse();
            }

            $data = $response->json();
            $jsonText = $data['candidates'][0]['content']['parts'][0]['text'] ?? '{}';
            
            $parsed = json_decode($jsonText, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::error('Gemini API JSON parse error', ['raw' => $jsonText]);
                return $this->fallbackResponse();
            }
            
            return $this->validateAndFormatResponse($parsed);
            
        } catch (\Throwable $e) {
            Log::error('Gemini API Exception', ['message' => $e->getMessage()]);
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
                        'arguments' => ['type' => 'OBJECT']
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
