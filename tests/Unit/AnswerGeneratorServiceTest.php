<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Services\AnswerGeneratorService;
use App\Services\OllamaService;
use App\Services\ContextBuilderService;
use App\Models\ChatSession;
use Illuminate\Support\Facades\Log;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;

class AnswerGeneratorServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        \Mockery::close();
        parent::tearDown();
    }

    /**
     * Test preprocessContext cleans whitespace and respects max length.
     */
    public function test_preprocess_context_cleans_and_truncates(): void
    {
        $ollamaServiceMock = $this->createMock(OllamaService::class);
        $contextBuilderMock = $this->createMock(ContextBuilderService::class);
        $service = new AnswerGeneratorService($ollamaServiceMock, $contextBuilderMock);

        // Test whitespace cleaning
        $input = "Line 1\n\n\n\nLine 2\t  \tLine 3";
        $expected = "Line 1\n\nLine 2 Line 3";
        $this->assertEquals($expected, $service->preprocessContext($input));

        // Test empty context
        $this->assertEquals('', $service->preprocessContext('   '));

        // Test truncation (exceeding 4000 chars)
        $longString = str_repeat("Ini adalah sebuah kalimat tentang hukum lalu lintas di Indonesia. ", 100); // ~6600 chars
        $processed = $service->preprocessContext($longString);
        $this->assertLessThanOrEqual(4000, mb_strlen($processed));
        $this->assertStringEndsWith('.', $processed); // Cut at last period
    }

    /**
     * Test isValidAnswer checks minimum length.
     */
    public function test_is_valid_answer(): void
    {
        $ollamaServiceMock = $this->createMock(OllamaService::class);
        $contextBuilderMock = $this->createMock(ContextBuilderService::class);
        $service = new AnswerGeneratorService($ollamaServiceMock, $contextBuilderMock);

        $this->assertFalse($service->isValidAnswer(''));
        $this->assertFalse($service->isValidAnswer('   '));
        $this->assertFalse($service->isValidAnswer('Pendek')); // 6 chars
        $this->assertTrue($service->isValidAnswer('Jawaban yang cukup panjang.')); // 27 chars
    }

    /**
     * Test cleanAnswer removes control characters and duplicate paragraphs/sentences.
     */
    public function test_clean_answer_removes_duplicates_and_control_chars(): void
    {
        $ollamaServiceMock = $this->createMock(OllamaService::class);
        $contextBuilderMock = $this->createMock(ContextBuilderService::class);
        $service = new AnswerGeneratorService($ollamaServiceMock, $contextBuilderMock);

        // Control characters removal
        $inputControl = "Hello\x00World\x07!";
        $this->assertEquals('HelloWorld!', $service->cleanAnswer($inputControl));

        // Duplicate paragraphs removal
        $inputDup = "Ini adalah paragraf satu.\n\nIni adalah paragraf satu.\n\nIni adalah paragraf dua.";
        $expectedDup = "Ini adalah paragraf satu.\n\nIni adalah paragraf dua.";
        $this->assertEquals($expectedDup, $service->cleanAnswer($inputDup));

        // Similar paragraph detection (>80% similarity)
        $inputSimilar = "Pengendara sepeda motor wajib menggunakan helm SNI di jalan raya.\n\nPengendara sepeda motor wajib pakai helm SNI di jalan raya.";
        $this->assertEquals("Pengendara sepeda motor wajib menggunakan helm SNI di jalan raya.", $service->cleanAnswer($inputSimilar));

        // Sentence level duplicates
        $inputSentenceDup = "Wajib pakai helm. Wajib pakai helm. Rambu dilarang parkir.";
        $this->assertEquals("Wajib pakai helm. Rambu dilarang parkir.", $service->cleanAnswer($inputSentenceDup));
    }

    /**
     * Test extractLegalReferences correctly parses pasal, sanksi, and undang-undang.
     */
    public function test_extract_legal_references(): void
    {
        $ollamaServiceMock = $this->createMock(OllamaService::class);
        $contextBuilderMock = $this->createMock(ContextBuilderService::class);
        $service = new AnswerGeneratorService($ollamaServiceMock, $contextBuilderMock);

        $answer = "Berdasarkan Pasal 57 ayat (1) UU No. 22 Tahun 2009 tentang Lalu Lintas, setiap pengendara wajib menggunakan helm SNI. Jika melanggar, dapat dikenakan denda paling banyak Rp 250.000.";
        $references = $service->extractLegalReferences($answer);

        $this->assertEquals('Pasal 57 ayat (1)', $references['pasal']);
        $this->assertEquals('Denda paling banyak Rp 250.000.', $references['sanksi']);
        $this->assertEquals('UU No. 22 Tahun 2009', $references['undang_undang']);

        // Test with different format
        $answer2 = "Pelanggar pasal 281 UU 22 Tahun 2009 akan dikenakan pidana penjara paling lama 4 bulan.";
        $references2 = $service->extractLegalReferences($answer2);
        $this->assertEquals('Pasal 281', $references2['pasal']);
        $this->assertEquals('Pidana penjara paling lama 4 bulan.', $references2['sanksi']);
        $this->assertEquals('UU No. 22 Tahun 2009', $references2['undang_undang']);
    }

    /**
     * Test buildFallbackAnswer returns the correct structure.
     */
    public function test_build_fallback_answer_returns_correct_structure(): void
    {
        $ollamaServiceMock = $this->createMock(OllamaService::class);
        $contextBuilderMock = $this->createMock(ContextBuilderService::class);
        $service = new AnswerGeneratorService($ollamaServiceMock, $contextBuilderMock);

        $fallback = $service->buildFallbackAnswer(150);

        $this->assertFalse($fallback['success']);
        $this->assertStringContainsString('Mohon maaf, Lentra AI sedang tidak dapat menghasilkan jawaban', $fallback['answer']);
        $this->assertEquals('fallback', $fallback['model']);
        $this->assertNull($fallback['references']['pasal']);
        $this->assertNull($fallback['references']['sanksi']);
        $this->assertNull($fallback['references']['undang_undang']);
        $this->assertEquals(150, $fallback['metadata']['generation_time_ms']);
        $this->assertTrue($fallback['metadata']['had_error']);
    }

    /**
     * Test generate successful flow.
     */
    public function test_generate_success_flow(): void
    {
        $session = ChatSession::create([
            'session_id' => '99999999-9999-9999-9999-999999999999',
            'title' => 'Test Session',
        ]);

        $ollamaServiceMock = $this->createMock(OllamaService::class);
        $contextBuilderMock = $this->createMock(ContextBuilderService::class);

        $contextBuilderMock->expects($this->once())
            ->method('getConversationHistory')
            ->with($session)
            ->willReturn([['role' => 'user', 'content' => 'Pertanyaan sebelumnya']]);

        $ollamaServiceMock->expects($this->once())
            ->method('getModel')
            ->willReturn('gemma3-local');

        $ollamaServiceMock->expects($this->once())
            ->method('chat')
            ->with(
                'apakah helm wajib?',
                'Ini context yang sudah dipotong.',
                [['role' => 'user', 'content' => 'Pertanyaan sebelumnya']]
            )
            ->willReturn([
                'success' => true,
                'message' => 'Berdasarkan Pasal 57 UU No. 22 Tahun 2009, pengendara sepeda motor wajib menggunakan helm SNI. Melanggar dikenakan denda paling banyak Rp 250.000.',
                'model' => 'gemma3-local',
            ]);

        $service = new AnswerGeneratorService($ollamaServiceMock, $contextBuilderMock);
        
        $result = $service->generate(
            'apakah helm wajib?',
            'Ini context yang sudah dipotong.   ', // Excess spaces to test preprocessContext call
            $session
        );

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('pengendara sepeda motor wajib menggunakan helm SNI', $result['answer']);
        $this->assertEquals('gemma3-local', $result['model']);
        $this->assertEquals('Pasal 57', $result['references']['pasal']);
        $this->assertEquals('Denda paling banyak Rp 250.000.', $result['references']['sanksi']);
        $this->assertEquals('UU No. 22 Tahun 2009', $result['references']['undang_undang']);
        $this->assertFalse($result['metadata']['had_error']);
        $this->assertGreaterThanOrEqual(0, $result['metadata']['generation_time_ms']);
    }

    /**
     * Test generate returns fallback on AI failure.
     */
    public function test_generate_returns_fallback_on_ai_failure(): void
    {
        $session = ChatSession::create([
            'session_id' => '88888888-8888-8888-8888-888888888888',
            'title' => 'Test Session',
        ]);

        $ollamaServiceMock = $this->createMock(OllamaService::class);
        $contextBuilderMock = $this->createMock(ContextBuilderService::class);

        $contextBuilderMock->expects($this->once())
            ->method('getConversationHistory')
            ->willReturn([]);

        $ollamaServiceMock->expects($this->once())
            ->method('chat')
            ->willReturn([
                'success' => false,
                'message' => 'Ollama Connection Failed',
                'model' => 'fallback',
            ]);

        $service = new AnswerGeneratorService($ollamaServiceMock, $contextBuilderMock);

        $result = $service->generate('apakah helm wajib?', 'Context', $session);

        $this->assertFalse($result['success']);
        $this->assertEquals('fallback', $result['model']);
        $this->assertTrue($result['metadata']['had_error']);
    }

    /**
     * Test generate returns fallback when AI response is too short.
     */
    public function test_generate_returns_fallback_when_ai_response_too_short(): void
    {
        $session = ChatSession::create([
            'session_id' => '77777777-7777-7777-7777-777777777777',
            'title' => 'Test Session',
        ]);

        $ollamaServiceMock = $this->createMock(OllamaService::class);
        $contextBuilderMock = $this->createMock(ContextBuilderService::class);

        $contextBuilderMock->expects($this->once())
            ->method('getConversationHistory')
            ->willReturn([]);

        $ollamaServiceMock->expects($this->once())
            ->method('chat')
            ->willReturn([
                'success' => true,
                'message' => 'Pendek', // Less than 10 chars
                'model' => 'gemma3-local',
            ]);

        $service = new AnswerGeneratorService($ollamaServiceMock, $contextBuilderMock);

        $result = $service->generate('apakah helm wajib?', 'Context', $session);

        $this->assertFalse($result['success']);
        $this->assertEquals('fallback', $result['model']);
        $this->assertTrue($result['metadata']['had_error']);
    }

    /**
     * Test handleGenerationError returns fallback and logs the issue.
     */
    public function test_handle_generation_error_logs_and_returns_fallback(): void
    {
        $session = ChatSession::create([
            'session_id' => '66666666-6666-6666-6666-666666666666',
            'title' => 'Test Session',
        ]);

        $ollamaServiceMock = $this->createMock(OllamaService::class);
        $contextBuilderMock = $this->createMock(ContextBuilderService::class);

        $service = new AnswerGeneratorService($ollamaServiceMock, $contextBuilderMock);

        $exception = new \RuntimeException('Database is down');
        
        Log::shouldReceive('error')
            ->once()
            ->with('[PBI-09] Answer generation failed with exception', \Mockery::on(function ($data) use ($session) {
                return $data['session_id'] === $session->session_id &&
                       $data['error_class'] === \RuntimeException::class &&
                       $data['error_message'] === 'Database is down';
            }));

        Log::shouldReceive('warning')
            ->once()
            ->with('[PBI-09] Using fallback answer');

        $result = $service->handleGenerationError($exception, $session, 200);

        $this->assertFalse($result['success']);
        $this->assertEquals('fallback', $result['model']);
        $this->assertEquals(200, $result['metadata']['generation_time_ms']);
    }
}
