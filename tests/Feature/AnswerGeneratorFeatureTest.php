<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\ChatSession;
use App\Services\AnswerGeneratorService;
use App\Services\ContextBuilderService;
use App\Services\OllamaService;
use App\Services\EmbeddingService;
use App\Services\VectorDatabaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Mockery\MockInterface;

class AnswerGeneratorFeatureTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        \Mockery::close();
        parent::tearDown();
    }

    /**
     * Test full generation integration: user query -> context RAG -> AI response -> structured output.
     */
    public function test_full_pipeline_answer_generation(): void
    {
        // 1. Setup session
        $uuid = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';
        $session = ChatSession::create([
            'session_id' => $uuid,
            'title' => 'Percakapan Integrasi PBI-09',
        ]);

        // 2. Mock RAG components: Embedding & Vector Database
        $fakeEmbedding = [0.1, 0.2, 0.3];
        $this->mock(EmbeddingService::class, function (MockInterface $mock) use ($fakeEmbedding) {
            $mock->shouldReceive('generateEmbedding')
                ->once()
                ->andReturn($fakeEmbedding);
        });

        $fakeRelevantDocs = [
            [
                'id' => 'pasal-287',
                'content' => 'Melanggar rambu lalu lintas dipidana kurungan paling lama 2 bulan atau denda paling banyak Rp 500.000.',
                'metadata' => ['pasal' => 'Pasal 287', 'topik' => 'Rambu'],
                'score' => 0.98,
            ]
        ];
        $this->mock(VectorDatabaseService::class, function (MockInterface $mock) use ($fakeEmbedding, $fakeRelevantDocs) {
            $mock->shouldReceive('search')
                ->once()
                ->andReturn($fakeRelevantDocs);
        });

        // 3. Mock OllamaService
        $this->mock(OllamaService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getModel')
                ->once()
                ->andReturn('gemma3-local');

            $mock->shouldReceive('chat')
                ->once()
                ->with(
                    'bagaimana aturan melanggar rambu?',
                    \Mockery::type('string'), // RAG context built
                    \Mockery::type('array')   // History
                )
                ->andReturn([
                    'success' => true,
                    'message' => 'Berdasarkan RAG, melanggar rambu diatur dalam Pasal 287 UU No. 22 Tahun 2009. Pelaku dapat dipidana kurungan paling lama 2 bulan atau denda paling banyak Rp 500.000.',
                    'model' => 'gemma3-local',
                ]);
        });

        // 4. Resolve ContextBuilderService and AnswerGeneratorService from container
        $contextBuilder = app(ContextBuilderService::class);
        
        // Build actual context from mocked database results
        $cleanedMessage = 'bagaimana aturan melanggar rambu?';
        $embedding = app(EmbeddingService::class)->generateEmbedding($cleanedMessage);
        $relevantDocs = app(VectorDatabaseService::class)->search($embedding, 1);
        $context = $contextBuilder->build($cleanedMessage, $relevantDocs, $session);

        // Instansiasi AnswerGeneratorService dengan Mocked Ollama dan real ContextBuilder
        $answerGenerator = app(AnswerGeneratorService::class);
        
        // 5. Generate answer
        $result = $answerGenerator->generate($cleanedMessage, $context, $session);

        // 6. Assertions on structure and content
        $this->assertTrue($result['success']);
        $this->assertStringContainsString('melanggar rambu diatur dalam Pasal 287 UU No. 22 Tahun 2009', $result['answer']);
        $this->assertEquals('gemma3-local', $result['model']);
        
        // References assertions
        $this->assertEquals('Pasal 287', $result['references']['pasal']);
        $this->assertEquals('Dipidana kurungan paling lama 2 bulan atau denda paling banyak Rp 500.000.', $result['references']['sanksi']);
        $this->assertEquals('UU No. 22 Tahun 2009', $result['references']['undang_undang']);

        // Metadata assertions
        $this->assertFalse($result['metadata']['had_error']);
        $this->assertEquals('gemma3-local', $result['metadata']['model_used']);
        $this->assertGreaterThan(0, $result['metadata']['answer_length']);
    }

    /**
     * Test full generation integration when Ollama is down (safety fallback path).
     */
    public function test_full_pipeline_answer_generation_fallback_on_failure(): void
    {
        $uuid = 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb';
        $session = ChatSession::create([
            'session_id' => $uuid,
            'title' => 'Percakapan Integrasi Gagal',
        ]);

        // Mock OllamaService to fail
        $this->mock(OllamaService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getModel')
                ->once()
                ->andReturn('gemma3-local');

            $mock->shouldReceive('chat')
                ->once()
                ->andReturn([
                    'success' => false,
                    'message' => 'Ollama server unavailable',
                    'model' => 'fallback',
                ]);
        });

        $answerGenerator = app(AnswerGeneratorService::class);
        $result = $answerGenerator->generate('apakah boleh parkir sembarangan?', 'Context', $session);

        $this->assertFalse($result['success']);
        $this->assertEquals('fallback', $result['model']);
        $this->assertTrue($result['metadata']['had_error']);
        $this->assertNull($result['references']['pasal']);
        $this->assertNull($result['references']['sanksi']);
        $this->assertNull($result['references']['undang_undang']);
    }
}
