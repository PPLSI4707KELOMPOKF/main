<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Services\OllamaService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Config;

class OllamaServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('ollama.base_url', 'http://127.0.0.1:11434');
        Config::set('ollama.model', 'mistral');
        Config::set('ollama.fallback_model', 'llama3');
        Config::set('ollama.timeout', 120);
        Config::set('ollama.retry_attempts', 2);
        Config::set('ollama.retry_delay', 0);
        Config::set('ollama.temperature', 0.2);
        Config::set('ollama.top_p', 0.7);
        Config::set('ollama.repeat_penalty', 1.3);
        Config::set('ollama.num_predict', 300);
        Config::set('ollama.system_prompt', 'Kamu adalah Lentra AI.');
    }

    /**
     * Test successful chat response from primary model.
     */
    public function test_chat_returns_success_with_primary_model(): void
    {
        Http::fake([
            '127.0.0.1:11434/api/chat' => Http::response([
                'message' => [
                    'role' => 'assistant',
                    'content' => 'Berdasarkan Pasal 57 UU LLAJ, helm wajib SNI.',
                ],
            ], 200),
        ]);

        $service = new OllamaService();
        $result = $service->chat('apa aturan helm?');

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('Pasal 57', $result['message']);
        $this->assertEquals('mistral', $result['model']);
        $this->assertNotNull($result['pasal']);
    }

    /**
     * Test fallback to secondary model when primary fails.
     */
    public function test_chat_falls_back_to_secondary_model(): void
    {
        $callCount = 0;

        Http::fake(function ($request) use (&$callCount) {
            $callCount++;
            $body = json_decode($request->body(), true);
            $model = $body['model'] ?? '';

            if ($model === 'mistral') {
                return Http::response('Server Error', 500);
            }

            // llama3 fallback succeeds
            return Http::response([
                'message' => [
                    'role' => 'assistant',
                    'content' => 'Jawaban dari model fallback tentang Pasal 57.',
                ],
            ], 200);
        });

        $service = new OllamaService();
        $result = $service->chat('apa aturan helm?');

        $this->assertTrue($result['success']);
        $this->assertEquals('llama3', $result['model']);
    }

    /**
     * Test fallback response when both models fail.
     */
    public function test_chat_returns_fallback_when_both_models_fail(): void
    {
        Http::fake([
            '127.0.0.1:11434/*' => Http::response('Service Unavailable', 503),
        ]);

        $service = new OllamaService();
        $result = $service->chat('apa aturan helm?');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('ollama serve', $result['message']);
        $this->assertEquals('fallback', $result['model']);
    }

    /**
     * Test anti-repeat removes duplicated paragraphs.
     */
    public function test_anti_repeat_removes_duplicate_paragraphs(): void
    {
        Http::fake([
            '127.0.0.1:11434/api/chat' => Http::response([
                'message' => [
                    'role' => 'assistant',
                    'content' => "Pasal 57 mengatur tentang helm.\n\nPasal 57 mengatur tentang helm.\n\nInformasi tambahan.",
                ],
            ], 200),
        ]);

        $service = new OllamaService();
        $result = $service->chat('helm?');

        $this->assertTrue($result['success']);
        // Should have removed the duplicate paragraph
        $this->assertEquals(1, substr_count($result['message'], 'Pasal 57 mengatur tentang helm.'));
        $this->assertStringContainsString('Informasi tambahan', $result['message']);
    }

    /**
     * Test empty response triggers retry.
     */
    public function test_empty_response_triggers_retry(): void
    {
        $callCount = 0;

        Http::fake(function () use (&$callCount) {
            $callCount++;
            if ($callCount <= 1) {
                return Http::response([
                    'message' => ['role' => 'assistant', 'content' => ''],
                ], 200);
            }
            return Http::response([
                'message' => ['role' => 'assistant', 'content' => 'Jawaban valid.'],
            ], 200);
        });

        $service = new OllamaService();
        $result = $service->chat('pertanyaan');

        $this->assertTrue($result['success']);
        $this->assertEquals('Jawaban valid.', $result['message']);
    }

    /**
     * Test response JSON format.
     */
    public function test_response_json_format(): void
    {
        Http::fake([
            '127.0.0.1:11434/api/chat' => Http::response([
                'message' => [
                    'role' => 'assistant',
                    'content' => 'Berdasarkan undang-undang, denda paling banyak Rp 500.000.',
                ],
            ], 200),
        ]);

        $service = new OllamaService();
        $result = $service->chat('berapa denda tilang?');

        $this->assertArrayHasKey('success', $result);
        $this->assertArrayHasKey('message', $result);
        $this->assertArrayHasKey('model', $result);
        $this->assertArrayHasKey('pasal', $result);
        $this->assertArrayHasKey('sanksi', $result);
        $this->assertIsBool($result['success']);
        $this->assertIsString($result['message']);
        $this->assertIsString($result['model']);
    }

    /**
     * Test isAvailable returns true when Ollama is running.
     */
    public function test_is_available_returns_true(): void
    {
        Http::fake([
            '127.0.0.1:11434/api/tags' => Http::response(['models' => []], 200),
        ]);

        $service = new OllamaService();
        $this->assertTrue($service->isAvailable());
    }

    /**
     * Test isAvailable returns false when Ollama is down.
     */
    public function test_is_available_returns_false_when_down(): void
    {
        Http::fake([
            '127.0.0.1:11434/*' => Http::response('', 500),
        ]);

        $service = new OllamaService();
        $this->assertFalse($service->isAvailable());
    }

    /**
     * Test model getters.
     */
    public function test_model_getters(): void
    {
        $service = new OllamaService();

        $this->assertEquals('mistral', $service->getModel());
        $this->assertEquals('llama3', $service->getFallbackModel());
    }
}
