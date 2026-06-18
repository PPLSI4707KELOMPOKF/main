<?php

namespace App\Http\Controllers;

use App\Models\ChatSession;
use App\Models\ChatMessage;
use App\Http\Requests\SendMessageRequest;
use App\Services\OllamaService;
use App\Services\ContextBuilderService;
use App\Services\ApiResponseFormatter;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;

class ChatController extends Controller
{
    protected OllamaService $ollamaService;
    protected ContextBuilderService $contextBuilder;

    public function __construct(OllamaService $ollamaService, ContextBuilderService $contextBuilder)
    {
        $this->ollamaService  = $ollamaService;
        $this->contextBuilder = $contextBuilder;
    }

    /**
     * Display the main chat interface (PBI-1).
     */
    public function index(Request $request)
    {
        $sessionId = $request->session()->get('chat_session_id');

        if (!$sessionId) {
            $sessionId = Str::uuid()->toString();
            $request->session()->put('chat_session_id', $sessionId);
        }

        $chatSession = null;
        $messages = collect();
        $allSessions = collect();

        if (Auth::check()) {
            $chatSession = ChatSession::where('session_id', $sessionId)
                ->where('user_id', Auth::id())
                ->first();

            if (!$chatSession) {
                $sessionId = Str::uuid()->toString();
                $request->session()->put('chat_session_id', $sessionId);
            }

            $messages = $chatSession?->messages()->get() ?? collect();

            $allSessions = ChatSession::where('user_id', Auth::id())
                ->whereHas('messages')
                ->with('messages')
                ->orderBy('updated_at', 'desc')
                ->limit(20)
                ->get();
        }

        return view('chat.index', compact('chatSession', 'messages', 'allSessions', 'sessionId'));
    }

    /**
     * Validasi input pertanyaan pengguna secara realtime (PBI-2 & PBI-3).
     */
    public function validateInput(Request $request)
    {
        $request->validate([
            'message' => 'required|string|min:2|max:2000',
        ]);

        $message = trim($request->input('message'));
        $length  = mb_strlen($message);

        $preprocessor = app(\App\Services\InputPreprocessor::class);
        $relevanceData = $preprocessor->checkRelevance($message);

        $isRelevant   = $relevanceData['is_relevant'];
        $matchedWords = $relevanceData['matched_words'];

        return response()->json([
            'valid'         => true,
            'length'        => $length,
            'max_length'    => 2000,
            'is_relevant'   => $isRelevant,
            'matched_words' => $matchedWords,
            'hint'          => $isRelevant
                ? 'Pertanyaan terdeteksi relevan dengan hukum lalu lintas.'
                : 'Coba sertakan kata kunci seperti: helm, tilang, SIM, STNK, parkir, dll.',
        ]);
    }

