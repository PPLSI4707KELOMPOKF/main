<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Services\AIProcessingService;
use App\Services\OllamaService;
use App\Services\ContextBuilderService;
use App\Models\ChatSession;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Mockery;

/**
 * Unit Tests — PBI-08: Pemrosesan oleh AI (Gemma 3 Local).
 *
 * Menguji AIProcessingService secara terisolasi:
 * - Context validation
 * - Context preprocessing
 * - Post-processing response
 * - Error handling & fallback
 * - Anti-duplicate detection
 * - Response formatting
 */
class AIProcessingServiceTest extends TestCase
{
    protected AIProcessingService $service;
    protected OllamaService $ollamaService;
    protected ContextBuilderService $contextBuilder;

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
        Config::set('ollama.system_prompt', 'Kamu adalah Lentra AI.');

        $this->ollamaService  = new OllamaService();
        $this->contextBuilder = new ContextBuilderService();
        $this->service        = new AIProcessingService($this->ollamaService, $this->contextBuilder);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // ─── CONTEXT VALIDATION ────────────────────────────────────────────

    /**
     * Test: Context kosong dinyatakan tidak valid.
     */
    public function test_validate_context_empty_returns_invalid(): void
    {
        $result = $this->service->validateContext('');
        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('kosong', $result['reason']);
    }

    /**
     * Test: Context hanya whitespace dinyatakan tidak valid.
     */
    public function test_validate_context_whitespace_only_returns_invalid(): void
    {
        $result = $this->service->validateContext('   ');
        $this->assertFalse($result['valid']);
    }

    /**
     * Test: Context terlalu pendek dinyatakan tidak valid.
     */
    public function test_validate_context_too_short_returns_invalid(): void
    {
        $result = $this->service->validateContext('abc');
        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('pendek', $result['reason']);
    }

    /**
     * Test: Context dengan konten bermakna dinyatakan valid.
     */
    public function test_validate_context_with_valid_content_returns_valid(): void
    {
        $context = 'Pasal 57 UU No. 22 Tahun 2009 tentang penggunaan helm SNI bagi pengendara sepeda motor.';
        $result = $this->service->validateContext($context);

        $this->assertTrue($result['valid']);
        $this->assertStringContainsString('valid', $result['reason']);
    }

    // ─── CONTEXT PREPROCESSING ─────────────────────────────────────────

    /**
     * Test: Preprocessing context kosong mengembalikan string kosong.
     */
    public function test_preprocess_empty_context_returns_empty_string(): void
    {
        $result = $this->service->preprocessContextForAI('');
        $this->assertEquals('', $result);
    }

    /**
     * Test: Preprocessing membersihkan whitespace berlebihan.
     */
    public function test_preprocess_cleans_excessive_whitespace(): void
    {
        $context = "Pasal 57    tentang   helm.\n\n\n\n\nPasal 68 tentang STNK.";
        $result = $this->service->preprocessContextForAI($context);

        // Multiple spaces should be reduced to single space
        $this->assertStringNotContainsString('    ', $result);
        // More than 2 newlines should be reduced to 2
        $this->assertFalse((bool) preg_match('/\n{3,}/', $result));
    }

    /**
     * Test: Preprocessing membatasi panjang context.
     */
    public function test_preprocess_truncates_long_context(): void
    {
        // Buat context yang sangat panjang
        $context = str_repeat('Pasal 57 mengatur tentang kewajiban helm. ', 200);
        $result = $this->service->preprocessContextForAI($context);

        $this->assertLessThanOrEqual(4001, mb_strlen($result)); // Allow 1 char margin
    }

    /**
     * Test: Preprocessing mempertahankan content normal.
     */
    public function test_preprocess_preserves_normal_content(): void
    {
        $context = "Pasal 57 UU LLAJ mengatur kewajiban helm.\n\nPasal 68 mengatur tentang STNK.";
        $result = $this->service->preprocessContextForAI($context);

        $this->assertStringContainsString('Pasal 57', $result);
        $this->assertStringContainsString('Pasal 68', $result);
    }

    // ─── POST-PROCESSING RESPONSE ──────────────────────────────────────

    /**
     * Test: Post-processing response sukses dari AI.
     */
    public function test_post_process_successful_response(): void
    {
        $aiResult = [
            'success' => true,
            'message' => 'Berdasarkan Pasal 57 UU LLAJ, helm wajib SNI.',
            'model'   => 'gemma3:4b',
            'pasal'   => 'Pasal 57',
            'sanksi'  => 'Denda paling banyak Rp 250.000',
        ];

        $result = $this->service->postProcessResponse($aiResult);

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('Pasal 57', $result['message']);
        $this->assertEquals('gemma3:4b', $result['model']);
        $this->assertEquals('Pasal 57', $result['pasal']);
    }

    /**
     * Test: Post-processing menghasilkan fallback jika AI gagal.
     */
    public function test_post_process_failed_response_returns_fallback(): void
    {
        $aiResult = [
            'success' => false,
            'message' => 'Connection refused',
            'model'   => 'gemma3:4b',
            'pasal'   => null,
            'sanksi'  => null,
        ];

        $result = $this->service->postProcessResponse($aiResult);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Lentra AI', $result['message']);
    }

