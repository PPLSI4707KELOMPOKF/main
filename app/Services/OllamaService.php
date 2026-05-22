<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

/**
 * OllamaService — Production-ready service untuk komunikasi dengan Ollama API.
 *
 * Fitur:
 * - Retry otomatis dengan fallback model
 * - Timeout protection
 * - Anti-repeat response detection
 * - Connection error handling
 * - Formatted JSON response
 * - Loading state support
 */
class OllamaService
{
    protected string $baseUrl;
    protected string $model;
    protected string $fallbackModel;
    protected int $timeout;
    protected int $retryAttempts;
    protected int $retryDelay;
    protected float $temperature;
    protected float $topP;
    protected float $repeatPenalty;
    protected int $numPredict;
    protected string $systemPrompt;

    public function __construct()
    {
        $this->baseUrl        = rtrim(config('ollama.base_url', 'http://127.0.0.1:11434'), '/');
        $this->model          = config('ollama.model', 'mistral');
        $this->fallbackModel  = config('ollama.fallback_model', 'llama3');
        $this->timeout        = config('ollama.timeout', 120);
        $this->retryAttempts  = config('ollama.retry_attempts', 3);
        $this->retryDelay     = config('ollama.retry_delay', 500);
        $this->temperature    = config('ollama.temperature', 0.2);
        $this->topP           = config('ollama.top_p', 0.7);
        $this->repeatPenalty  = config('ollama.repeat_penalty', 1.3);
        $this->numPredict     = config('ollama.num_predict', 300);
        $this->systemPrompt   = config('ollama.system_prompt', '');
    }

    /**
     * Kirim pertanyaan ke Ollama dan dapatkan jawaban AI.
     *
     * @param string $userMessage Pertanyaan pengguna
     * @param string $context Context dari ContextBuilderService (PBI-7)
     * @param array $conversationHistory Riwayat percakapan sebagai messages array
     * @return array{success: bool, message: string, model: string, pasal: ?string, sanksi: ?string}
     */
    public function chat(string $userMessage, string $context = '', array $conversationHistory = []): array
    {
        // Bangun messages array untuk Ollama /api/chat
        $messages = $this->buildMessages($userMessage, $context, $conversationHistory);

        // Coba model utama
        $result = $this->sendRequest($this->model, $messages);

        if ($result['success']) {
            return $result;
        }

        // Fallback ke model alternatif
        Log::warning('[OllamaService] Primary model failed, trying fallback', [
            'primary_model'  => $this->model,
            'fallback_model' => $this->fallbackModel,
            'error'          => $result['message'],
        ]);

        $fallbackResult = $this->sendRequest($this->fallbackModel, $messages);

        if ($fallbackResult['success']) {
            return $fallbackResult;
        }

        // Kedua model gagal — return fallback response
        Log::error('[OllamaService] Both models failed', [
            'primary_model'  => $this->model,
            'fallback_model' => $this->fallbackModel,
        ]);

        return $this->getFallbackResponse();
    }

    /**
     * Kirim HTTP request ke Ollama API /api/chat dengan retry otomatis.
     */
    protected function sendRequest(string $model, array $messages): array
    {
        $payload = [
            'model'    => $model,
            'messages' => $messages,
            'stream'   => false,
            'options'  => [
                'temperature'    => $this->temperature,
                'top_p'          => $this->topP,
                'repeat_penalty' => $this->repeatPenalty,
                'num_predict'    => $this->numPredict,
            ],
        ];

        $lastError = '';

        for ($attempt = 1; $attempt <= $this->retryAttempts; $attempt++) {
            try {
                $response = Http::timeout($this->timeout)
                    ->withHeaders([
                        'Content-Type' => 'application/json',
                    ])
                    ->post("{$this->baseUrl}/api/chat", $payload);

                if ($response->successful()) {
                    $data = $response->json();
                    $content = $data['message']['content'] ?? '';

                    if (empty(trim($content))) {
                        $lastError = 'Empty response from AI';
                        Log::warning("[OllamaService] Empty response on attempt {$attempt}", [
                            'model' => $model,
                        ]);
                        continue;
                    }

                    // Anti-repeat: deteksi jawaban yang berulang
                    $content = $this->removeRepeatedContent($content);

                    Log::info('[OllamaService] AI response received', [
                        'model'           => $model,
                        'attempt'         => $attempt,
                        'response_length' => mb_strlen($content),
                    ]);

                    return [
                        'success' => true,
                        'message' => trim($content),
                        'model'   => $model,
                        'pasal'   => $this->extractPasal($content),
                        'sanksi'  => $this->extractSanksi($content),
                    ];
                }

                $lastError = "HTTP {$response->status()}: {$response->body()}";
                Log::warning("[OllamaService] Non-success HTTP on attempt {$attempt}", [
                    'model'  => $model,
                    'status' => $response->status(),
                ]);

            } catch (\Illuminate\Http\Client\ConnectionException $e) {
                $lastError = 'Connection refused — Ollama tidak berjalan';
                Log::error("[OllamaService] Connection failed on attempt {$attempt}", [
                    'model'   => $model,
                    'message' => $e->getMessage(),
                ]);

            } catch (\Illuminate\Http\Client\RequestException $e) {
                $lastError = "Request error: {$e->getMessage()}";
                Log::error("[OllamaService] Request exception on attempt {$attempt}", [
                    'model'   => $model,
                    'message' => $e->getMessage(),
                ]);

            } catch (\Exception $e) {
                $lastError = "Unexpected error: {$e->getMessage()}";
                Log::error("[OllamaService] Unexpected error on attempt {$attempt}", [
                    'model'   => $model,
                    'message' => $e->getMessage(),
                ]);
            }

            // Delay sebelum retry (kecuali attempt terakhir)
            if ($attempt < $this->retryAttempts) {
                usleep($this->retryDelay * 1000);
            }
        }

        return [
            'success' => false,
            'message' => $lastError,
            'model'   => $model,
            'pasal'   => null,
            'sanksi'  => null,
        ];
    }

