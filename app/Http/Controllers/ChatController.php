<?php

namespace App\Http\Controllers;

use App\Models\ChatSession;
use App\Models\ChatMessage;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Http;

class ChatController extends Controller
{
    /**
     * Display the main chat interface (PBI-1).
     */
    public function index(Request $request)
    {
        // Get or create a session ID for guest users
        $sessionId = $request->session()->get('chat_session_id');

        if (!$sessionId) {
            $sessionId = Str::uuid()->toString();
            $request->session()->put('chat_session_id', $sessionId);
        }

        // Get or create the chat session
        $chatSession = ChatSession::firstOrCreate(
            ['session_id' => $sessionId],
            ['title' => 'Percakapan Baru', 'user_id' => null]
        );

        // Load all messages for this session
        $messages = $chatSession->messages()->get();

        // Get all sessions for history sidebar
        $allSessions = ChatSession::where('session_id', $sessionId)
            ->orWhereNull('user_id')
            ->with('messages')
            ->orderBy('updated_at', 'desc')
            ->limit(20)
            ->get();

        return view('chat.index', compact('chatSession', 'messages', 'allSessions', 'sessionId'));
    }

    /**
     * Send a message and get AI response (PBI-1 Core).
     */
    public function sendMessage(Request $request)
    {
        $request->validate([
            'message' => 'required|string|max:2000',
            'session_id' => 'required|string',
        ]);

        $sessionId = $request->input('session_id');
        $userMessage = trim($request->input('message'));

        // Get or create chat session
        $chatSession = ChatSession::firstOrCreate(
            ['session_id' => $sessionId],
            ['title' => 'Percakapan Baru', 'user_id' => null]
        );

        // Update title if this is the first message
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

        // Process with AI (PBI-1: basic chatbot interface)
        $aiResponse = $this->processWithAI($userMessage, $chatSession);

        // Save AI response
        $assistantMsg = ChatMessage::create([
            'chat_session_id' => $chatSession->id,
            'role' => 'assistant',
            'content' => $aiResponse['content'],
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
     * Process user message with AI.
     * PBI-1: Basic chatbot interface that sends question to backend and receives response.
     * Future PBIs will add RAG, embeddings, and Gemma 3 integration.
     */
    private function processWithAI(string $userMessage, ChatSession $session): array
    {
        // PBI-1: Basic response system - interface scaffolding
        // The actual Gemma 3 + RAG pipeline will be added in PBI-2 through PBI-10
        
        // Try to connect to Ollama if available
        try {
            $ollamaUrl = env('OLLAMA_URL', 'http://localhost:11434');
            $response = Http::timeout(30)->post("{$ollamaUrl}/api/generate", [
                'model' => env('OLLAMA_MODEL', 'gemma3'),
                'prompt' => $this->buildPrompt($userMessage, $session),
                'stream' => false,
            ]);

            if ($response->successful()) {
                $data = $response->json();
                $content = $data['response'] ?? '';
                
                // Parse pasal and sanksi from response
                $pasal = $this->extractPasal($content);
                $sanksi = $this->extractSanksi($content);

                return [
                    'content' => $content,
                    'pasal' => $pasal,
                    'sanksi' => $sanksi,
                ];
            }
        } catch (\Exception $e) {
            // Ollama not available, use simulated response
        }

        // Simulated response for PBI-1 (interface demo)
        return $this->getSimulatedResponse($userMessage);
    }

    /**
     * Build prompt for AI model.
     */
    private function buildPrompt(string $userMessage, ChatSession $session): string
    {
        $context = "Anda adalah LENTRA AI, asisten hukum lalu lintas Indonesia yang cerdas dan membantu. " .
            "Anda ahli dalam UU No. 22 Tahun 2009 tentang Lalu Lintas dan Angkutan Jalan. " .
            "Berikan jawaban yang akurat, informatif, dan mudah dipahami. " .
            "Selalu sebutkan pasal yang relevan dan sanksi yang berlaku jika ada.\n\n";

        // Add conversation history
        $history = $session->messages()->orderBy('created_at', 'desc')->limit(6)->get()->reverse();
        foreach ($history as $msg) {
            $role = $msg->role === 'user' ? 'User' : 'LENTRA AI';
            $context .= "{$role}: {$msg->content}\n";
        }

        $context .= "\nUser: {$userMessage}\nLENTRA AI:";
        return $context;
    }

    /**
     * Extract pasal reference from AI response.
     */
    private function extractPasal(string $content): ?string
    {
        if (preg_match('/[Pp]asal\s+(\d+[A-Za-z]?(?:\s*(?:ayat|huruf)\s*\([^)]+\))?)/u', $content, $matches)) {
            return 'Pasal ' . $matches[1];
        }
        return null;
    }

    /**
     * Extract sanksi/penalty from AI response.
     */
    private function extractSanksi(string $content): ?string
    {
        if (preg_match('/(?:pidana penjara|denda|kurungan)[^.]+\./u', $content, $matches)) {
            return ucfirst(trim($matches[0]));
        }
        return null;
    }

    /**
     * Get simulated response when AI is not available (PBI-1 demo mode).
     */
    private function getSimulatedResponse(string $userMessage): array
    {
        $lowerMsg = strtolower($userMessage);

        // Keyword-based responses for common traffic law questions
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
                'content' => 'Penanganan kecelakaan lalu lintas diatur dalam UU No. 22 Tahun 2009 Pasal 229-236. Setiap pengemudi yang terlibat kecelakaan wajib menghentikan kendaraan, menolong korban, dan melaporkan kepada pihak kepolisian. Meninggalkan korban kecelakaan dapat dikenakan sanksi pidana.',
                'pasal' => 'Pasal 229-236 UU No. 22 Tahun 2009',
                'sanksi' => 'Pidana penjara paling lama 3 tahun atau denda paling banyak Rp 75.000.000',
            ];
        }

        if (str_contains($lowerMsg, 'lampu merah') || str_contains($lowerMsg, 'rambu')) {
            return [
                'content' => 'Setiap pengemudi wajib mematuhi rambu-rambu lalu lintas, marka jalan, dan alat pemberi isyarat lalu lintas (lampu lalu lintas). Melanggar lampu merah diatur dalam Pasal 287 UU No. 22 Tahun 2009 dan dapat dikenakan sanksi.',
                'pasal' => 'Pasal 287 UU No. 22 Tahun 2009',
                'sanksi' => 'Pidana kurungan paling lama 2 bulan atau denda paling banyak Rp 500.000',
            ];
        }

        // Default response
        return [
            'content' => 'Ini adalah simulasi jawaban dari LENTRA AI. Jawaban yang sebenarnya akan merujuk pada undang-undang lalu lintas yang berlaku, khususnya UU No. 22 Tahun 2009 tentang Lalu Lintas dan Angkutan Jalan. Silakan tanyakan tentang helm, tilang, STNK, SIM, parkir, kecelakaan, atau lampu merah untuk mendapatkan informasi hukum yang lebih spesifik.',
            'pasal' => 'Pasal Simulasi',
            'sanksi' => null,
        ];
    }
}
