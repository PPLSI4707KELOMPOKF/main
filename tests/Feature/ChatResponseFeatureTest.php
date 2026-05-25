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

class ChatResponseFeatureTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        \Mockery::close();
        parent::tearDown();
    }

    /**
     * Test successful chat API response structure under PBI-10.
     */
    public function test_send_message_endpoint_returns_consistent_success_json(): void
    {
        $uuid = '22222222-2222-2222-2222-222222222222';
        $session = ChatSession::create([
            'session_id' => $uuid,
            'title' => 'Percakapan PBI-10',
        ]);

        // Mock dependencies to avoid actual external connections
        $this->mock(EmbeddingService::class, function (MockInterface $mock) {
            $mock->shouldReceive('generateEmbedding')->andReturn(null);
        });

        $this->mock(ContextBuilderService::class, function (MockInterface $mock) {
            $mock->shouldReceive('build')->andReturn('Context');
            $mock->shouldReceive('getConversationHistory')->andReturn([]);
        });

        $this->mock(OllamaService::class, function (MockInterface $mock) {
            $mock->shouldReceive('chat')->andReturn([
                'success' => true,
                'message' => 'Jawaban terformat AI.',
                'model'   => 'gemma3',
            ]);
        });

        // Trigger request
        $response = $this->postJson(route('chat.send'), [
            'session_id' => $uuid,
            'message' => 'apakah harus pakai helm?',
        ]);

        $response->assertStatus(200);

        // Verify JSON response conforms to PBI-10 and preserves backward compatibility
        $response->assertJsonStructure([
            'success',
            'message',
            'model',
            'timestamp',
            'user_message' => ['id', 'role', 'content', 'time'],
            'assistant_message' => ['id', 'role', 'content', 'pasal', 'sanksi', 'time'],
            'session_title',
        ]);

        $response->assertJsonPath('success', true);
        $response->assertJsonPath('message', 'Jawaban terformat AI.');
        $response->assertJsonPath('model', 'gemma3');
        $this->assertNotEmpty($response->json('timestamp'));
    }

    /**
     * Test chat API response structure when an exception occurs (PBI-10 error format).
     */
    public function test_send_message_endpoint_returns_consistent_error_json_on_exception(): void
    {
        $uuid = '33333333-3333-3333-3333-333333333333';
        $session = ChatSession::create([
            'session_id' => $uuid,
            'title' => 'Percakapan Gagal PBI-10',
        ]);

        // Force an exception in EmbeddingService to trigger the catch block in ChatController
        $this->mock(EmbeddingService::class, function (MockInterface $mock) {
            $mock->shouldReceive('generateEmbedding')
                ->andThrow(new \RuntimeException('Koneksi database gagal'));
        });

        // Trigger request
        $response = $this->postJson(route('chat.send'), [
            'session_id' => $uuid,
            'message' => 'apakah harus pakai helm?',
        ]);

        $response->assertStatus(500);

        // Verify error response format
        $response->assertExactJson([
            'success' => false,
            'message' => 'AI sedang tidak tersedia',
            'error'   => 'Koneksi database gagal',
        ]);
    }
}
