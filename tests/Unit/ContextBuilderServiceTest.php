<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Services\ContextBuilderService;
use App\Models\ChatSession;
use App\Models\ChatMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ContextBuilderServiceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test that context includes system prompt, relevant documents, and user question.
     */
    public function test_context_includes_system_prompt_documents_and_question(): void
    {
        $session = ChatSession::create([
            'session_id' => '11111111-1111-1111-1111-111111111111',
            'title' => 'Test Session',
        ]);

        $relevantDocs = [
            [
                'id' => 'pasal-57',
                'content' => 'Setiap pengendara sepeda motor wajib menggunakan helm SNI.',
                'metadata' => ['pasal' => 'Pasal 57', 'topik' => 'Helm'],
                'score' => 0.95,
            ],
            [
                'id' => 'pasal-281',
                'content' => 'Pengemudi tanpa SIM dipidana kurungan paling lama 4 bulan.',
                'metadata' => ['pasal' => 'Pasal 281', 'topik' => 'SIM'],
                'score' => 0.80,
            ],
        ];

        $builder = new ContextBuilderService();
        $context = $builder->build('apakah harus pakai helm?', $relevantDocs, $session);

        // System prompt
        $this->assertStringContainsString('LENTRA AI', $context);
        $this->assertStringContainsString('UU No. 22 Tahun 2009', $context);

        // Documents section
        $this->assertStringContainsString('DOKUMEN REFERENSI HUKUM', $context);
        $this->assertStringContainsString('Pasal 57', $context);
        $this->assertStringContainsString('Helm', $context);
        $this->assertStringContainsString('helm SNI', $context);
        $this->assertStringContainsString('Pasal 281', $context);
        $this->assertStringContainsString('AKHIR DOKUMEN REFERENSI', $context);

        // User question
        $this->assertStringContainsString('apakah harus pakai helm?', $context);
        $this->assertStringContainsString('LENTRA AI:', $context);
    }

    /**
     * Test that context is valid even without any relevant documents.
     */
    public function test_context_valid_without_documents(): void
    {
        $session = ChatSession::create([
            'session_id' => '22222222-2222-2222-2222-222222222222',
            'title' => 'Test Session No Docs',
        ]);

        $builder = new ContextBuilderService();
        $context = $builder->build('pertanyaan umum', [], $session);

        // System prompt still present
        $this->assertStringContainsString('LENTRA AI', $context);

        // No documents section
        $this->assertStringNotContainsString('DOKUMEN REFERENSI HUKUM', $context);

        // User question present
        $this->assertStringContainsString('pertanyaan umum', $context);
    }

    /**
     * Test that context includes conversation history.
     */
    public function test_context_includes_conversation_history(): void
    {
        $session = ChatSession::create([
            'session_id' => '33333333-3333-3333-3333-333333333333',
            'title' => 'Test Session History',
        ]);

        // Add some messages to the session
        ChatMessage::create([
            'chat_session_id' => $session->id,
            'role' => 'user',
            'content' => 'Apa itu SIM?',
        ]);

        ChatMessage::create([
            'chat_session_id' => $session->id,
            'role' => 'assistant',
            'content' => 'SIM adalah Surat Izin Mengemudi.',
        ]);

        $builder = new ContextBuilderService();
        $context = $builder->build('bagaimana cara mendapatkan SIM?', [], $session);

        // History section
        $this->assertStringContainsString('RIWAYAT PERCAKAPAN', $context);
        $this->assertStringContainsString('User: Apa itu SIM?', $context);
        $this->assertStringContainsString('LENTRA AI: SIM adalah Surat Izin Mengemudi.', $context);
        $this->assertStringContainsString('AKHIR RIWAYAT', $context);

        // New question
        $this->assertStringContainsString('bagaimana cara mendapatkan SIM?', $context);
    }

    /**
     * Test that context has correct section ordering:
     * system prompt -> documents -> history -> question.
     */
    public function test_context_sections_are_in_correct_order(): void
    {
        $session = ChatSession::create([
            'session_id' => '44444444-4444-4444-4444-444444444444',
            'title' => 'Order Test',
        ]);

        ChatMessage::create([
            'chat_session_id' => $session->id,
            'role' => 'user',
            'content' => 'Halo',
        ]);

        $relevantDocs = [
            [
                'id' => 'doc-1',
                'content' => 'Isi dokumen.',
                'metadata' => ['pasal' => 'Pasal 1'],
                'score' => 0.9,
            ],
        ];

        $builder = new ContextBuilderService();
        $context = $builder->build('pertanyaan baru', $relevantDocs, $session);

        $posSystem = mb_strpos($context, 'LENTRA AI, asisten hukum');
        $posDocs = mb_strpos($context, 'DOKUMEN REFERENSI HUKUM');
        $posHistory = mb_strpos($context, 'RIWAYAT PERCAKAPAN');
        $posQuestion = mb_strpos($context, 'Pertanyaan pengguna saat ini');

        $this->assertNotFalse($posSystem);
        $this->assertNotFalse($posDocs);
        $this->assertNotFalse($posHistory);
        $this->assertNotFalse($posQuestion);

        // Verify ordering
        $this->assertLessThan($posDocs, $posSystem, 'System prompt should come before documents');
        $this->assertLessThan($posHistory, $posDocs, 'Documents should come before history');
        $this->assertLessThan($posQuestion, $posHistory, 'History should come before question');
    }
}
