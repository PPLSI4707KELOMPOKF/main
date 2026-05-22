<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\ChatSession;
use App\Services\EmbeddingService;
use App\Services\VectorDatabaseService;
use App\Services\ContextBuilderService;
use App\Services\OllamaService;
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
                ->with($fakeEmbedding, $topKLimit)
                ->andReturn($fakeRelevantDocs);
        });

        // 5. Mock ContextBuilderService (PBI-7)
        $this->mock(ContextBuilderService::class, function (MockInterface $mock) {
            $mock->shouldReceive('build')
                ->once()
                ->andReturn('Mocked context');
            $mock->shouldReceive('getConversationHistory')
                ->once()
                ->andReturn([]);
        });

        // 6. Mock OllamaService
        $this->mock(OllamaService::class, function (MockInterface $mock) {
            $mock->shouldReceive('chat')
                ->once()
                ->andReturn([
                    'success' => true,
                    'message' => 'Jawaban AI tentang helm.',
                    'model'   => 'mistral',
                    'pasal'   => 'Pasal 57',
                    'sanksi'  => null,
                ]);
        });

        // 7. Mock Log
        Log::shouldReceive('info')
            ->with('[PBI-2] User question received', \Mockery::any());

        Log::shouldReceive('info')
            ->with('[PBI-4] Successfully generated embedding in ChatController', \Mockery::any());

        Log::shouldReceive('info')
            ->once()
            ->with('[PBI-6] Retrieved relevant documents', \Mockery::on(function ($data) use ($topKLimit) {
                return isset($data['top_k']) && $data['top_k'] === $topKLimit && $data['count'] === 1;
            }));

        Log::shouldReceive('info')
            ->with('[OllamaService] Final response', \Mockery::any());

        // 8. Make request
        $response = $this->postJson(route('chat.send'), [
            'session_id' => $uuid,
            'message' => 'apakah saya harus pakai helm?'
        ]);

        // 9. Assertions
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
