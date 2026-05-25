<?php

namespace App\Services;

use App\Models\ChatSession;
use Illuminate\Support\Facades\Log;

/**
 * AIProcessingService — PBI-08: Pemrosesan oleh AI (Gemma 3 Local).
 *
 * Service layer khusus yang mengorkestrasikan pemrosesan context
 * menggunakan model AI lokal Gemma3 melalui OllamaService.
 *
 * Pipeline:
 * 1. Validasi context (tidak kosong/invalid)
 * 2. Preprocessing context untuk format optimal Gemma3
 * 3. Kirim ke OllamaService (retry + timeout + fallback sudah built-in)
 * 4. Post-processing response (format, sanitasi, anti-duplicate)
 * 5. Error handling dengan logging lengkap dan fallback aman
 *
 * Fitur Protection:
 * - Context validation (empty/null guard)
 * - Retry protection (delegasi ke OllamaService)
 * - Timeout protection (delegasi ke OllamaService)
 * - Anti duplicate response
 * - Response formatter terstruktur
 * - Logging error lengkap
 * - Fallback response aman
 */
class AIProcessingService
{
    protected OllamaService $ollamaService;
    protected ContextBuilderService $contextBuilder;

    /**
     * Batas maksimal karakter context yang dikirim ke AI.
     * Mencegah context terlalu panjang yang bisa membuat Gemma3 lambat/timeout.
     */
    protected int $maxContextLength;

    /**
     * Batas minimal karakter response AI yang dianggap valid.
     */
    protected int $minResponseLength;

    public function __construct(OllamaService $ollamaService, ContextBuilderService $contextBuilder)
    {
        $this->ollamaService  = $ollamaService;
        $this->contextBuilder = $contextBuilder;
        $this->maxContextLength  = 4000;
        $this->minResponseLength = 5;
    }

    /**
     * Entry point utama PBI-08: Proses pertanyaan pengguna melalui pipeline AI.
     *
     * Menerima user message yang sudah dibersihkan (PBI-3), context dari RAG (PBI-7),
     * dan session, lalu mengirim ke Gemma3 untuk menghasilkan jawaban.
     *
     * @param string      $cleanedMessage  Pertanyaan yang sudah di-preprocess (PBI-3)
     * @param string      $context         Context dari ContextBuilderService (PBI-7)
     * @param ChatSession $session         Sesi chat aktif
     * @return array{success: bool, message: string, model: string, pasal: ?string, sanksi: ?string, processing_info: array}
     */
    public function processContext(string $cleanedMessage, string $context, ChatSession $session): array
    {
        $startTime = microtime(true);

        Log::info('[PBI-08] AI processing started', [
            'session_id'      => $session->session_id,
            'message_length'  => mb_strlen($cleanedMessage),
            'context_length'  => mb_strlen($context),
        ]);

        try {
            // Step 1: Validasi context
            $contextValidation = $this->validateContext($context);
            if (!$contextValidation['valid']) {
                Log::warning('[PBI-08] Context validation failed', [
                    'session_id' => $session->session_id,
                    'reason'     => $contextValidation['reason'],
                ]);

                // Context kosong — tetap proses tapi tanpa context RAG
                $context = '';
            }

            // Step 2: Preprocessing context untuk Gemma3
            $processedContext = $this->preprocessContextForAI($context);

            // Step 3: Ambil conversation history
            $conversationHistory = $this->contextBuilder->getConversationHistory($session);

            // Step 4: Kirim ke OllamaService (Gemma3)
            $aiResult = $this->sendToAI($cleanedMessage, $processedContext, $conversationHistory);

            // Step 5: Post-processing response
            $processedResult = $this->postProcessResponse($aiResult);

            $processingTime = round((microtime(true) - $startTime) * 1000);

            Log::info('[PBI-08] AI processing completed', [
                'session_id'      => $session->session_id,
                'success'         => $processedResult['success'],
                'model'           => $processedResult['model'],
                'processing_ms'   => $processingTime,
                'response_length' => mb_strlen($processedResult['message']),
            ]);

            // Step 6: Format response final
            return $this->formatResponse($processedResult, $processingTime);

        } catch (\Exception $e) {
            $processingTime = round((microtime(true) - $startTime) * 1000);

            return $this->handleProcessingError($e, $session, $processingTime);
        }
    }

    /**
     * Validasi context sebelum dikirim ke AI.
     *
     * Memastikan context tidak kosong, tidak null, dan memiliki
     * konten yang bermakna sebelum dikirim ke Gemma3.
     *
     * @param string $context Context dari ContextBuilderService
     * @return array{valid: bool, reason: string}
     */
    public function validateContext(string $context): array
    {
        // Context kosong
        if (empty(trim($context))) {
            return [
                'valid'  => false,
                'reason' => 'Context kosong — tidak ada dokumen referensi yang ditemukan',
            ];
        }

        // Context terlalu pendek (kemungkinan noise)
        if (mb_strlen(trim($context)) < 10) {
            return [
                'valid'  => false,
                'reason' => 'Context terlalu pendek untuk menjadi referensi bermakna',
            ];
        }

        return [
            'valid'  => true,
            'reason' => 'Context valid',
        ];
    }

