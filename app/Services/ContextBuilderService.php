<?php

namespace App\Services;

use App\Models\ChatSession;
use Illuminate\Support\Facades\Log;

class ContextBuilderService
{
    /**
     * Bangun context/prompt lengkap yang menggabungkan pertanyaan pengguna
     * dengan dokumen hukum relevan untuk dikirim ke AI (PBI-7).
     *
     * @param string $userMessage Pertanyaan pengguna yang sudah dibersihkan
     * @param array $relevantDocs Dokumen relevan hasil pencarian RAG (PBI-6)
     * @param ChatSession $session Sesi chat untuk riwayat percakapan
     * @return string Context/prompt yang siap dikirim ke AI
     */
    public function build(string $userMessage, array $relevantDocs, ChatSession $session): string
    {
        $parts = [];

        // 1. System prompt — identitas LENTRA AI
        $parts[] = $this->buildSystemPrompt();

        // 2. Dokumen regulasi relevan (dari PBI-5/PBI-6)
        $docsSection = $this->buildDocumentsSection($relevantDocs);
        if ($docsSection !== '') {
            $parts[] = $docsSection;
        }

        // 3. Riwayat percakapan
        $historySection = $this->buildHistorySection($session);
        if ($historySection !== '') {
            $parts[] = $historySection;
        }

        // 4. Pertanyaan pengguna saat ini
        $parts[] = $this->buildUserQuestionSection($userMessage);

        $context = implode("\n\n", $parts);

        // Logging PBI-7
        Log::info('[PBI-7] Context built successfully', [
            'session_id'       => $session->session_id,
            'context_length'   => mb_strlen($context),
            'documents_count'  => count($relevantDocs),
            'has_history'      => $historySection !== '',
        ]);

        return $context;
    }

    /**
     * Bangun system prompt — identitas dan instruksi dasar untuk LENTRA AI.
     */
    protected function buildSystemPrompt(): string
    {
        return "Anda adalah LENTRA AI, asisten hukum lalu lintas Indonesia yang cerdas dan membantu. " .
            "Anda ahli dalam UU No. 22 Tahun 2009 tentang Lalu Lintas dan Angkutan Jalan. " .
            "Berikan jawaban yang akurat, informatif, dan mudah dipahami. " .
            "Selalu sebutkan pasal yang relevan dan sanksi yang berlaku jika ada. " .
            "Gunakan dokumen referensi yang disediakan di bawah ini sebagai dasar jawaban Anda.";
    }

    /**
     * Bangun bagian dokumen regulasi relevan untuk context.
     *
     * @param array $relevantDocs Dokumen hasil pencarian RAG
     * @return string Bagian dokumen yang diformat, atau string kosong jika tidak ada
     */
    protected function buildDocumentsSection(array $relevantDocs): string
    {
        if (empty($relevantDocs)) {
            return '';
        }

        $lines = ["--- DOKUMEN REFERENSI HUKUM ---"];

        foreach ($relevantDocs as $index => $doc) {
            $num = $index + 1;
            $pasal = $doc['metadata']['pasal'] ?? 'Tidak diketahui';
            $topik = $doc['metadata']['topik'] ?? '';
            $content = $doc['content'] ?? '';
            $score = isset($doc['score']) ? round($doc['score'], 4) : '-';

            $lines[] = "[Dokumen {$num}] {$pasal}" . ($topik ? " — {$topik}" : '') . " (skor: {$score})";
            $lines[] = $content;
        }

        $lines[] = "--- AKHIR DOKUMEN REFERENSI ---";

        return implode("\n", $lines);
    }

    /**
     * Bangun bagian riwayat percakapan untuk context.
     *
     * @param ChatSession $session Sesi chat
     * @return string Bagian riwayat yang diformat, atau string kosong jika tidak ada
     */
    protected function buildHistorySection(ChatSession $session): string
    {
        $history = $session->messages()
            ->orderBy('created_at', 'desc')
            ->limit(6)
            ->get()
            ->reverse();

        if ($history->isEmpty()) {
            return '';
        }

        $lines = ["--- RIWAYAT PERCAKAPAN ---"];

        foreach ($history as $msg) {
            $role = $msg->role === 'user' ? 'User' : 'LENTRA AI';
            $lines[] = "{$role}: {$msg->content}";
        }

        $lines[] = "--- AKHIR RIWAYAT ---";

        return implode("\n", $lines);
    }

    /**
     * Bangun bagian pertanyaan pengguna saat ini.
     *
     * @param string $userMessage Pertanyaan pengguna
     * @return string
     */
    protected function buildUserQuestionSection(string $userMessage): string
    {
        return "Pertanyaan pengguna saat ini:\nUser: {$userMessage}\nLENTRA AI:";
    }
}