    /**
     * Bangun array messages untuk Ollama /api/chat format.
     */
    protected function buildMessages(string $userMessage, string $context, array $conversationHistory): array
    {
        $messages = [];

        // System message — gabungkan system prompt + context dari RAG (PBI-7)
        $systemContent = $this->systemPrompt;
        if (!empty($context)) {
            $systemContent .= "\n\n" . $context;
        }

        $messages[] = [
            'role'    => 'system',
            'content' => $systemContent,
        ];

        // Conversation history (max 6 pesan terakhir)
        foreach (array_slice($conversationHistory, -6) as $msg) {
            $messages[] = [
                'role'    => $msg['role'] === 'assistant' ? 'assistant' : 'user',
                'content' => $msg['content'],
            ];
        }

        // Pertanyaan pengguna saat ini
        $messages[] = [
            'role'    => 'user',
            'content' => $userMessage,
        ];

        return $messages;
    }

    /**
     * Anti-repeat: hapus konten yang diulang-ulang di response.
     * Mendeteksi paragraf atau kalimat duplikat dan hanya menyimpan yang pertama.
     */
    protected function removeRepeatedContent(string $content): string
    {
        // Split by paragraphs
        $paragraphs = preg_split('/\n{2,}/', trim($content));

        if (count($paragraphs) <= 1) {
            return $this->removeRepeatedSentences($content);
        }

        $seen = [];
        $unique = [];

        foreach ($paragraphs as $paragraph) {
            $normalized = mb_strtolower(trim($paragraph));
            // Abaikan paragraf yang sangat mirip dengan yang sudah ada
            $isDuplicate = false;
            foreach ($seen as $seenText) {
                if ($this->isSimilarText($normalized, $seenText)) {
                    $isDuplicate = true;
                    break;
                }
            }

            if (!$isDuplicate && !empty(trim($paragraph))) {
                $seen[] = $normalized;
                $unique[] = trim($paragraph);
            }
        }

        return implode("\n\n", $unique);
    }

    /**
     * Hapus kalimat duplikat dalam satu paragraf.
     */
    protected function removeRepeatedSentences(string $content): string
    {
        $sentences = preg_split('/(?<=[.!?])\s+/', trim($content));

        if (count($sentences) <= 1) {
            return $content;
        }

        $seen = [];
        $unique = [];

        foreach ($sentences as $sentence) {
            $normalized = mb_strtolower(trim($sentence));
            if (!in_array($normalized, $seen) && !empty(trim($sentence))) {
                $seen[] = $normalized;
                $unique[] = trim($sentence);
            }
        }

        return implode(' ', $unique);
    }

    /**
     * Cek apakah dua teks serupa (similarity > 80%).
     */
    protected function isSimilarText(string $a, string $b): bool
    {
        if ($a === $b) {
            return true;
        }

        $maxLen = max(mb_strlen($a), mb_strlen($b));
        if ($maxLen === 0) {
            return true;
        }

        $distance = levenshtein(
            mb_substr($a, 0, 255),
            mb_substr($b, 0, 255)
        );

        $similarity = 1 - ($distance / max(mb_strlen(mb_substr($a, 0, 255)), mb_strlen(mb_substr($b, 0, 255)), 1));

        return $similarity > 0.8;
    }

    /**
     * Extract referensi pasal dari response AI.
     */
    protected function extractPasal(string $content): ?string
    {
        if (preg_match('/[Pp]asal\s+(\d+[A-Za-z]?(?:\s*(?:ayat|huruf)\s*\([^)]+\))?)/u', $content, $matches)) {
            return 'Pasal ' . $matches[1];
        }
        return null;
    }

    /**
     * Extract sanksi/denda dari response AI.
     */
    protected function extractSanksi(string $content): ?string
    {
        if (preg_match('/(?:pidana penjara|denda|kurungan)[^.]+\./u', $content, $matches)) {
            return ucfirst(trim($matches[0]));
        }
        return null;
    }

    /**
     * Response fallback ketika AI tidak tersedia.
     */
    protected function getFallbackResponse(): array
    {
        return [
            'success' => false,
            'message' => 'AI sedang tidak tersedia. Pastikan Ollama berjalan menggunakan command: ollama serve',
            'model'   => 'fallback',
            'pasal'   => null,
            'sanksi'  => null,
        ];
    }

    /**
     * Cek apakah Ollama service berjalan.
     */
    public function isAvailable(): bool
    {
        try {
            $response = Http::timeout(5)->get("{$this->baseUrl}/api/tags");
            return $response->successful();
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Dapatkan daftar model yang tersedia di Ollama.
     */
    public function getAvailableModels(): array
    {
        try {
            $response = Http::timeout(5)->get("{$this->baseUrl}/api/tags");
            if ($response->successful()) {
                $data = $response->json();
                return array_column($data['models'] ?? [], 'name');
            }
        } catch (\Exception $e) {
            Log::error('[OllamaService] Failed to fetch models', [
                'message' => $e->getMessage(),
            ]);
        }
        return [];
    }

    /**
     * Getter untuk model name (untuk testing & logging).
     */
    public function getModel(): string
    {
        return $this->model;
    }

    /**
     * Getter untuk fallback model name.
     */
    public function getFallbackModel(): string
    {
        return $this->fallbackModel;
    }
}