    /**
     * Preprocessing context agar optimal untuk Gemma3.
     *
     * - Membatasi panjang context agar tidak melebihi kapasitas model
     * - Membersihkan whitespace berlebihan
     * - Memastikan format context terstruktur
     *
     * @param string $context Context yang akan dipreprocess
     * @return string Context yang sudah dioptimalkan
     */
    public function preprocessContextForAI(string $context): string
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

            // Potong di akhir kalimat terdekat agar tidak terpotong di tengah kata
            $lastPeriod = mb_strrpos($processed, '.');
            $lastNewline = mb_strrpos($processed, "\n");
            $cutPoint = max($lastPeriod, $lastNewline);

            if ($cutPoint !== false && $cutPoint > ($this->maxContextLength * 0.7)) {
                $processed = mb_substr($processed, 0, $cutPoint + 1);
            }

            Log::info('[PBI-08] Context truncated to fit model capacity', [
                'original_length'  => mb_strlen($context),
                'truncated_length' => mb_strlen($processed),
                'max_allowed'      => $this->maxContextLength,
            ]);
        }

        return $processed;
    }

    /**
     * Kirim pertanyaan dan context ke OllamaService (Gemma3).
     *
     * Wrapper method yang mendelegasikan ke OllamaService::chat()
     * dengan tambahan logging khusus PBI-08.
     *
     * @param string $cleanedMessage     Pertanyaan pengguna
     * @param string $processedContext   Context yang sudah dipreprocess
     * @param array  $conversationHistory Riwayat percakapan
     * @return array Response dari OllamaService
     */
    protected function sendToAI(string $cleanedMessage, string $processedContext, array $conversationHistory): array
    {
        Log::info('[PBI-08] Sending to Gemma3 via OllamaService', [
            'model'            => $this->ollamaService->getModel(),
            'context_length'   => mb_strlen($processedContext),
            'history_count'    => count($conversationHistory),
            'has_context'      => !empty($processedContext),
        ]);

        try {
            $result = $this->ollamaService->chat(
                $cleanedMessage,
                $processedContext,
                $conversationHistory
            );

            return $result;

        } catch (\Exception $e) {
            Log::error('[PBI-08] OllamaService threw exception', [
                'error'   => $e->getMessage(),
                'model'   => $this->ollamaService->getModel(),
            ]);

            throw $e;
        }
    }

    /**
     * Post-processing response dari AI.
     *
     * - Validasi response tidak kosong
     * - Sanitasi konten (hapus karakter aneh)
     * - Deteksi dan hapus duplikasi kalimat/paragraf
     * - Pastikan format response konsisten
     *
     * @param array $aiResult Response mentah dari OllamaService
     * @return array Response yang sudah di-post-process
     */
    public function postProcessResponse(array $aiResult): array
    {
        // Jika AI gagal, langsung kembalikan fallback
        if (!$aiResult['success']) {
            return $this->getSafeFallbackResponse($aiResult['message'] ?? 'AI tidak merespons');
        }

        $message = $aiResult['message'] ?? '';

        // Validasi response tidak terlalu pendek
        if (mb_strlen(trim($message)) < $this->minResponseLength) {
            Log::warning('[PBI-08] AI response too short, using fallback', [
                'response_length' => mb_strlen($message),
                'min_required'    => $this->minResponseLength,
            ]);

            return $this->getSafeFallbackResponse('Response AI terlalu pendek');
        }

        // Sanitasi: hapus karakter kontrol yang tidak diinginkan
        $message = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $message);

        // Anti-duplicate: deteksi kalimat yang berulang di awal dan akhir
        $message = $this->removeDuplicateSegments($message);

        // Trim whitespace
        $message = trim($message);

        return [
            'success' => true,
            'message' => $message,
            'model'   => $aiResult['model'] ?? config('ollama.model'),
            'pasal'   => $aiResult['pasal'] ?? null,
            'sanksi'  => $aiResult['sanksi'] ?? null,
        ];
    }

    /**
     * Deteksi dan hapus segmen teks yang terduplikasi di response AI.
     *
     * Gemma3 kadang mengulang kalimat atau paragraf yang sama.
     * Method ini mendeteksi pola duplikasi dan hanya menyimpan yang pertama.
     *
     * @param string $text Teks response AI
     * @return string Teks tanpa duplikasi
     */
    protected function removeDuplicateSegments(string $text): string
    {
        // Split by double newline (paragraf)
        $paragraphs = preg_split('/\n{2,}/', trim($text));

        if (count($paragraphs) <= 1) {
            // Cek duplikasi di level kalimat
            return $this->removeDuplicateSentences($text);
        }

        $seen = [];
        $unique = [];

        foreach ($paragraphs as $paragraph) {
            $normalized = mb_strtolower(trim($paragraph));

            if (empty($normalized)) {
                continue;
            }

            // Cek apakah paragraf sudah pernah muncul (exact atau mirip)
            $isDuplicate = false;
            foreach ($seen as $seenText) {
                if ($normalized === $seenText) {
                    $isDuplicate = true;
                    break;
                }
                // Similarity check untuk paragraf yang hampir sama
                if (mb_strlen($normalized) > 20 && mb_strlen($seenText) > 20) {
                    similar_text($normalized, $seenText, $percent);
                    if ($percent > 80) {
                        $isDuplicate = true;
                        break;
                    }
                }
            }

            if (!$isDuplicate) {
                $seen[] = $normalized;
                $unique[] = trim($paragraph);
            }
        }

        return implode("\n\n", $unique);
    }

    /**
     * Hapus kalimat duplikat dalam satu paragraf.
     *
     * @param string $text Teks yang mungkin mengandung kalimat duplikat
     * @return string Teks tanpa kalimat duplikat
     */
    protected function removeDuplicateSentences(string $text): string
    {
        $sentences = preg_split('/(?<=[.!?])\s+/', trim($text));

        if (count($sentences) <= 1) {
            return $text;
        }

        $seen = [];
        $unique = [];

        foreach ($sentences as $sentence) {
            $normalized = mb_strtolower(trim($sentence));

            if (empty($normalized)) {
                continue;
            }

            if (!in_array($normalized, $seen)) {
                $seen[] = $normalized;
                $unique[] = trim($sentence);
            }
        }

        return implode(' ', $unique);
    }

    /**
     * Handle error saat pemrosesan AI gagal.
     *
     * Mencatat error secara detail ke log dan mengembalikan
     * fallback response yang aman untuk ditampilkan ke user.
     *
     * @param \Exception  $exception   Exception yang terjadi
     * @param ChatSession $session     Sesi chat
     * @param int         $processingTime Waktu pemrosesan dalam millisecond
     * @return array Fallback response yang aman
     */
    public function handleProcessingError(\Exception $exception, ChatSession $session, int $processingTime = 0): array
    {
        Log::error('[PBI-08] AI processing failed with exception', [
            'session_id'     => $session->session_id,
            'error_class'    => get_class($exception),
            'error_message'  => $exception->getMessage(),
            'error_file'     => $exception->getFile(),
            'error_line'     => $exception->getLine(),
            'processing_ms'  => $processingTime,
        ]);

        $fallback = $this->getSafeFallbackResponse($exception->getMessage());
        $fallback['processing_info'] = [
            'processing_time_ms' => $processingTime,
            'had_error'          => true,
            'error_type'         => get_class($exception),
        ];

        return $fallback;
    }

    /**
     * Response fallback yang aman ketika AI tidak dapat memproses.
     *
     * Dikembalikan ketika:
     * - OllamaService gagal total (primary + fallback model)
     * - Exception tidak tertangani
     * - Response AI terlalu pendek/tidak valid
     *
     * @param string $errorDetail Detail error untuk logging (tidak ditampilkan ke user)
     * @return array Response fallback yang aman
     */
    public function getSafeFallbackResponse(string $errorDetail = ''): array
    {
        if (!empty($errorDetail)) {
            Log::warning('[PBI-08] Using safe fallback response', [
                'reason' => $errorDetail,
            ]);
        }

        return [
            'success' => false,
            'message' => 'Mohon maaf, Lentra AI sedang tidak dapat memproses pertanyaan Anda saat ini. '
                       . 'Silakan coba beberapa saat lagi atau pastikan layanan AI sedang berjalan.',
            'model'   => 'fallback',
            'pasal'   => null,
            'sanksi'  => null,
        ];
    }

    /**
     * Format response final dengan informasi processing.
     *
     * Menambahkan metadata processing ke response agar
     * frontend/logging bisa mengetahui detail pemrosesan.
     *
     * @param array $processedResult Response yang sudah di-post-process
     * @param int   $processingTime  Waktu pemrosesan dalam millisecond
     * @return array Response terformat lengkap
     */
    public function formatResponse(array $processedResult, int $processingTime = 0): array
    {
        return array_merge($processedResult, [
            'processing_info' => [
                'processing_time_ms' => $processingTime,
                'had_error'          => !$processedResult['success'],
                'model_used'         => $processedResult['model'] ?? 'unknown',
            ],
        ]);
    }
}
