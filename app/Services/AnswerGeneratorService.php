<?php

namespace App\Services;

use App\Models\ChatSession;
use Illuminate\Support\Facades\Log;

/**
 * AnswerGeneratorService — PBI-09: Generate Jawaban.
 *
 * Service layer yang bertanggung jawab menghasilkan jawaban akhir
 * berbasis regulasi hukum lalu lintas yang relevan.
 *
 * Pipeline Generate Jawaban:
 * 1. Terima pertanyaan pengguna (sudah di-preprocess PBI-3)
 * 2. Terima context RAG (sudah disusun PBI-7)
 * 3. Kirim ke AI via OllamaService (PBI-8)
 * 4. Validasi response AI
 * 5. Ekstraksi referensi hukum (pasal, sanksi, UU)
 * 6. Format jawaban terstruktur
 * 7. Logging & error handling
 *
 * Fitur:
 * - Validasi jawaban AI (tidak kosong, tidak terlalu pendek)
 * - Ekstraksi referensi pasal dan sanksi
 * - Deteksi sumber UU yang dirujuk
 * - Format jawaban terstruktur untuk frontend
 * - Fallback jawaban aman jika AI gagal
 * - Anti-duplicate content detection
 * - Logging lengkap setiap tahap generate
 */
class AnswerGeneratorService
{
    protected OllamaService $ollamaService;
    protected ContextBuilderService $contextBuilder;

    /**
     * Batas minimal karakter jawaban yang dianggap valid.
     */
    protected int $minAnswerLength;

    /**
     * Batas maksimal karakter context yang dikirim ke AI.
     */
    protected int $maxContextLength;

    public function __construct(OllamaService $ollamaService, ContextBuilderService $contextBuilder)
    {
        $this->ollamaService    = $ollamaService;
        $this->contextBuilder   = $contextBuilder;
        $this->minAnswerLength  = 10;
        $this->maxContextLength = 4000;
    }

    /**
     * Entry point utama PBI-09: Generate jawaban dari pertanyaan pengguna.
     *
     * Mengorkestrasi seluruh pipeline: validasi context → kirim ke AI →
     * validasi response → ekstraksi referensi hukum → format jawaban.
     *
     * @param string      $cleanedMessage  Pertanyaan yang sudah di-preprocess (PBI-3)
     * @param string      $context         Context dari ContextBuilderService (PBI-7)
     * @param ChatSession $session         Sesi chat aktif
     * @return array{success: bool, answer: string, model: string, references: array, metadata: array}
     */
    public function generate(string $cleanedMessage, string $context, ChatSession $session): array
    {
        $startTime = microtime(true);

        Log::info('[PBI-09] Generate jawaban started', [
            'session_id'      => $session->session_id,
            'question_length' => mb_strlen($cleanedMessage),
            'context_length'  => mb_strlen($context),
            'has_context'     => !empty(trim($context)),
        ]);

        try {
            // Step 1: Preprocess context (batasi panjang, bersihkan)
            $processedContext = $this->preprocessContext($context);

            // Step 2: Ambil conversation history
            $conversationHistory = $this->contextBuilder->getConversationHistory($session);

            // Step 3: Kirim ke AI via OllamaService
            $aiResult = $this->requestAIResponse($cleanedMessage, $processedContext, $conversationHistory);

            // Step 4: Validasi response AI
            if (!$aiResult['success']) {
                $elapsedMs = $this->getElapsedMs($startTime);
                Log::warning('[PBI-09] AI failed to generate answer', [
                    'session_id'    => $session->session_id,
                    'error'         => $aiResult['message'] ?? 'Unknown error',
                    'elapsed_ms'    => $elapsedMs,
                ]);
                return $this->buildFallbackAnswer($elapsedMs);
            }

            $rawAnswer = $aiResult['message'] ?? '';

            // Step 5: Validasi jawaban tidak kosong / terlalu pendek
            if (!$this->isValidAnswer($rawAnswer)) {
                $elapsedMs = $this->getElapsedMs($startTime);
                Log::warning('[PBI-09] AI answer too short or empty', [
                    'session_id'      => $session->session_id,
                    'answer_length'   => mb_strlen($rawAnswer),
                    'min_required'    => $this->minAnswerLength,
                    'elapsed_ms'      => $elapsedMs,
                ]);
                return $this->buildFallbackAnswer($elapsedMs);
            }

            // Step 6: Bersihkan dan deduplicate jawaban
            $cleanedAnswer = $this->cleanAnswer($rawAnswer);

            // Step 7: Ekstraksi referensi hukum
            $references = $this->extractLegalReferences($cleanedAnswer);

            // Step 8: Build response terformat
            $elapsedMs = $this->getElapsedMs($startTime);

            Log::info('[PBI-09] Jawaban berhasil di-generate', [
                'session_id'      => $session->session_id,
                'answer_length'   => mb_strlen($cleanedAnswer),
                'model'           => $aiResult['model'] ?? 'unknown',
                'has_pasal'       => !empty($references['pasal']),
                'has_sanksi'      => !empty($references['sanksi']),
                'has_uu'          => !empty($references['undang_undang']),
                'elapsed_ms'      => $elapsedMs,
            ]);

            return $this->buildSuccessAnswer($cleanedAnswer, $aiResult, $references, $elapsedMs);

        } catch (\Exception $e) {
            $elapsedMs = $this->getElapsedMs($startTime);
            return $this->handleGenerationError($e, $session, $elapsedMs);
        }
    }

