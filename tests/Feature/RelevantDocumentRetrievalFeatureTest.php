<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\ChatSession;
use App\Services\EmbeddingService;
use App\Services\VectorDatabaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Mockery\MockInterface;

class RelevantDocumentRetrievalFeatureTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test that sending a chat message invokes the vector search with Top-K config.
     */
    public function test_send_message_retrieves_top_k_documents(): void
    {
        // 1. Set config value for RAG_TOP_K
        $topKLimit = 4;
        Config::set('rag.top_k', $topKLimit);

        // 2. Create a chat session
        $uuid = '123e4567-e89b-12d3-a456-426614174000';
        $session = ChatSession::create([
            'session_id' => $uuid,
            'title' => 'Percakapan Baru'
        ]);

        // 3. Mock EmbeddingService
        $fakeEmbedding = [0.1, 0.2, 0.3];
        $this->mock(EmbeddingService::class, function (MockInterface $mock) use ($fakeEmbedding) {
            $mock->shouldReceive('generateEmbedding')
                ->once()
                ->with('apakah saya harus pakai helm?')
                ->andReturn($fakeEmbedding);
        });

        // 4. Mock VectorDatabaseService
        $fakeRelevantDocs = [
            [
                'id' => 'pasal-57',
                'content' => 'Setiap pengendara wajib menggunakan helm...',
                'metadata' => ['pasal' => 'Pasal 57'],
                'score' => 0.95
            ]
        ];
        $this->mock(VectorDatabaseService::class, function (MockInterface $mock) use ($fakeEmbedding, $topKLimit, $fakeRelevantDocs) {
            $mock->shouldReceive('search')
                ->once()
                ->with($fakeEmbedding, $topKLimit) // Verifies $topK is passed correctly
                ->andReturn($fakeRelevantDocs);
        });

        // 5. Mock Log to capture the correct log entry
        Log::shouldReceive('info')
            ->with('[PBI-2] User question received', \Mockery::any());
        
        Log::shouldReceive('info')
            ->with('[PBI-4] Successfully generated embedding in ChatController', \Mockery::any());

        Log::shouldReceive('info')
            ->once()
            ->with('[PBI-6] Retrieved relevant documents', \Mockery::on(function ($data) use ($topKLimit) {
                return isset($data['top_k']) && $data['top_k'] === $topKLimit && $data['count'] === 1;
            }));

        // 6. Make request to send message
        $response = $this->postJson(route('chat.send'), [
            'session_id' => $uuid,
            'message' => 'apakah saya harus pakai helm?'
        ]);


        // 7. Assertions
        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonStructure([
            'success',
            'user_message',
            'assistant_message',
            'session_title'
        ]);
    }
}
