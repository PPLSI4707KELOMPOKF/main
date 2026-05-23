<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\ChatSession;
use App\Services\EmbeddingService;
use App\Services\VectorDatabaseService;
use App\Services\ContextBuilderService;
use App\Services\OllamaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Mockery\MockInterface;

class ContextBuildingFeatureTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test that sending a chat message triggers context building via ContextBuilderService.
     */
    public function test_send_message_builds_context_with_documents(): void
    {
        // 1. Create a chat session with valid UUID
        $uuid = '55555555-5555-5555-5555-555555555555';
        $session = ChatSession::create([
            'session_id' => $uuid,
            'title' => 'Percakapan Baru',
        ]);

        // 2. Mock EmbeddingService
        $fakeEmbedding = [0.1, 0.2, 0.3];
        $this->mock(EmbeddingService::class, function (MockInterface $mock) use ($fakeEmbedding) {
            $mock->shouldReceive('generateEmbedding')
                ->once()
                ->andReturn($fakeEmbedding);
        });

        // 3. Mock VectorDatabaseService
        $fakeRelevantDocs = [
            [
                'id' => 'pasal-57',
                'content' => 'Wajib menggunakan helm SNI.',
                'metadata' => ['pasal' => 'Pasal 57', 'topik' => 'Helm'],
                'score' => 0.95,
            ],
        ];
        $this->mock(VectorDatabaseService::class, function (MockInterface $mock) use ($fakeEmbedding, $fakeRelevantDocs) {
            $mock->shouldReceive('search')
                ->once()
                ->andReturn($fakeRelevantDocs);
        });

        // 4. Mock ContextBuilderService to verify it gets called with the right arguments
        $this->mock(ContextBuilderService::class, function (MockInterface $mock) use ($fakeRelevantDocs) {
            $mock->shouldReceive('build')
                ->once()
                ->with(
                    \Mockery::type('string'),           // cleaned message
                    $fakeRelevantDocs,                   // relevant docs from PBI-6
                    \Mockery::type(ChatSession::class)   // session object
                )
                ->andReturn('Mocked context prompt for AI');
            $mock->shouldReceive('getConversationHistory')
                ->once()
                ->andReturn([]);
        });

        // 5. Mock OllamaService so we don't connect to a real Ollama instance
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

        // 6. Mock Log calls from ChatController
        Log::shouldReceive('info')
            ->with('[PBI-2] User question received', \Mockery::any());

        Log::shouldReceive('info')
            ->with('[PBI-4] Successfully generated embedding in ChatController', \Mockery::any());

        Log::shouldReceive('info')
            ->with('[PBI-6] Retrieved relevant documents', \Mockery::any());

        Log::shouldReceive('info')
            ->with('[OllamaService] Final response', \Mockery::any());

        // 7. Make request
        $response = $this->postJson(route('chat.send'), [
            'session_id' => $uuid,
            'message' => 'apakah harus pakai helm?',
        ]);

        // 8. Assertions
        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
    }

    /**
     * Test that context building works even when no documents are found.
     */
    public function test_send_message_builds_context_without_documents(): void
    {
        $uuid = '66666666-6666-6666-6666-666666666666';
        ChatSession::create([
            'session_id' => $uuid,
            'title' => 'Percakapan Baru',
        ]);

        // Mock EmbeddingService returns null (embedding failed)
        $this->mock(EmbeddingService::class, function (MockInterface $mock) {
            $mock->shouldReceive('generateEmbedding')
                ->once()
                ->andReturn(null);
        });

        // ContextBuilderService should be called with empty docs array
        $this->mock(ContextBuilderService::class, function (MockInterface $mock) {
            $mock->shouldReceive('build')
                ->once()
                ->with(
                    \Mockery::type('string'),
                    [],                                    // empty docs since embedding failed
                    \Mockery::type(ChatSession::class)
                )
                ->andReturn('Mocked context without docs');
            $mock->shouldReceive('getConversationHistory')
                ->once()
                ->andReturn([]);
        });

        // Mock OllamaService
        $this->mock(OllamaService::class, function (MockInterface $mock) {
            $mock->shouldReceive('chat')
                ->once()
                ->andReturn([
                    'success' => true,
                    'message' => 'Jawaban AI tanpa dokumen.',
                    'model'   => 'mistral',
                    'pasal'   => null,
                    'sanksi'  => null,
                ]);
        });

        Log::shouldReceive('info')
            ->with('[PBI-2] User question received', \Mockery::any());

        Log::shouldReceive('info')
            ->with('[OllamaService] Final response', \Mockery::any());

        $response = $this->postJson(route('chat.send'), [
            'session_id' => $uuid,
            'message' => 'pertanyaan umum tentang lalu lintas',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
    }
}
