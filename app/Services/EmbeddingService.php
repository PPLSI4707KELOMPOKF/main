<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class EmbeddingService
{
    /**
     * Generate embedding for a given text using Ollama (PBI-4).
     *
     * @param string $text
     * @return array|null
     */
    public function generateEmbedding(string $text): ?array
    {
        try {
            $ollamaUrl = env('OLLAMA_URL', 'http://localhost:11434');
            $model = env('OLLAMA_EMBEDDING_MODEL', 'nomic-embed-text');

            $response = Http::timeout(30)->post("{$ollamaUrl}/api/embeddings", [
                'model' => $model,
                'prompt' => $text,
            ]);

            if ($response->successful()) {
                $data = $response->json();
                
                if (isset($data['embedding'])) {
                    Log::info('[PBI-4] Embedding generated successfully', [
                        'model' => $model,
                        'vector_size' => count($data['embedding']),
                        'text_length' => mb_strlen($text)
                    ]);
                    
                    return $data['embedding'];
                }
            }

            Log::error('[PBI-4] Failed to generate embedding: Invalid response from Ollama', [
                'status' => $response->status(),
                'body' => $response->body()
            ]);
            
            return null;

        } catch (\Exception $e) {
            Log::error('[PBI-4] Exception while generating embedding', [
                'message' => $e->getMessage()
            ]);
            
            return null;
        }
    }
}