    /**
     * Preprocess context sebelum dikirim ke AI.
     *
     * - Bersihkan whitespace berlebihan
     * - Batasi panjang agar tidak melebihi kapasitas model
     *
     * @param string $context
     * @return string
     */
    public function preprocessContext(string $context): string
    {
        if (empty(trim($context))) {
            return '';
        }

        // Bersihkan whitespace berlebihan
        $processed = preg_replace('/\n{3,}/', "\n\n", $context);
        $processed = preg_replace('/[ \t]+/', ' ', $processed);
        $processed = trim($processed);

        // Batasi panjang context
        if (mb_strlen($processed) > $this->maxContextLength) {
            $processed = mb_substr($processed, 0, $this->maxContextLength);

            // Potong di akhir kalimat terdekat
            $lastPeriod  = mb_strrpos($processed, '.');
            $lastNewline = mb_strrpos($processed, "\n");
            $cutPoint    = max($lastPeriod, $lastNewline);

            if ($cutPoint !== false && $cutPoint > ($this->maxContextLength * 0.7)) {
                $processed = mb_substr($processed, 0, $cutPoint + 1);
            }

            Log::info('[PBI-09] Context truncated', [
                'original_length'  => mb_strlen($context),
                'truncated_length' => mb_strlen($processed),
            ]);
        }

        return $processed;
    }

