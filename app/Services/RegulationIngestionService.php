<?php

namespace App\Services;

use App\Models\RegulationChunk;
use App\Models\RegulationDocument;

class RegulationIngestionService
{
    public function __construct(
        protected DocumentChunkingService $chunkingService,
        protected EmbeddingService $embeddingService,
        protected VectorDatabaseService $vectorDatabase
    ) {
    }

    public function ingest(RegulationDocument $document): int
    {
        $this->removeDocumentVectors($document);
        $document->chunks()->delete();

        $chunks = $this->chunkingService->chunk($document->content);

        foreach ($chunks as $index => $content) {
            $chunkUid = sprintf('regdoc-%s-chunk-%s', $document->id, $index + 1);
            $metadata = [
                'document_id' => $document->id,
                'chunk_uid' => $chunkUid,
                'chunk_index' => $index + 1,
                'title' => $document->title,
                'source' => $document->source,
                'pasal' => $document->pasal,
                'topic' => $document->topic,
                'topik' => $document->topic,
            ];

            $embedding = $this->embeddingService->generateEmbedding($content);

            $chunk = RegulationChunk::create([
                'regulation_document_id' => $document->id,
                'chunk_uid' => $chunkUid,
                'chunk_index' => $index + 1,
                'content' => $content,
                'metadata' => $metadata,
                'embedding_status' => $embedding ? 'embedded' : 'failed',
                'embedded_at' => $embedding ? now() : null,
            ]);

            if ($embedding) {
                $this->vectorDatabase->addDocument(
                    $chunk->chunk_uid,
                    $chunk->content,
                    $metadata,
                    $embedding
                );
            }
        }

        return count($chunks);
    }

    public function removeDocumentVectors(RegulationDocument $document): void
    {
        $this->vectorDatabase->deleteByDocumentId($document->id);
    }
}
