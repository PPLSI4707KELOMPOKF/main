<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class VectorDatabaseService
{
    protected string $storagePath = 'vector_store.json';

    /**
     * Search for the most relevant documents given a query embedding (PBI-5).
     *
     * @param array $queryEmbedding
     * @param int $limit
     * @return array
     */
    public function search(array $queryEmbedding, int $limit = 3): array
    {
        $documents = $this->loadDatabase();
        
        if (empty($documents)) {
            Log::warning('[PBI-5] Vector database is empty.');
            return [];
        }

        $results = [];

        foreach ($documents as $doc) {
            if (!isset($doc['embedding']) || empty($doc['embedding'])) {
                continue;
            }

            $similarity = $this->cosineSimilarity($queryEmbedding, $doc['embedding']);
            
            // Only consider documents with positive similarity
            if ($similarity > 0.0) {
                $results[] = [
                    'id' => $doc['id'],
                    'content' => $doc['content'],
                    'metadata' => $doc['metadata'] ?? [],
                    'score' => $similarity
                ];
            }
        }

        // Sort by highest similarity score
        usort($results, function ($a, $b) {
            return $b['score'] <=> $a['score'];
        });

        // Return top K results
        $topResults = array_slice($results, 0, $limit);
        
        Log::info('[PBI-5] RAG Search completed', [
            'found_count' => count($topResults),
            'top_score' => !empty($topResults) ? $topResults[0]['score'] : null
        ]);

        return $topResults;
    }

    /**
     * Add a document with its embedding to the store.
     */
    public function addDocument(string $id, string $content, array $metadata, array $embedding): void
    {
        $documents = $this->loadDatabase();
        
        // Remove existing doc with same ID if any
        $documents = array_filter($documents, function ($doc) use ($id) {
            return $doc['id'] !== $id;
        });

        $documents[] = [
            'id' => $id,
            'content' => $content,
            'metadata' => $metadata,
            'embedding' => $embedding
        ];

        $this->saveDatabase(array_values($documents));
    }

    /**
     * Load the database from storage.
     */
    protected function loadDatabase(): array
    {
        if (!Storage::exists($this->storagePath)) {
            return [];
        }

        $content = Storage::get($this->storagePath);
        $data = json_decode($content, true);
        
        return is_array($data) ? $data : [];
    }

    /**
     * Save the database to storage.
     */
    protected function saveDatabase(array $documents): void
    {
        Storage::put($this->storagePath, json_encode($documents));
    }

    /**
     * Calculate cosine similarity between two vectors.
     */
    protected function cosineSimilarity(array $vec1, array $vec2): float
    {
        $dotProduct = 0.0;
        $normA = 0.0;
        $normB = 0.0;
        
        $count = min(count($vec1), count($vec2));

        if ($count === 0) return 0.0;

        for ($i = 0; $i < $count; $i++) {
            $dotProduct += $vec1[$i] * $vec2[$i];
            $normA += pow($vec1[$i], 2);
            $normB += pow($vec2[$i], 2);
        }

        if ($normA == 0.0 || $normB == 0.0) {
            return 0.0;
        }

        return $dotProduct / (sqrt($normA) * sqrt($normB));
    }
}