    /**
     * Kirim pertanyaan dan context ke OllamaService.
     *
     * @param string $message   Pertanyaan pengguna
     * @param string $context   Context yang sudah dipreprocess
     * @param array  $history   Riwayat percakapan
     * @return array Response dari OllamaService
     */
    protected function requestAIResponse(string $message, string $context, array $history): array
    {
        Log::info('[PBI-09] Sending to AI for answer generation', [
            'model'         => $this->ollamaService->getModel(),
            'context_chars' => mb_strlen($context),
            'history_count' => count($history),
        ]);

        try {
            return $this->ollamaService->chat($message, $context, $history);
        } catch (\Exception $e) {
            Log::error('[PBI-09] OllamaService exception during generation', [
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Validasi apakah jawaban AI cukup bermakna.
     *
     * @param string $answer
     * @return bool
     */
    public function isValidAnswer(string $answer): bool
    {
        $trimmed = trim($answer);

        if (empty($trimmed)) {
            return false;
        }

        if (mb_strlen($trimmed) < $this->minAnswerLength) {
            return false;
        }

        return true;
    }

    /**
     * Bersihkan jawaban AI dari karakter tidak diinginkan dan duplikasi.
     *
     * @param string $answer Jawaban mentah dari AI
     * @return string Jawaban yang sudah dibersihkan
     */
    public function cleanAnswer(string $answer): string
    {
        // Hapus karakter kontrol
        $cleaned = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $answer);

        // Hapus duplikasi paragraf
        $cleaned = $this->removeDuplicateParagraphs($cleaned);

        // Trim
        $cleaned = trim($cleaned);

        return $cleaned;
    }

    /**
     * Hapus paragraf yang terduplikasi dalam jawaban.
     *
     * @param string $text
     * @return string
     */
    protected function removeDuplicateParagraphs(string $text): string
    {
        $paragraphs = preg_split('/\n{2,}/', trim($text));

        if (count($paragraphs) <= 1) {
            return $this->removeDuplicateSentences($text);
        }

        $seen   = [];
        $unique = [];

        foreach ($paragraphs as $paragraph) {
            $normalized = mb_strtolower(trim($paragraph));

            if (empty($normalized)) {
                continue;
            }

            $isDuplicate = false;
            foreach ($seen as $seenText) {
                if ($normalized === $seenText) {
                    $isDuplicate = true;
                    break;
                }
                // Similarity check
                if (mb_strlen($normalized) > 50 && mb_strlen($seenText) > 50) {
                    similar_text($normalized, $seenText, $percent);
                    if ($percent > 85) {
                        $isDuplicate = true;
                        break;
                    }
                }
            }

            if (!$isDuplicate) {
                $seen[]   = $normalized;
                $unique[] = trim($paragraph);
            }
        }

        return implode("\n\n", $unique);
    }

    /**
     * Hapus kalimat duplikat dalam satu paragraf.
     *
     * @param string $text
     * @return string
     */
    protected function removeDuplicateSentences(string $text): string
    {
        $sentences = preg_split('/(?<=[.!?])\s+/', trim($text));

        if (count($sentences) <= 1) {
            return $text;
        }

        $seen   = [];
        $unique = [];

        foreach ($sentences as $sentence) {
            $normalized = mb_strtolower(trim($sentence));
            if (!empty($normalized) && !in_array($normalized, $seen)) {
                $seen[]   = $normalized;
                $unique[] = trim($sentence);
            }
        }

        return implode(' ', $unique);
    }

    /**
     * Ekstraksi referensi hukum dari jawaban AI.
     *
     * Mengidentifikasi:
     * - Pasal yang dirujuk
     * - Sanksi/denda yang disebutkan
     * - Undang-undang sumber
     *
     * @param string $answer Jawaban AI yang sudah dibersihkan
     * @return array{pasal: ?string, sanksi: ?string, undang_undang: ?string}
     */
    public function extractLegalReferences(string $answer): array
    {
        return [
            'pasal'         => $this->extractPasal($answer),
            'sanksi'        => $this->extractSanksi($answer),
            'undang_undang' => $this->extractUndangUndang($answer),
        ];
    }

    /**
     * Ekstraksi referensi pasal dari jawaban.
     *
     * @param string $text
     * @return string|null
     */
    protected function extractPasal(string $text): ?string
    {
        if (preg_match('/[Pp]asal\s+(\d+[A-Za-z]?(?:\s*(?:ayat|huruf)\s*\([^)]+\))?)/u', $text, $matches)) {
            return 'Pasal ' . $matches[1];
        }
        return null;
    }

    /**
     * Ekstraksi sanksi/denda dari jawaban.
     *
     * @param string $text
     * @return string|null
     */
    protected function extractSanksi(string $text): ?string
    {
        if (preg_match('/(?:pidana penjara|pidana kurungan|dipidana|pidana|denda|kurungan).*?\.(?:\s|$)/ui', $text, $matches)) {
            return ucfirst(trim($matches[0]));
        }
        return null;
    }

    /**
     * Ekstraksi undang-undang yang dirujuk dari jawaban.
     *
     * @param string $text
     * @return string|null
     */
    protected function extractUndangUndang(string $text): ?string
    {
        if (preg_match('/UU\s*(?:No\.?\s*)?(\d+)\s*(?:Tahun\s*(\d{4}))?/ui', $text, $matches)) {
            $result = 'UU No. ' . $matches[1];
            if (!empty($matches[2])) {
                $result .= ' Tahun ' . $matches[2];
            }
            return $result;
        }
        return null;
    }

    /**
     * Build response jawaban sukses.
     *
     * @param string $answer      Jawaban yang sudah dibersihkan
     * @param array  $aiResult    Response asli dari OllamaService
     * @param array  $references  Referensi hukum yang diekstraksi
     * @param int    $elapsedMs   Waktu pemrosesan dalam ms
     * @return array
     */
    protected function buildSuccessAnswer(string $answer, array $aiResult, array $references, int $elapsedMs): array
    {
        return [
            'success'    => true,
            'answer'     => $answer,
            'model'      => $aiResult['model'] ?? config('ollama.model'),
            'references' => $references,
            'metadata'   => [
                'generation_time_ms' => $elapsedMs,
                'answer_length'      => mb_strlen($answer),
                'had_error'          => false,
                'model_used'         => $aiResult['model'] ?? 'unknown',
            ],
        ];
    }

    /**
     * Build fallback answer ketika AI gagal generate.
     *
     * @param int $elapsedMs
     * @return array
     */
    public function buildFallbackAnswer(int $elapsedMs = 0): array
    {
        Log::warning('[PBI-09] Using fallback answer');

        return [
            'success'    => false,
            'answer'     => 'Mohon maaf, Lentra AI sedang tidak dapat menghasilkan jawaban saat ini. '
                          . 'Silakan coba beberapa saat lagi atau pastikan layanan AI sedang berjalan.',
            'model'      => 'fallback',
            'references' => [
                'pasal'         => null,
                'sanksi'        => null,
                'undang_undang' => null,
            ],
            'metadata'   => [
                'generation_time_ms' => $elapsedMs,
                'answer_length'      => 0,
                'had_error'          => true,
                'model_used'         => 'fallback',
            ],
        ];
    }

    /**
     * Handle error saat proses generate jawaban gagal.
     *
     * @param \Exception  $exception
     * @param ChatSession $session
     * @param int         $elapsedMs
     * @return array
     */
    public function handleGenerationError(\Exception $exception, ChatSession $session, int $elapsedMs = 0): array
    {
        Log::error('[PBI-09] Answer generation failed with exception', [
            'session_id'    => $session->session_id,
            'error_class'   => get_class($exception),
            'error_message' => $exception->getMessage(),
            'error_file'    => $exception->getFile(),
            'error_line'    => $exception->getLine(),
            'elapsed_ms'    => $elapsedMs,
        ]);

        return $this->buildFallbackAnswer($elapsedMs);
    }

    /**
     * Hitung elapsed time dalam millisecond.
     *
     * @param float $startTime
     * @return int
     */
    protected function getElapsedMs(float $startTime): int
    {
        return (int) round((microtime(true) - $startTime) * 1000);
    }
}