    /**
     * Test: Post-processing menolak response AI yang terlalu pendek.
     */
    public function test_post_process_rejects_too_short_response(): void
    {
        $aiResult = [
            'success' => true,
            'message' => 'Ya.',
            'model'   => 'gemma3:4b',
            'pasal'   => null,
            'sanksi'  => null,
        ];

        $result = $this->service->postProcessResponse($aiResult);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Lentra AI', $result['message']);
    }

    /**
     * Test: Post-processing menghapus karakter kontrol dari response.
     */
    public function test_post_process_removes_control_characters(): void
    {
        $aiResult = [
            'success' => true,
            'message' => "Berdasarkan Pasal 57\x00\x01 UU LLAJ tentang helm yang wajib SNI.",
            'model'   => 'gemma3:4b',
            'pasal'   => 'Pasal 57',
            'sanksi'  => null,
        ];

        $result = $this->service->postProcessResponse($aiResult);

        $this->assertTrue($result['success']);
        $this->assertStringNotContainsString("\x00", $result['message']);
        $this->assertStringNotContainsString("\x01", $result['message']);
    }

    // ─── ANTI-DUPLICATE DETECTION ──────────────────────────────────────

    /**
     * Test: Post-processing menghapus paragraf duplikat.
     */
    public function test_post_process_removes_duplicate_paragraphs(): void
    {
        $aiResult = [
            'success' => true,
            'message' => "Pasal 57 mengatur tentang helm yang wajib SNI.\n\nPasal 57 mengatur tentang helm yang wajib SNI.\n\nInformasi tambahan tentang regulasi lalu lintas.",
            'model'   => 'gemma3:4b',
            'pasal'   => 'Pasal 57',
            'sanksi'  => null,
        ];

        $result = $this->service->postProcessResponse($aiResult);

        $this->assertTrue($result['success']);
        $this->assertEquals(1, substr_count($result['message'], 'Pasal 57 mengatur tentang helm'));
        $this->assertStringContainsString('Informasi tambahan', $result['message']);
    }

    /**
     * Test: Post-processing menghapus kalimat duplikat.
     */
    public function test_post_process_removes_duplicate_sentences(): void
    {
        $aiResult = [
            'success' => true,
            'message' => 'Helm wajib SNI. Denda Rp 250.000. Helm wajib SNI.',
            'model'   => 'gemma3:4b',
            'pasal'   => null,
            'sanksi'  => null,
        ];

        $result = $this->service->postProcessResponse($aiResult);

        $this->assertTrue($result['success']);
        $this->assertEquals(1, substr_count(mb_strtolower($result['message']), 'helm wajib sni.'));
    }

    // ─── ERROR HANDLING & FALLBACK ─────────────────────────────────────

    /**
     * Test: handleProcessingError mengembalikan response aman.
     */
    public function test_handle_processing_error_returns_safe_response(): void
    {
        $session = Mockery::mock(ChatSession::class)->makePartial();
        $session->shouldReceive('getAttribute')->with('session_id')->andReturn('test-session-123');
        $session->shouldReceive('setAttribute')->andReturnNull();

        $exception = new \RuntimeException('Connection timeout');

        $result = $this->service->handleProcessingError($exception, $session, 5000);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Lentra AI', $result['message']);
        $this->assertEquals('fallback', $result['model']);
        $this->assertNull($result['pasal']);
        $this->assertNull($result['sanksi']);
        $this->assertTrue($result['processing_info']['had_error']);
    }

    /**
     * Test: getSafeFallbackResponse mengembalikan format yang benar.
     */
    public function test_safe_fallback_response_format(): void
    {
        $result = $this->service->getSafeFallbackResponse('Test error');

        $this->assertArrayHasKey('success', $result);
        $this->assertArrayHasKey('message', $result);
        $this->assertArrayHasKey('model', $result);
        $this->assertArrayHasKey('pasal', $result);
        $this->assertArrayHasKey('sanksi', $result);

        $this->assertFalse($result['success']);
        $this->assertEquals('fallback', $result['model']);
        $this->assertNull($result['pasal']);
        $this->assertNull($result['sanksi']);
    }

    // ─── RESPONSE FORMATTING ───────────────────────────────────────────

    /**
     * Test: formatResponse menambahkan processing_info.
     */
    public function test_format_response_adds_processing_info(): void
    {
        $processedResult = [
            'success' => true,
            'message' => 'Jawaban AI yang valid tentang lalu lintas.',
            'model'   => 'gemma3:4b',
            'pasal'   => null,
            'sanksi'  => null,
        ];

        $result = $this->service->formatResponse($processedResult, 1500);

        $this->assertArrayHasKey('processing_info', $result);
        $this->assertEquals(1500, $result['processing_info']['processing_time_ms']);
        $this->assertFalse($result['processing_info']['had_error']);
        $this->assertEquals('gemma3:4b', $result['processing_info']['model_used']);
    }

