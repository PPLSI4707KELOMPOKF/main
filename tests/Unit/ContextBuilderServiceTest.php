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
     * Test that build returns a beautifully formatted documents section when documents exist.
     */
    public function test_build_returns_formatted_documents_section_when_documents_exist(): void
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

        // Documents section markers
        $this->assertStringContainsString('DOKUMEN REFERENSI HUKUM', $context);
        $this->assertStringContainsString('Pasal 57', $context);
        $this->assertStringContainsString('Helm', $context);
        $this->assertStringContainsString('Setiap pengendara sepeda motor wajib menggunakan helm SNI.', $context);
        $this->assertStringContainsString('Pasal 281', $context);
        $this->assertStringContainsString('SIM', $context);
        $this->assertStringContainsString('Pengemudi tanpa SIM dipidana kurungan paling lama 4 bulan.', $context);
        $this->assertStringContainsString('AKHIR DOKUMEN REFERENSI', $context);
    }

    /**
     * Test that build returns an empty string when no relevant documents are provided.
     */
    public function test_build_returns_empty_string_when_no_documents(): void
    {
        $session = ChatSession::create([
            'session_id' => '22222222-2222-2222-2222-222222222222',
            'title' => 'Test Session No Docs',
        ]);

        $builder = new ContextBuilderService();
        $context = $builder->build('pertanyaan umum', [], $session);

        $this->assertSame('', $context);
    }

    /**
     * Test that getConversationHistory returns last 6 messages formatted as role/content.
     */
    public function test_get_conversation_history_returns_correct_format_and_limit(): void
    {
        $session = ChatSession::create([
            'session_id' => '33333333-3333-3333-3333-333333333333',
            'title' => 'Test Session History',
        ]);

        $baseTime = \Illuminate\Support\Carbon::now();

        // Add 8 messages to the session (4 pairs of user-assistant)
        for ($i = 1; $i <= 4; $i++) {
            \Illuminate\Support\Carbon::setTestNow($baseTime->copy()->addSeconds($i * 2));
            ChatMessage::create([
                'chat_session_id' => $session->id,
                'role' => 'user',
                'content' => "User message {$i}",
            ]);

            \Illuminate\Support\Carbon::setTestNow($baseTime->copy()->addSeconds($i * 2 + 1));
            ChatMessage::create([
                'chat_session_id' => $session->id,
                'role' => 'assistant',
                'content' => "Assistant message {$i}",
            ]);
        }

        \Illuminate\Support\Carbon::setTestNow(null); // Reset after loop

        $builder = new ContextBuilderService();
        $history = $builder->getConversationHistory($session);

        // Should return exactly the last 6 messages
        $this->assertCount(6, $history);

        // Verification of contents & order (should be chronological: from message 2 user to message 4 assistant)
        $this->assertEquals('user', $history[0]['role']);
        $this->assertEquals('User message 2', $history[0]['content']);

        $this->assertEquals('assistant', $history[1]['role']);
        $this->assertEquals('Assistant message 2', $history[1]['content']);

        $this->assertEquals('user', $history[2]['role']);
        $this->assertEquals('User message 3', $history[2]['content']);

        $this->assertEquals('assistant', $history[3]['role']);
        $this->assertEquals('Assistant message 3', $history[3]['content']);

        $this->assertEquals('user', $history[4]['role']);
        $this->assertEquals('User message 4', $history[4]['content']);

        $this->assertEquals('assistant', $history[5]['role']);
        $this->assertEquals('Assistant message 4', $history[5]['content']);
    }
}
