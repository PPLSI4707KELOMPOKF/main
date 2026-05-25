<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Services\AIProcessingService;
use App\Services\OllamaService;
use App\Services\ContextBuilderService;
use App\Models\ChatSession;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Config;
use Mockery;

/**
 * Feature Tests — PBI-08: Pemrosesan oleh AI (Gemma 3 Local).
 *
 * Menguji integrasi end-to-end AIProcessingService
 * dengan OllamaService dan ContextBuilderService.
 */
class AIProcessingFeatureTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('ollama.base_url', 'http://127.0.0.1:11434');
        Config::set('ollama.model', 'gemma3:4b');
        Config::set('ollama.fallback_model', 'mistral');
        Config::set('ollama.timeout', 120);
        Config::set('ollama.retry_attempts', 2);
        Config::set('ollama.retry_delay', 0);
        Config::set('ollama.temperature', 0.2);
        Config::set('ollama.top_p', 0.7);
        Config::set('ollama.repeat_penalty', 1.5);
        Config::set('ollama.num_predict', 350);
        Config::set('ollama.system_prompt', 'Kamu adalah Lentra AI, asisten hukum lalu lintas Indonesia.');
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Helper: Buat mock ChatSession.
     */
    private function createMockSession(string $sessionId = 'feature-test-session'): ChatSession
    {
        $session = Mockery::mock(ChatSession::class)->makePartial();
        $session->shouldReceive('getAttribute')->with('session_id')->andReturn($sessionId);
        $session->shouldReceive('setAttribute')->andReturnNull();
        $session->shouldReceive('messages->reorder->limit->get->reverse->map->values->toArray')
            ->andReturn([]);

        return $session;
    }

    /**
     * Test: Full pipeline dengan context RAG valid → response AI berhasil.
     */
    public function test_full_pipeline_with_rag_context_returns_ai_response(): void
    {
        Http::fake([
            '127.0.0.1:11434/api/chat' => Http::response([
                'message' => [
                    'role'    => 'assistant',
                    'content' => 'Berdasarkan Pasal 57 ayat (1) UU No. 22 Tahun 2009, setiap pengendara sepeda motor wajib menggunakan helm SNI. Denda paling banyak Rp 250.000.',
                ],
            ], 200),
        ]);

        $service = new AIProcessingService(new OllamaService(), new ContextBuilderService());
        $session = $this->createMockSession();

        $ragContext = "--- DOKUMEN REFERENSI HUKUM ---\n"
            . "[Dokumen 1] Pasal 57 ayat (1) — Helm (skor: 0.9512)\n"
            . "Setiap pengendara sepeda motor wajib mengenakan helm yang memenuhi SNI.\n"
            . "--- AKHIR DOKUMEN REFERENSI ---";

        $result = $service->processContext('apa aturan helm?', $ragContext, $session);

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('Pasal 57', $result['message']);
        $this->assertArrayHasKey('processing_info', $result);
        $this->assertGreaterThanOrEqual(0, $result['processing_info']['processing_time_ms']);
    }

    /**
     * Test: Pipeline dengan context kosong tetap berjalan (tanpa error).
     */
    public function test_pipeline_with_empty_context_handles_gracefully(): void
    {
        Http::fake([
            '127.0.0.1:11434/api/chat' => Http::response([
                'message' => [
                    'role'    => 'assistant',
                    'content' => 'Secara umum, UU No. 22 Tahun 2009 mengatur aturan lalu lintas di Indonesia.',
                ],
            ], 200),
        ]);

        $service = new AIProcessingService(new OllamaService(), new ContextBuilderService());
        $session = $this->createMockSession();

        $result = $service->processContext('apa itu UU lalu lintas?', '', $session);

        // Harus tetap berhasil — AI menjawab tanpa context RAG
        $this->assertTrue($result['success']);
        $this->assertNotEmpty($result['message']);
    }

    /**
     * Test: Pipeline dengan AI gagal → fallback response aman.
     */
    public function test_pipeline_with_ai_failure_returns_safe_fallback(): void
    {
        Http::fake([
            '127.0.0.1:11434/*' => Http::response('Internal Server Error', 500),
        ]);

        $service = new AIProcessingService(new OllamaService(), new ContextBuilderService());
        $session = $this->createMockSession();

        $result = $service->processContext(
            'apa aturan helm?',
            'Pasal 57 tentang kewajiban helm SNI.',
            $session
        );

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Lentra AI', $result['message']);
        $this->assertNull($result['pasal']);
        $this->assertNull($result['sanksi']);
    }

    /**
     * Test: Response dari pipeline selalu memenuhi format standar.
     */
    public function test_pipeline_response_always_has_standard_format(): void
    {
        Http::fake([
            '127.0.0.1:11434/api/chat' => Http::response([
                'message' => [
                    'role'    => 'assistant',
                    'content' => 'Jawaban dari Gemma3 tentang hukum lalu lintas Indonesia yang cukup lengkap.',
                ],
            ], 200),
        ]);

        $service = new AIProcessingService(new OllamaService(), new ContextBuilderService());
        $session = $this->createMockSession();

        $result = $service->processContext(
            'test pertanyaan',
            'Context referensi hukum lalu lintas.',
            $session
        );

        // Field wajib
        $this->assertArrayHasKey('success', $result);
        $this->assertArrayHasKey('message', $result);
        $this->assertArrayHasKey('model', $result);
        $this->assertArrayHasKey('pasal', $result);
        $this->assertArrayHasKey('sanksi', $result);
        $this->assertArrayHasKey('processing_info', $result);

        // Processing info
        $this->assertArrayHasKey('processing_time_ms', $result['processing_info']);
        $this->assertArrayHasKey('had_error', $result['processing_info']);
        $this->assertArrayHasKey('model_used', $result['processing_info']);
    }

    /**
     * Test: Pipeline dengan fallback model (primary gagal, secondary berhasil).
     */
    public function test_pipeline_uses_fallback_model_when_primary_fails(): void
    {
        $callCount = 0;

        Http::fake(function ($request) use (&$callCount) {
            $callCount++;
            $body = json_decode($request->body(), true);
            $model = $body['model'] ?? '';

            if ($model === 'gemma3:4b') {
                return Http::response('Model Error', 500);
            }

            return Http::response([
                'message' => [
                    'role'    => 'assistant',
                    'content' => 'Jawaban dari model fallback tentang peraturan lalu lintas Indonesia.',
                ],
            ], 200);
        });

        $service = new AIProcessingService(new OllamaService(), new ContextBuilderService());
        $session = $this->createMockSession();

        $result = $service->processContext(
            'apa aturan helm?',
            'Pasal 57 tentang helm SNI yang wajib digunakan.',
            $session
        );

        $this->assertTrue($result['success']);
        $this->assertEquals('mistral', $result['model']);
    }

    /**
     * Test: Pipeline menghapus duplikasi di response AI.
     */
    public function test_pipeline_removes_duplicate_content_from_response(): void
    {
        Http::fake([
            '127.0.0.1:11434/api/chat' => Http::response([
                'message' => [
                    'role'    => 'assistant',
                    'content' => "Pasal 57 mewajibkan helm SNI.\n\nPasal 57 mewajibkan helm SNI.\n\nDenda pelanggaran Rp 250.000.",
                ],
            ], 200),
        ]);

        $service = new AIProcessingService(new OllamaService(), new ContextBuilderService());
        $session = $this->createMockSession();

        $result = $service->processContext(
            'apa aturan helm?',
            'Pasal 57 tentang helm SNI.',
            $session
        );

        $this->assertTrue($result['success']);
        // Paragraf duplikat harus dihapus — hanya muncul sekali
        $this->assertEquals(1, substr_count($result['message'], 'Pasal 57 mewajibkan helm SNI.'));
        $this->assertStringContainsString('Denda pelanggaran', $result['message']);
    }
}
