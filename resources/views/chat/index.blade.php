@extends('layouts.app')

@push('styles')
<style>
  /* No extra styles needed – app.css covers everything */
</style>
@endpush

@section('content')
<div class="app-shell">

  {{-- ══════════ SIDEBAR ══════════ --}}
  <aside class="sidebar">
    <div class="sidebar-logo">
      <div class="sidebar-logo-icon">⚖️</div>
      <div class="sidebar-logo-text">
        <h1>LENTRA AI</h1>
        <p>Asisten Hukum Lalu Lintas</p>
      </div>
    </div>

    <nav class="sidebar-nav">
      <a href="{{ route('chat.index') }}" class="nav-item active" id="nav-chat">
        <span class="nav-item-icon">💬</span>
        <div class="nav-item-text">
          <div class="nav-item-label">Chat AI</div>
          <div class="nav-item-sub">Tanya & dapatkan jawaban</div>
        </div>
        <span class="nav-arrow">›</span>
      </a>
      <a href="#" class="nav-item" id="nav-history" onclick="toggleHistory(event)">
        <span class="nav-item-icon">🕐</span>
        <div class="nav-item-text">
          <div class="nav-item-label">Riwayat Percakapan</div>
          <div class="nav-item-sub">Lihat riwayat tanya jawab Anda</div>
        </div>
      </a>
      <a href="#" class="nav-item">
        <span class="nav-item-icon">📋</span>
        <div class="nav-item-text">
          <div class="nav-item-label">Topik</div>
          <div class="nav-item-sub">Pilih topik hukum lalu lintas</div>
        </div>
      </a>
      <a href="#" class="nav-item">
        <span class="nav-item-icon">📘</span>
        <div class="nav-item-text">
          <div class="nav-item-label">Panduan &amp; Info</div>
          <div class="nav-item-sub">Informasi hukum lalu lintas</div>
        </div>
      </a>
      <a href="#" class="nav-item">
        <span class="nav-item-icon">🤖</span>
        <div class="nav-item-text">
          <div class="nav-item-label">Tentang LENTRA AI</div>
          <div class="nav-item-sub">Pelajari lebih lanjut</div>
        </div>
      </a>
    </nav>

    <div class="sidebar-footer">
      <div class="ai-promo-card">
        <div class="ai-promo-icon">⚖️</div>
        <div class="ai-promo-text">
          <h3>AI Hukum Lalu Lintas</h3>
          <p>Asisten cerdas untuk menjawab pertanyaan hukum lalu lintas di Indonesia.</p>
        </div>
      </div>
    </div>
  </aside>

  {{-- ══════════ MAIN CONTENT ══════════ --}}
  <main class="main-content">

    {{-- Header --}}
    <header class="chat-header">
      <div class="header-source">
        <a href="#" class="source-badge">
          <span class="source-icon">📄</span>
          Sumber: UU No. 22 Tahun 2009 tentang Lalu Lintas dan Angkutan Jalan
        </a>
      </div>
      <div class="header-actions">
        <button class="btn-history" onclick="toggleHistory(event)">
          🕐 Riwayat
        </button>
        <button class="avatar-btn" id="userAvatar" title="User">U</button>
      </div>
    </header>

    {{-- Chat Area --}}
    <div class="chat-area" id="chatArea">

      {{-- Hero (shown when no messages) --}}
      <div class="hero-section" id="heroSection" style="{{ $messages->count() > 0 ? 'display:none' : '' }}">
        <div class="hero-bg"></div>
        <div class="hero-overlay"></div>
        <div class="hero-content">
          <h2>Tanya Hukum Lalu Lintas,<br><span>Dapatkan Jawaban Akurat</span></h2>
          <p>Dapatkan referensi hukum dan jawaban akurat dari LENTRA AI.</p>
        </div>
      </div>

      {{-- Messages --}}
      <div class="messages-container" id="messagesContainer">
        @if($messages->count() === 0)
          <div class="empty-state" id="emptyState">
            <div class="empty-state-icon">⚖️</div>
            <h3>Mulai Percakapan</h3>
            <p>Tanyakan apa saja tentang hukum lalu lintas Indonesia kepada LENTRA AI.</p>
          </div>
        @else
          @foreach($messages as $msg)
            @if($msg->role === 'user')
              <div class="message-row user">
                <div class="msg-avatar user-avatar">U</div>
                <div class="msg-body">
                  <div class="msg-time">{{ $msg->created_at->format('H:i') }}</div>
                  <div class="msg-bubble user">{{ $msg->content }}</div>
                </div>
              </div>
            @else
              <div class="message-row ai">
                <div class="msg-avatar ai-avatar">✨</div>
                <div class="msg-body">
                  <div class="msg-header">
                    <span class="msg-name">LENTRA AI</span>
                    <span class="ai-badge">AI</span>
                    <span class="msg-time">{{ $msg->created_at->format('H:i') }}</span>
                  </div>
                  <div class="msg-bubble ai">{{ $msg->content }}</div>
                  @if($msg->pasal || $msg->sanksi)
                  <div class="ref-cards">
                    @if($msg->pasal)
                    <div class="ref-card pasal">
                      <div class="ref-card-icon">📋</div>
                      <div class="ref-card-content">
                        <div class="ref-card-label">Pasal</div>
                        <div class="ref-card-value">{{ $msg->pasal }}</div>
                      </div>
                    </div>
                    @endif
                    @if($msg->sanksi)
                    <div class="ref-card sanksi">
                      <div class="ref-card-icon">⚠️</div>
                      <div class="ref-card-content">
                        <div class="ref-card-label">Sanksi</div>
                        <div class="ref-card-value">{{ $msg->sanksi }}</div>
                      </div>
                    </div>
                    @endif
                  </div>
                  @endif
                </div>
              </div>
            @endif
          @endforeach
        @endif

        {{-- Typing indicator --}}
        <div class="message-row ai typing-indicator" id="typingIndicator">
          <div class="msg-avatar ai-avatar">✨</div>
          <div class="msg-body">
            <div class="msg-header">
              <span class="msg-name">LENTRA AI</span>
              <span class="ai-badge">AI</span>
            </div>
            <div class="msg-bubble ai">
              <div class="typing-dots">
                <div class="typing-dot"></div>
                <div class="typing-dot"></div>
                <div class="typing-dot"></div>
              </div>
            </div>
          </div>
        </div>
      </div>

      {{-- Topic Tags --}}
      <div class="topic-tags">
        <button class="topic-tag" onclick="sendQuickMessage('Apa aturan tilang motor?')">
          <span class="topic-tag-icon">📄</span> Tilang
        </button>
        <button class="topic-tag" onclick="sendQuickMessage('Apa kewajiban memiliki STNK?')">
          <span class="topic-tag-icon">📋</span> STNK
        </button>
        <button class="topic-tag" onclick="sendQuickMessage('Apa aturan SIM kendaraan bermotor?')">
          <span class="topic-tag-icon">🪪</span> SIM
        </button>
        <button class="topic-tag" onclick="sendQuickMessage('Apa aturan parkir di jalan?')">
          <span class="topic-tag-icon">🅿️</span> Parkir
        </button>
        <button class="topic-tag" onclick="sendQuickMessage('Bagaimana hukum kecelakaan lalu lintas?')">
          <span class="topic-tag-icon">⚠️</span> Kecelakaan
        </button>
        <button class="topic-tag" onclick="sendQuickMessage('Apa sanksi melanggar lampu merah?')">
          <span class="topic-tag-icon">🚦</span> Lampu Merah
        </button>
      </div>

      {{-- Input (PBI-2: dengan char counter, hint, dan error) --}}
      <div class="chat-input-wrapper">
        <form id="chatForm" onsubmit="sendMessage(event)">
          <div class="chat-input-box" id="chatInputBox">
            <textarea
              id="chatInput"
              class="chat-input"
              placeholder="Ketik pertanyaan hukum lalu lintas Anda di sini..."
              rows="1"
              maxlength="2000"
              onkeydown="handleKey(event)"
              oninput="onChatInput(this)"
            ></textarea>
            <button type="submit" class="send-btn" id="sendBtn" title="Kirim" disabled>
              ➤
            </button>
          </div>

          {{-- PBI-2: baris info di bawah input --}}
          <div class="input-meta">
            {{-- Error validasi --}}
            <span id="inputError" class="input-error" style="display:none;"></span>
            {{-- Hint relevansi kata kunci --}}
            <span id="inputHint" class="input-hint" style="display:none;"></span>
            {{-- Karakter counter --}}
            <span id="charCounter" class="char-counter">0 / 2000</span>
          </div>
        </form>
        <p class="input-disclaimer">ℹ️ Jawaban dihasilkan oleh AI dan bersifat informatif, bukan nasihat hukum.</p>
      </div>

    </div>{{-- /chat-area --}}
  </main>
</div>

{{-- ══════════ HISTORY PANEL ══════════ --}}
<div class="history-panel" id="historyPanel" onclick="closeHistoryOnBg(event)">
  <div class="history-drawer">
    <div class="history-header">
      <h2>🕐 Riwayat Percakapan</h2>
      <button class="btn-close" onclick="closeHistory()">✕</button>
    </div>
    <button class="btn-new-chat" onclick="startNewChat()">+ Percakapan Baru</button>
    <div class="history-list" id="historyList">
      <div class="history-empty">Memuat riwayat...</div>
    </div>
  </div>
</div>

{{-- Toast --}}
<div class="toast" id="toast"></div>

{{-- Pass session_id to JS --}}
<script>
  window.LENTRA = {
    sessionId: '{{ $sessionId }}',
    sendUrl: '{{ route("chat.send") }}',
    newSessionUrl: '{{ route("chat.new-session") }}',
    historyUrl: '{{ route("chat.history") }}',
    switchUrl: '{{ url("/chat/switch") }}',
    validateUrl: '{{ route("chat.validate-input") }}',
    csrfToken: '{{ csrf_token() }}',
  };
</script>
@endsection