    /**
     * Terima pertanyaan pengguna, validasi via SendMessageRequest (PBI-2),
     * lalu kirim ke OllamaService dan simpan ke database.
     */
    public function sendMessage(SendMessageRequest $request)
    {
        // Naikkan batas waktu PHP untuk request AI (Ollama butuh 30-60 detik)
        set_time_limit(300);
        ini_set('max_execution_time', '300');

        try {
            $sessionId   = $request->input('session_id');
            $userMessage = $request->input('message');
            $isAuthenticated = Auth::check();

            $chatSession = null;
            $userMsg = null;

            if ($isAuthenticated) {
                // Get or create an owned chat session.
                $chatSession = $this->getOrCreateOwnedSession($request, $sessionId);
                $sessionId = $chatSession->session_id;

                // Update title if first message.
                if ($chatSession->messages()->count() === 0) {
                    $title = Str::limit($userMessage, 40);
                    $chatSession->update(['title' => $title]);
                }

                // Save user message for authenticated users only.
                $userMsg = ChatMessage::create([
                    'chat_session_id' => $chatSession->id,
                    'role' => 'user',
                    'content' => $userMessage,
                ]);
            }

            Log::info('[PBI-2] User question received', [
                'session_id'  => $sessionId,
                'user_id'     => Auth::id(),
                'char_count'  => mb_strlen($userMessage),
                'word_count'  => str_word_count($userMessage),
            ]);

            // ========================================
            // PIPELINE: PBI-3 → PBI-4 → PBI-6 → PBI-7 → OllamaService
            // ========================================
            $aiResponse = $this->processWithAI($userMessage, $chatSession);

            $assistantMsg = null;

            if ($chatSession) {
                // Save AI response for authenticated users only.
                $assistantMsg = ChatMessage::create([
                    'chat_session_id' => $chatSession->id,
                    'role' => 'assistant',
                    'content' => $aiResponse['message'],
                    'pasal' => $aiResponse['pasal'] ?? null,
                    'sanksi' => $aiResponse['sanksi'] ?? null,
                    'legal_references' => $aiResponse['references'] ?? [],
                ]);

                $chatSession->touch();
            }

            $modelName = $aiResponse['model'] ?? config('ollama.model');

            $formatted = ApiResponseFormatter::formatSuccess(
                $aiResponse['message'],
                $modelName,
                [
                    'user_message' => [
                        'id' => $userMsg?->id,
                        'role' => 'user',
                        'content' => $userMsg?->content ?? $userMessage,
                        'time' => $userMsg?->created_at?->format('H:i') ?? now()->format('H:i'),
                    ],
                    'assistant_message' => [
                        'id' => $assistantMsg?->id,
                        'role' => 'assistant',
                        'content' => $assistantMsg?->content ?? $aiResponse['message'],
                        'pasal' => $assistantMsg?->pasal ?? ($aiResponse['pasal'] ?? null),
                        'sanksi' => $assistantMsg?->sanksi ?? ($aiResponse['sanksi'] ?? null),
                        'references' => $assistantMsg?->legal_references ?? ($aiResponse['references'] ?? []),
                        'time' => $assistantMsg?->created_at?->format('H:i') ?? now()->format('H:i'),
                    ],
                    'session_title' => $chatSession?->title ?? 'Percakapan Sementara',
                    'session_id' => $sessionId,
                    'persisted' => $isAuthenticated,
                ]
            );

            return response()->json($formatted);

        } catch (\Exception $e) {
            Log::error('[PBI-10] Error in sendMessage endpoint', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $errorResponse = ApiResponseFormatter::formatError(
                'AI sedang tidak tersedia',
                $e->getMessage()
            );

            return response()->json($errorResponse, 500);
        }
    }


    /**
     * Stream jawaban AI ke browser via Server-Sent Events (SSE).
     * Token langsung muncul di browser — tidak ada timeout.
     */
    public function streamMessage(Request $request)
    {
        set_time_limit(0);
        ini_set('max_execution_time', '0');

        $request->validate([
            'session_id' => 'required|string',
            'message'    => 'required|string|min:2|max:2000',
        ]);

        $sessionId   = $request->input('session_id');
        $userMessage = $request->input('message');
        $isAuthenticated = Auth::check();

        $chatSession = null;
        $userMsg = null;
        $sessionTitle = 'Percakapan Sementara';

        if ($isAuthenticated) {
            $chatSession = $this->getOrCreateOwnedSession($request, $sessionId);
            $sessionId = $chatSession->session_id;

            if ($chatSession->messages()->count() === 0) {
                $chatSession->update(['title' => Str::limit($userMessage, 40)]);
            }

            $sessionTitle = $chatSession->title;

            $userMsg = ChatMessage::create([
                'chat_session_id' => $chatSession->id,
                'role'            => 'user',
                'content'         => $userMessage,
            ]);
        }

        $preprocessor     = app(\App\Services\InputPreprocessor::class);
        $cleanedMessage   = $preprocessor->clean($userMessage);

        // GUARD: Tolak pertanyaan di luar topik lalu lintas
        $relevanceData = $preprocessor->checkRelevance($cleanedMessage);
        if (!$relevanceData['is_relevant']) {
            $rejectMsg = 'Maaf, saya hanya dapat membantu pertanyaan seputar hukum lalu lintas Indonesia. Silakan tanyakan tentang SIM, STNK, helm, tilang, rambu, parkir, atau aturan berkendara.';
            $assistantMsg = null;
            if ($chatSession) {
                $assistantMsg = ChatMessage::create([
                    'chat_session_id' => $chatSession->id,
                    'role'            => 'assistant',
                    'content'         => $rejectMsg,
                    'pasal'           => null,
                    'sanksi'          => null,
                    'legal_references' => [],
                ]);
                $chatSession->touch();
            }
            return response()->stream(function () use ($userMsg, $sessionId, $sessionTitle, $assistantMsg, $rejectMsg, $isAuthenticated) {
                echo "data: " . json_encode(['type' => 'user_saved', 'id' => $userMsg?->id, 'time' => $userMsg?->created_at?->format('H:i') ?? now()->format('H:i'), 'session_id' => $sessionId, 'session_title' => $sessionTitle, 'persisted' => $isAuthenticated]) . "\n\n";
                ob_flush(); flush();
                echo "data: " . json_encode(['type' => 'token', 'token' => $rejectMsg]) . "\n\n";
                ob_flush(); flush();
                echo "data: " . json_encode(['type' => 'done', 'id' => $assistantMsg?->id, 'time' => $assistantMsg?->created_at?->format('H:i') ?? now()->format('H:i'), 'pasal' => null, 'sanksi' => null, 'success' => true, 'persisted' => $isAuthenticated]) . "\n\n";
                ob_flush(); flush();
            }, 200, ['Content-Type' => 'text/event-stream', 'Cache-Control' => 'no-cache', 'X-Accel-Buffering' => 'no']);
        }

        $embeddingService = app(\App\Services\EmbeddingService::class);
        $embedding        = $embeddingService->generateEmbedding($cleanedMessage);

        $relevantDocs = [];
        if ($embedding) {
            $topK = config('rag.top_k', 3);
            $vectorDb = app(\App\Services\VectorDatabaseService::class);
            $relevantDocs = $vectorDb->search($embedding, $topK);
        } else {
            $topK = config('rag.top_k', 3);
            $vectorDb = app(\App\Services\VectorDatabaseService::class);
            $relevantDocs = $vectorDb->searchByText($cleanedMessage, $topK);
        }

        $context             = $this->contextBuilder->build($cleanedMessage, $relevantDocs, $chatSession);
        $conversationHistory = $this->contextBuilder->getConversationHistory($chatSession);
        $ollamaService       = $this->ollamaService;

        return response()->stream(function () use (
            $ollamaService, $cleanedMessage, $context, $conversationHistory,
            $chatSession, $userMsg, $sessionId, $sessionTitle, $isAuthenticated, $relevantDocs
        ) {
            echo "data: " . json_encode([
                'type'          => 'user_saved',
                'id'            => $userMsg?->id,
                'time'          => $userMsg?->created_at?->format('H:i') ?? now()->format('H:i'),
                'session_id'    => $sessionId,
                'session_title' => $sessionTitle,
                'persisted'     => $isAuthenticated,
            ]) . "\n\n";
            ob_flush(); flush();

            $fullContent = '';

            $ollamaService->streamChat(
                $cleanedMessage,
                $context,
                $conversationHistory,
                function (string $token) use (&$fullContent) {
                    $fullContent .= $token;
                    echo "data: " . json_encode(['type' => 'token', 'token' => $token]) . "\n\n";
                    ob_flush(); flush();
                },
                function (string $content, bool $success) use ($chatSession, &$fullContent, $isAuthenticated, $cleanedMessage, $relevantDocs) {
                    $finalContent = $success ? $content : $this->buildOfflineAnswer($cleanedMessage, $relevantDocs, $fullContent);
                    $references = $this->extractReferences($finalContent, $relevantDocs);
                    $primary = $this->primaryReference($references);
                    $pasal = $primary['pasal'] ?? null;
                    $sanksi = $primary['sanksi'] ?? null;

                    $assistantMsg = null;
                    if ($chatSession) {
                        $assistantMsg = ChatMessage::create([
                            'chat_session_id' => $chatSession->id,
                            'role'            => 'assistant',
                            'content'         => $finalContent,
                            'pasal'           => $pasal,
                            'sanksi'          => $sanksi,
                            'legal_references' => $references,
                        ]);
                        $chatSession->touch();
                    }

                    echo "data: " . json_encode([
                        'type'    => 'done',
                        'id'      => $assistantMsg?->id,
                        'time'    => $assistantMsg?->created_at?->format('H:i') ?? now()->format('H:i'),
                        'pasal'   => $pasal,
                        'sanksi'  => $sanksi,
                        'references' => $references,
                        'success' => true,
                        'offline' => !$success,
                        'persisted' => $isAuthenticated,
                    ]) . "\n\n";
                    ob_flush(); flush();
                }
            );
        }, 200, [
            'Content-Type'      => 'text/event-stream',
            'Cache-Control'     => 'no-cache',
            'X-Accel-Buffering' => 'no',
            'Connection'        => 'keep-alive',
        ]);
    }

    /**
     * Create a new chat session.
     */
    public function newSession(Request $request)
    {
        $sessionId = Str::uuid()->toString();
        $request->session()->put('chat_session_id', $sessionId);

        return response()->json([
            'success' => true,
            'session_id' => $sessionId,
        ]);
    }

    /**
     * Switch to an existing chat session.
     */
    public function switchSession(Request $request, $sessionId)
    {
        $chatSession = ChatSession::where('session_id', $sessionId)
            ->where('user_id', Auth::id())
            ->firstOrFail();
        $request->session()->put('chat_session_id', $sessionId);

        $messages = $chatSession->messages()->get()->map(function ($msg) {
            return [
                'id' => $msg->id,
                'role' => $msg->role,
                'content' => $msg->content,
                'pasal' => $msg->pasal,
                'sanksi' => $msg->sanksi,
                'references' => $msg->legal_references ?? [],
                'time' => $msg->created_at->format('H:i'),
            ];
        });

        return response()->json([
            'success' => true,
            'session_id' => $sessionId,
            'title' => $chatSession->title,
            'messages' => $messages,
        ]);
    }

    /**
     * Get chat history list.
     */
    public function getHistory(Request $request)
    {
        $sessions = ChatSession::where('user_id', Auth::id())
            ->whereHas('messages')
            ->orderBy('updated_at', 'desc')
            ->limit(20)
            ->get()
            ->map(function ($session) {
                $lastMessage = $session->messages()
                    ->reorder('created_at', 'desc')
                    ->first();

                return [
                    'session_id' => $session->session_id,
                    'title' => $session->title,
                    'last_message' => $lastMessage?->content ?? '',
                    'updated_at' => $session->updated_at->format('d M Y'),
                ];
            });

        return response()->json(['sessions' => $sessions]);
    }

    /**
     * Cek status Ollama.
     */
    public function checkStatus()
    {
        $isAvailable = $this->ollamaService->isAvailable();
        $models = $isAvailable ? $this->ollamaService->getAvailableModels() : [];

        return response()->json([
            'success'    => $isAvailable,
            'available'  => $isAvailable,
            'models'     => $models,
            'message'    => $isAvailable
                ? 'Ollama berjalan dengan baik.'
                : 'Pastikan Ollama berjalan menggunakan command: ollama serve',
            ]);
    }

    private function getOrCreateOwnedSession(Request $request, string $sessionId): ChatSession
    {
        $chatSession = ChatSession::where('session_id', $sessionId)
            ->where('user_id', Auth::id())
            ->first();

        if ($chatSession) {
            return $chatSession;
        }

        if (ChatSession::where('session_id', $sessionId)->exists()) {
            $sessionId = Str::uuid()->toString();
            $request->session()->put('chat_session_id', $sessionId);
        }

        return ChatSession::create([
            'session_id' => $sessionId,
            'title' => 'Percakapan Baru',
            'user_id' => Auth::id(),
        ]);
    }

    /**
     * Process user message with full AI pipeline.
     *
     * PBI-3: Preprocessing input
     * PBI-4: Generate embedding
     * PBI-5/6: Pencarian & pengambilan dokumen relevan
     * PBI-7: Penyusunan context
     * OllamaService: Kirim ke AI dengan retry, fallback, anti-repeat
     */
    private function processWithAI(string $userMessage, ?ChatSession $session): array
    {
        // PBI-3: Clean and preprocess user message
        $preprocessor   = app(\App\Services\InputPreprocessor::class);
        $cleanedMessage = $preprocessor->clean($userMessage);

        // GUARD: Cek relevansi SEBELUM panggil Ollama
        // Jika pertanyaan tidak menyangkut lalu lintas → tolak langsung tanpa AI
        $relevanceData = $preprocessor->checkRelevance($cleanedMessage);
        if (!$relevanceData['is_relevant']) {
            Log::info('[Guard] Off-topic question rejected', [
                'session_id' => $session?->session_id ?? 'guest',
                'message'    => mb_substr($cleanedMessage, 0, 80),
            ]);
            return [
                'success' => true,
                'message' => 'Maaf, saya hanya dapat membantu pertanyaan seputar hukum lalu lintas Indonesia. Silakan tanyakan tentang SIM, STNK, helm, tilang, rambu, parkir, atau aturan berkendara.',
                'model'   => 'guard',
                'pasal'   => null,
                'sanksi'  => null,
                'references' => [],
            ];
        }

        // PBI-4: Generate embedding query
        $embeddingService = app(\App\Services\EmbeddingService::class);
        $embedding = $embeddingService->generateEmbedding($cleanedMessage);

        $relevantDocs = [];

        if ($embedding) {
            Log::info('[PBI-4] Successfully generated embedding in ChatController', [
                'session_id' => $session?->session_id ?? 'guest',
            ]);

            // PBI-6: Pengambilan Dokumen Relevan (Top-K)
            $topK = config('rag.top_k', 3);
            $vectorDb = app(\App\Services\VectorDatabaseService::class);
            $relevantDocs = $vectorDb->search($embedding, $topK);

            if (!empty($relevantDocs)) {
                Log::info('[PBI-6] Retrieved relevant documents', [
                    'session_id' => $session?->session_id ?? 'guest',
                    'count' => count($relevantDocs),
                    'top_score' => $relevantDocs[0]['score'],
                    'top_pasal' => $relevantDocs[0]['metadata']['pasal'] ?? 'Unknown',
                    'top_k' => $topK,
                ]);
            } else {
                Log::info('[PBI-6] No relevant documents found', [
                    'session_id' => $session?->session_id ?? 'guest',
                    'top_k' => $topK,
                ]);
            }
        } else {
            $topK = config('rag.top_k', 3);
            $vectorDb = app(\App\Services\VectorDatabaseService::class);
            $relevantDocs = $vectorDb->searchByText($cleanedMessage, $topK);

            Log::info('[PBI-13] Embedding unavailable, using local text retrieval', [
                'session_id' => $session?->session_id ?? 'guest',
                'count' => count($relevantDocs),
                'top_k' => $topK,
            ]);
        }

        // PBI-7: Penyusunan Context — RAG documents
        $context = $this->contextBuilder->build($cleanedMessage, $relevantDocs, $session);

        // Conversation history untuk OllamaService messages format
        $conversationHistory = $this->contextBuilder->getConversationHistory($session);

        // Kirim ke OllamaService (retry otomatis + fallback model + anti-repeat)
        $result = $this->ollamaService->chat($cleanedMessage, $context, $conversationHistory);

        Log::info('[OllamaService] Final response', [
            'session_id' => $session?->session_id ?? 'guest',
            'success'    => $result['success'],
            'model'      => $result['model'],
        ]);

        // Jika AI gagal total, gunakan simulated response sebagai safety net
        if (!$result['success']) {
            Log::warning('[OllamaService] Using simulated fallback response', [
                'session_id' => $session?->session_id ?? 'guest',
            ]);
            $simulated = $this->getSimulatedResponse($userMessage);
            return [
                'success' => true,
                'message' => $this->buildOfflineAnswer($cleanedMessage, $relevantDocs, $simulated['content']),
                'model'   => !empty($relevantDocs) ? 'offline-rag' : 'simulated',
                'pasal'   => $this->primaryReference($this->extractReferences($simulated['content'], $relevantDocs))['pasal'] ?? $simulated['pasal'],
                'sanksi'  => $this->primaryReference($this->extractReferences($simulated['content'], $relevantDocs))['sanksi'] ?? $simulated['sanksi'],
                'references' => $this->extractReferences($simulated['content'], $relevantDocs),
            ];
        }

        $references = $this->extractReferences($result['message'] ?? '', $relevantDocs);
        $primary = $this->primaryReference($references);

        return array_merge($result, [
            'pasal' => $result['pasal'] ?? ($primary['pasal'] ?? null),
            'sanksi' => $result['sanksi'] ?? ($primary['sanksi'] ?? null),
            'references' => $references,
        ]);
    }

    private function extractReferences(string $answer, array $relevantDocs = []): array
    {
        $references = [];

        $answerPasal = $this->extractPasalFromText($answer);
        $answerSanksi = $this->extractSanksiFromText($answer);

        foreach ($relevantDocs as $doc) {
            $metadata = $doc['metadata'] ?? [];
            $pasal = $metadata['pasal'] ?? null;
            $source = $metadata['source'] ?? 'UU No. 22 Tahun 2009';
            $title = $metadata['title'] ?? null;
            $topic = $metadata['topic'] ?? ($metadata['topik'] ?? null);
            $chunkUid = $metadata['chunk_uid'] ?? ($doc['id'] ?? null);

            if (!$pasal && !$source && !$title && !$topic) {
                continue;
            }

            $references[] = [
                'pasal' => $pasal,
                'sanksi' => $answerSanksi,
                'source' => $source,
                'title' => $title,
                'topic' => $topic,
                'chunk_uid' => $chunkUid,
                'score' => isset($doc['score']) ? round((float) $doc['score'], 4) : null,
            ];
        }

        if (empty($references) && ($answerPasal || $answerSanksi)) {
            $references[] = [
                'pasal' => $answerPasal,
                'sanksi' => $answerSanksi,
                'source' => 'UU No. 22 Tahun 2009',
                'title' => null,
                'topic' => null,
                'chunk_uid' => null,
                'score' => null,
            ];
        }

        if (!empty($references) && $answerPasal && empty($references[0]['pasal'])) {
            $references[0]['pasal'] = $answerPasal;
        }

        return array_values($references);
    }

    private function primaryReference(array $references): array
    {
        return $references[0] ?? [];
    }

    private function buildOfflineAnswer(string $userMessage, array $relevantDocs, string $fallbackContent = ''): string
    {
        if (empty($relevantDocs)) {
            return $fallbackContent ?: $this->getSimulatedResponse($userMessage)['content'];
        }

        $lines = [
            'Mode offline aktif. Berdasarkan dokumen regulasi lokal yang paling relevan, berikut ringkasan jawabannya:',
        ];

        foreach (array_slice($relevantDocs, 0, 2) as $index => $doc) {
            $metadata = $doc['metadata'] ?? [];
            $pasal = $metadata['pasal'] ?? 'Referensi regulasi';
            $source = $metadata['source'] ?? 'UU No. 22 Tahun 2009';
            $title = $metadata['title'] ?? ($metadata['topic'] ?? ($metadata['topik'] ?? 'Hukum lalu lintas'));
            $content = trim((string) ($doc['content'] ?? ''));
            $excerpt = Str::limit(preg_replace('/\s+/', ' ', $content), 260);

            $lines[] = ($index + 1) . ". {$pasal} - {$title} ({$source}): {$excerpt}";
        }

        $lines[] = 'Gunakan jawaban ini sebagai informasi awal. Untuk keputusan hukum resmi, ikuti prosedur kepolisian/pengadilan atau konsultasikan kepada ahli.';

        return implode("\n\n", $lines);
    }

    private function extractPasalFromText(string $text): ?string
    {
        if (preg_match('/[Pp]asal\s+(\d+[A-Za-z]?(?:\s*(?:ayat|huruf)\s*\([^)]+\))?)/u', $text, $matches)) {
            return 'Pasal ' . $matches[1];
        }

        return null;
    }

    private function extractSanksiFromText(string $text): ?string
    {
        if (preg_match('/(?:pidana penjara|pidana kurungan|dipidana|pidana|denda|kurungan).*?\.(?:\s|$)/ui', $text, $matches)) {
            return ucfirst(trim($matches[0]));
        }

        return null;
    }

    /**
     * Get simulated response when AI is not available (safety net).
     */
    private function getSimulatedResponse(string $userMessage): array
    {
        $lowerMsg = strtolower($userMessage);

        if (str_contains($lowerMsg, 'helm')) {
            return [
                'content' => 'Berdasarkan UU No. 22 Tahun 2009 tentang Lalu Lintas dan Angkutan Jalan, setiap pengemudi dan penumpang sepeda motor wajib menggunakan helm yang memenuhi Standar Nasional Indonesia (SNI). Kewajiban ini diatur dalam Pasal 57 ayat (1) UU LLAJ.',
                'pasal' => 'Pasal 57 ayat (1) UU LLAJ',
                'sanksi' => 'Pidana kurungan paling lama 1 bulan atau denda paling banyak Rp 250.000',
            ];
        }

        if (str_contains($lowerMsg, 'tilang') || str_contains($lowerMsg, 'tilangan')) {
            return [
                'content' => 'Tilang (bukti pelanggaran) adalah mekanisme penegakan hukum lalu lintas. Berdasarkan UU LLAJ, pengemudi yang melanggar aturan dapat dikenakan tilang oleh petugas kepolisian. Proses tilang dapat diselesaikan melalui sidang tilang atau pembayaran denda.',
                'pasal' => 'Pasal 267 UU LLAJ',
                'sanksi' => 'Denda sesuai jenis pelanggaran yang dilakukan',
            ];
        }

        if (str_contains($lowerMsg, 'stnk')) {
            return [
                'content' => 'STNK (Surat Tanda Nomor Kendaraan) adalah bukti registrasi kendaraan bermotor yang wajib dimiliki setiap pengemudi. Berdasarkan UU No. 22 Tahun 2009 Pasal 68, setiap kendaraan bermotor wajib memiliki STNK yang diterbitkan oleh Kepolisian Negara.',
                'pasal' => 'Pasal 68 UU No. 22 Tahun 2009',
                'sanksi' => 'Pidana kurungan paling lama 2 bulan atau denda paling banyak Rp 500.000',
            ];
        }

        if (str_contains($lowerMsg, 'sim') || str_contains($lowerMsg, 'surat izin')) {
            return [
                'content' => 'SIM (Surat Izin Mengemudi) wajib dimiliki oleh setiap orang yang mengemudikan kendaraan bermotor di jalan. Berdasarkan Pasal 77 UU No. 22 Tahun 2009, setiap pengemudi wajib memiliki SIM sesuai jenis kendaraan yang dikemudikan.',
                'pasal' => 'Pasal 77 UU No. 22 Tahun 2009',
                'sanksi' => 'Pidana kurungan paling lama 4 bulan atau denda paling banyak Rp 1.000.000',
            ];
        }

        if (str_contains($lowerMsg, 'parkir')) {
            return [
                'content' => 'Aturan parkir diatur dalam UU No. 22 Tahun 2009. Pengemudi dilarang memarkir kendaraan di tempat-tempat tertentu yang dapat mengganggu ketertiban dan kelancaran lalu lintas, seperti di trotoar, persimpangan, atau tempat yang bertanda larangan parkir.',
                'pasal' => 'Pasal 120 UU No. 22 Tahun 2009',
                'sanksi' => 'Denda paling banyak Rp 500.000',
            ];
        }

        if (str_contains($lowerMsg, 'kecelakaan')) {
            return [
                'content' => 'Penanganan kecelakaan lalu lintas diatur dalam UU No. 22 Tahun 2009 Pasal 229-236. Setiap pengemudi yang terlibat kecelakaan wajib menghentikan kendaraan, menolong korban, dan melaporkan kepada pihak kepolisian.',
                'pasal' => 'Pasal 229-236 UU No. 22 Tahun 2009',
                'sanksi' => 'Pidana penjara paling lama 3 tahun atau denda paling banyak Rp 75.000.000',
            ];
        }

        if (str_contains($lowerMsg, 'lampu merah') || str_contains($lowerMsg, 'rambu')) {
            return [
                'content' => 'Setiap pengemudi wajib mematuhi rambu-rambu lalu lintas, marka jalan, dan alat pemberi isyarat lalu lintas. Melanggar lampu merah diatur dalam Pasal 287 UU No. 22 Tahun 2009.',
                'pasal' => 'Pasal 287 UU No. 22 Tahun 2009',
                'sanksi' => 'Pidana kurungan paling lama 2 bulan atau denda paling banyak Rp 500.000',
            ];
        }

        return [
            'content' => 'Mohon maaf, saat ini Lentra AI sedang dalam mode offline. Silakan pastikan Ollama berjalan dengan command: ollama serve. Atau tanyakan tentang helm, tilang, STNK, SIM, parkir, kecelakaan, atau lampu merah untuk mendapatkan informasi hukum lalu lintas.',
            'pasal' => null,
            'sanksi' => null,
        ];
    }
}
