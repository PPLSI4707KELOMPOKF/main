<?php

namespace App\Http\Controllers;

use App\Models\ChatSession;
use App\Models\ChatMessage;
use App\Http\Requests\SendMessageRequest;
use App\Services\OllamaService;
use App\Services\ContextBuilderService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

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

        $chatSession = ChatSession::firstOrCreate(
            ['session_id' => $sessionId],
            ['title' => 'Percakapan Baru', 'user_id' => null]
        );

        $messages = $chatSession->messages()->get();

        $allSessions = ChatSession::where('session_id', $sessionId)
            ->orWhereNull('user_id')
            ->with('messages')
            ->orderBy('updated_at', 'desc')
            ->limit(20)
            ->get();

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
        $sessionId   = $request->input('session_id');
        $userMessage = $request->input('message');

        // Get or create chat session
        $chatSession = ChatSession::firstOrCreate(
            ['session_id' => $sessionId],
            ['title' => 'Percakapan Baru', 'user_id' => null]
        );

        // Update title if first message
        if ($chatSession->messages()->count() === 0) {
            $title = Str::limit($userMessage, 40);
            $chatSession->update(['title' => $title]);
        }

        // Save user message
        $userMsg = ChatMessage::create([
            'chat_session_id' => $chatSession->id,
            'role' => 'user',
            'content' => $userMessage,
        ]);

        Log::info('[PBI-2] User question received', [
            'session_id'  => $sessionId,
            'char_count'  => mb_strlen($userMessage),
            'word_count'  => str_word_count($userMessage),
        ]);

        // ========================================
        // PIPELINE: PBI-3 → PBI-4 → PBI-6 → PBI-7 → OllamaService
        // ========================================
        $aiResponse = $this->processWithAI($userMessage, $chatSession);

        // Save AI response
        $assistantMsg = ChatMessage::create([
            'chat_session_id' => $chatSession->id,
            'role' => 'assistant',
            'content' => $aiResponse['message'],
            'pasal' => $aiResponse['pasal'] ?? null,
            'sanksi' => $aiResponse['sanksi'] ?? null,
        ]);

        $chatSession->touch();

        return response()->json([
            'success' => true,
            'user_message' => [
                'id' => $userMsg->id,
                'role' => 'user',
                'content' => $userMsg->content,
                'time' => $userMsg->created_at->format('H:i'),
            ],
            'assistant_message' => [
                'id' => $assistantMsg->id,
                'role' => 'assistant',
                'content' => $assistantMsg->content,
                'pasal' => $assistantMsg->pasal,
                'sanksi' => $assistantMsg->sanksi,
                'time' => $assistantMsg->created_at->format('H:i'),
            ],
            'model' => $aiResponse['model'] ?? config('ollama.model'),
            'session_title' => $chatSession->title,
        ]);
    }

    /**
     * Create a new chat session.
     */
    public function newSession(Request $request)
    {
        $sessionId = Str::uuid()->toString();
        $request->session()->put('chat_session_id', $sessionId);

        ChatSession::create([
            'session_id' => $sessionId,
            'title' => 'Percakapan Baru',
            'user_id' => null,
        ]);

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
        $chatSession = ChatSession::where('session_id', $sessionId)->firstOrFail();
        $request->session()->put('chat_session_id', $sessionId);

        $messages = $chatSession->messages()->get()->map(function ($msg) {
            return [
                'id' => $msg->id,
                'role' => $msg->role,
                'content' => $msg->content,
                'pasal' => $msg->pasal,
                'sanksi' => $msg->sanksi,
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
        $sessions = ChatSession::whereNull('user_id')
            ->with(['messages' => function ($q) {
                $q->orderBy('created_at', 'desc')->limit(1);
            }])
            ->orderBy('updated_at', 'desc')
            ->limit(20)
            ->get()
            ->map(function ($session) {
                return [
                    'session_id' => $session->session_id,
                    'title' => $session->title,
                    'last_message' => $session->messages->first()?->content ?? '',
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

    /**
     * Process user message with full AI pipeline.
     *
     * PBI-3: Preprocessing input
     * PBI-4: Generate embedding
     * PBI-5/6: Pencarian & pengambilan dokumen relevan
     * PBI-7: Penyusunan context
     * OllamaService: Kirim ke AI dengan retry, fallback, anti-repeat
     */
    private function processWithAI(string $userMessage, ChatSession $session): array
    {
        // PBI-3: Clean and preprocess user message
        $preprocessor   = app(\App\Services\InputPreprocessor::class);
        $cleanedMessage = $preprocessor->clean($userMessage);

        // PBI-4: Generate embedding query
        $embeddingService = app(\App\Services\EmbeddingService::class);
        $embedding = $embeddingService->generateEmbedding($cleanedMessage);

        $relevantDocs = [];

        if ($embedding) {
            Log::info('[PBI-4] Successfully generated embedding in ChatController', [
                'session_id' => $session->session_id,
            ]);

            // PBI-6: Pengambilan Dokumen Relevan (Top-K)
            $topK = config('rag.top_k', 3);
            $vectorDb = app(\App\Services\VectorDatabaseService::class);
            $relevantDocs = $vectorDb->search($embedding, $topK);

            if (!empty($relevantDocs)) {
                Log::info('[PBI-6] Retrieved relevant documents', [
                    'session_id' => $session->session_id,
                    'count' => count($relevantDocs),
                    'top_score' => $relevantDocs[0]['score'],
                    'top_pasal' => $relevantDocs[0]['metadata']['pasal'] ?? 'Unknown',
                    'top_k' => $topK,
                ]);
            } else {
                Log::info('[PBI-6] No relevant documents found', [
                    'session_id' => $session->session_id,
                    'top_k' => $topK,
                ]);
            }
        }

        // PBI-7: Penyusunan Context — RAG documents
        $context = $this->contextBuilder->build($cleanedMessage, $relevantDocs, $session);

        // Conversation history untuk OllamaService messages format
        $conversationHistory = $this->contextBuilder->getConversationHistory($session);

        // Kirim ke OllamaService (retry otomatis + fallback model + anti-repeat)
        $result = $this->ollamaService->chat($cleanedMessage, $context, $conversationHistory);

        Log::info('[OllamaService] Final response', [
            'session_id' => $session->session_id,
            'success'    => $result['success'],
            'model'      => $result['model'],
        ]);

        // Jika AI gagal total, gunakan simulated response sebagai safety net
        if (!$result['success']) {
            Log::warning('[OllamaService] Using simulated fallback response', [
                'session_id' => $session->session_id,
            ]);
            $simulated = $this->getSimulatedResponse($userMessage);
            return [
                'success' => true,
                'message' => $simulated['content'],
                'model'   => 'simulated',
                'pasal'   => $simulated['pasal'],
                'sanksi'  => $simulated['sanksi'],
            ];
        }

        return $result;
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
