<?php

namespace App\Services;

use App\Models\ChatSession;
use Illuminate\Support\Facades\Log;

/**
 * ContextBuilderService — Menyusun context/prompt untuk AI (PBI-7).
 *
 * Menggabungkan:
 * 1. Dokumen regulasi relevan dari RAG (PBI-5/6)
 * 2. Pertanyaan pengguna yang sudah dibersihkan (PBI-3)
 *
 * System prompt dikelola oleh OllamaService melalui config/ollama.php.
 */
class ContextBuilderService
{
    /**
     * Bangun context yang menggabungkan dokumen hukum relevan
     * dengan informasi pertanyaan pengguna.
     *
     * @param string $userMessage Pertanyaan pengguna yang sudah dibersihkan
     * @param array $relevantDocs Dokumen relevan hasil pencarian RAG (PBI-6)
     * @param ChatSession $session Sesi chat untuk riwayat percakapan
     * @return string Context yang akan diinjeksi ke system prompt
     */
    public function build(string $userMessage, array $relevantDocs, ChatSession $session): string
    {
        $parts = [];

        // 1. Dokumen regulasi relevan (dari PBI-5/PBI-6)
        $docsSection = $this->buildDocumentsSection($relevantDocs);
        if ($docsSection !== '') {
            $parts[] = $docsSection;
        }

        $context = implode("\n\n", $parts);

        Log::info('[PBI-7] Context built successfully', [
            'session_id'       => $session->session_id,
            'context_length'   => mb_strlen($context),
            'documents_count'  => count($relevantDocs),
        ]);

        return $context;
    }

    /**
     * Ambil riwayat percakapan sebagai array messages untuk OllamaService.
     *
     * @param ChatSession $session
     * @return array
     */
    public function getConversationHistory(ChatSession $session): array
    {
        return $session->messages()
            ->reorder('created_at', 'desc')
            ->limit(6)
            ->get()
            ->reverse()
            ->map(function ($msg) {
                return [
                    'role'    => $msg->role,
                    'content' => $msg->content,
                ];
            })
            ->values()
            ->toArray();
    }

    /**
     * Bangun bagian dokumen regulasi relevan untuk context.
     */
    protected function buildDocumentsSection(array $relevantDocs): string
    {
        if (empty($relevantDocs)) {
            return '';
        }

        $lines = ["--- DOKUMEN REFERENSI HUKUM ---"];

        foreach ($relevantDocs as $index => $doc) {
            $num     = $index + 1;
            $pasal   = $doc['metadata']['pasal'] ?? 'Tidak diketahui';
            $topik   = $doc['metadata']['topik'] ?? '';
            $content = $doc['content'] ?? '';
            $score   = isset($doc['score']) ? round($doc['score'], 4) : '-';

            $lines[] = "[Dokumen {$num}] {$pasal}" . ($topik ? " — {$topik}" : '') . " (skor: {$score})";
            $lines[] = $content;
        }

        $lines[] = "--- AKHIR DOKUMEN REFERENSI ---";

        return implode("\n", $lines);
    }
}