    /**
     * Test: formatResponse menandai had_error=true saat success=false.
     */
    public function test_format_response_marks_error_correctly(): void
    {
        $processedResult = [
            'success' => false,
            'message' => 'AI tidak tersedia.',
            'model'   => 'fallback',
            'pasal'   => null,
            'sanksi'  => null,
        ];

        $result = $this->service->formatResponse($processedResult, 0);

        $this->assertTrue($result['processing_info']['had_error']);
    }

    // ─── FULL PIPELINE (processContext) ────────────────────────────────

    /**
     * Test: Full pipeline processContext berhasil dengan context valid.
     */
    public function test_process_context_success_with_valid_context(): void
    {
        Http::fake([
            '127.0.0.1:11434/api/chat' => Http::response([
                'message' => [
                    'role'    => 'assistant',
                    'content' => 'Berdasarkan Pasal 57 UU LLAJ, helm wajib SNI untuk pengendara motor.',
                ],
            ], 200),
        ]);

        $session = Mockery::mock(ChatSession::class)->makePartial();
        $session->shouldReceive('getAttribute')->with('session_id')->andReturn('test-uuid');
        $session->shouldReceive('setAttribute')->andReturnNull();
        $session->shouldReceive('messages->reorder->limit->get->reverse->map->values->toArray')
            ->andReturn([]);

        $context = 'Pasal 57 UU No. 22 Tahun 2009: Setiap pengendara sepeda motor wajib menggunakan helm berstandar SNI.';

        $result = $this->service->processContext('apa aturan helm?', $context, $session);

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('Pasal 57', $result['message']);
        $this->assertArrayHasKey('processing_info', $result);
        $this->assertFalse($result['processing_info']['had_error']);
    }

    /**
     * Test: processContext dengan context kosong tetap berhasil (tanpa RAG context).
     */
    public function test_process_context_with_empty_context_still_processes(): void
    {
        Http::fake([
            '127.0.0.1:11434/api/chat' => Http::response([
                'message' => [
                    'role'    => 'assistant',
                    'content' => 'Berdasarkan UU LLAJ, helm wajib dipakai saat mengendarai motor.',
                ],
            ], 200),
        ]);

        $session = Mockery::mock(ChatSession::class)->makePartial();
        $session->shouldReceive('getAttribute')->with('session_id')->andReturn('test-uuid-2');
        $session->shouldReceive('setAttribute')->andReturnNull();
        $session->shouldReceive('messages->reorder->limit->get->reverse->map->values->toArray')
            ->andReturn([]);

        $result = $this->service->processContext('apa aturan helm?', '', $session);

        // Harus tetap berhasil meskipun tanpa context RAG
        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('processing_info', $result);
    }

    /**
     * Test: processContext mengembalikan fallback saat AI gagal total.
     */
    public function test_process_context_returns_fallback_when_ai_fails(): void
    {
        Http::fake([
            '127.0.0.1:11434/*' => Http::response('Service Unavailable', 503),
        ]);

        $session = Mockery::mock(ChatSession::class)->makePartial();
        $session->shouldReceive('getAttribute')->with('session_id')->andReturn('test-uuid-3');
        $session->shouldReceive('setAttribute')->andReturnNull();
        $session->shouldReceive('messages->reorder->limit->get->reverse->map->values->toArray')
            ->andReturn([]);

        $context = 'Pasal 57 tentang helm SNI wajib dipakai.';

        $result = $this->service->processContext('apa aturan helm?', $context, $session);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Lentra AI', $result['message']);
        $this->assertArrayHasKey('processing_info', $result);
    }

    /**
     * Test: Response dari processContext selalu memiliki field wajib.
     */
    public function test_process_context_response_has_required_fields(): void
    {
        Http::fake([
            '127.0.0.1:11434/api/chat' => Http::response([
                'message' => [
                    'role'    => 'assistant',
                    'content' => 'Jawaban tentang hukum lalu lintas yang cukup panjang untuk dianggap valid.',
                ],
            ], 200),
        ]);

        $session = Mockery::mock(ChatSession::class)->makePartial();
        $session->shouldReceive('getAttribute')->with('session_id')->andReturn('test-uuid-4');
        $session->shouldReceive('setAttribute')->andReturnNull();
        $session->shouldReceive('messages->reorder->limit->get->reverse->map->values->toArray')
            ->andReturn([]);

        $result = $this->service->processContext(
            'pertanyaan test',
            'Context referensi hukum yang valid.',
            $session
        );

        // Semua field wajib harus ada
        $this->assertArrayHasKey('success', $result);
        $this->assertArrayHasKey('message', $result);
        $this->assertArrayHasKey('model', $result);
        $this->assertArrayHasKey('pasal', $result);
        $this->assertArrayHasKey('sanksi', $result);
        $this->assertArrayHasKey('processing_info', $result);
        $this->assertIsBool($result['success']);
        $this->assertIsString($result['message']);
        $this->assertIsString($result['model']);
    }
}
