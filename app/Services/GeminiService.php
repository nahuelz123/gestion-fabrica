<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Exception;

class GeminiService
{
    /**
     * Uses Gemini Structured Output / Function Calling to parse the intent and entities.
     */
    public function analyzeText(string $text): array
    {
        $apiKey = config('services.gemini.api_key');
        if (!$apiKey) {
            throw new Exception("No Gemini API key configured.");
        }

        // We use gemini-3-flash-preview as it is fast and supports JSON schema / Structured Output
        $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-3-flash-preview:generateContent?key={$apiKey}";

        $payload = [
            'contents' => [
                ['parts' => [['text' => $text]]]
            ],
            'systemInstruction' => [
                'parts' => [
                    ['text' => 'Sos el asistente virtual de un sistema de gestión de fábrica. Tu tarea es extraer la intención del usuario y las entidades (producto y cantidad). No inventes información. Si no detectás un producto, dejalo nulo. Las intenciones válidas son: check_stock (averiguar saldo), propose_stock_entry (ingresar nuevo stock), calculate_production (simular producción), unknown (charla u otra cosa).']
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
}
