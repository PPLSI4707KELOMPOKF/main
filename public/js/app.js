/* LENTRA AI – Chat JavaScript
 * PBI-1: Interface & send message
 * PBI-2: Input pertanyaan pengguna – validasi realtime, char counter, error handling
 */
(function () {
  'use strict';

  const cfg = window.LENTRA || {};
  let currentSessionId = cfg.sessionId || '';
  let isSending        = false;
  let validateTimer    = null;   // debounce timer untuk PBI-2 realtime validate

  /* ── DOM Refs ── */
  const chatInput    = document.getElementById('chatInput');
  const sendBtn      = document.getElementById('sendBtn');
  const messagesEl   = document.getElementById('messagesContainer');
  const typingEl     = document.getElementById('typingIndicator');
  const emptyEl      = document.getElementById('emptyState');
  const heroEl       = document.getElementById('heroSection');
  const historyPanel = document.getElementById('historyPanel');
  const historyList  = document.getElementById('historyList');
  const toastEl      = document.getElementById('toast');
  const charCounter  = document.getElementById('charCounter');
  const inputHint    = document.getElementById('inputHint');
  const inputError   = document.getElementById('inputError');

  const MAX_CHARS = 2000;
  const MIN_CHARS = 2;

  /* ── Scroll ── */
  function scrollBottom(smooth) {
    messagesEl.scrollTo({ top: messagesEl.scrollHeight, behavior: smooth ? 'smooth' : 'auto' });
  }

  /* ── Toast ── */
  function showToast(msg, duration) {
    toastEl.textContent = msg;
    toastEl.classList.add('show');
    setTimeout(() => toastEl.classList.remove('show'), duration || 3000);
  }

  /* ── Auto-resize textarea ── */
  window.autoResize = function (el) {
    el.style.height = 'auto';
    el.style.height = Math.min(el.scrollHeight, 120) + 'px';
  };

  /* ════════════════════════════════════════════════════════
   * PBI-2: Input handling – karakter counter & validasi
   * ════════════════════════════════════════════════════════ */

  /**
   * Update karakter counter secara sinkron saat user mengetik.
   */
  function updateCharCounter(text) {
    if (!charCounter) return;
    const len  = text.length;
    const left = MAX_CHARS - len;
    charCounter.textContent = len + ' / ' + MAX_CHARS;

    charCounter.classList.remove('warn', 'danger');
    if (left <= 100) charCounter.classList.add('danger');
    else if (left <= 300) charCounter.classList.add('warn');
  }

  /**
   * Tampilkan error input (PBI-2: validasi frontend).
   */
  function showInputError(msg) {
    if (!inputError) return;
    inputError.textContent = msg;
    inputError.style.display = 'block';
    if (inputHint) inputHint.style.display = 'none';
  }

  /**
   * Hapus error input.
   */
  function clearInputError() {
    if (!inputError) return;
    inputError.textContent = '';
    inputError.style.display = 'none';
  }

  /**
   * Tampilkan hint dari server (relevansi kata kunci).
   */
  function showInputHint(msg, isRelevant) {
    if (!inputHint) return;
    inputHint.textContent = (isRelevant ? '✅ ' : '💡 ') + msg;
    inputHint.className   = 'input-hint ' + (isRelevant ? 'relevant' : 'irrelevant');
    inputHint.style.display = 'block';
    clearInputError();
  }

  function hideInputHint() {
    if (!inputHint) return;
    inputHint.style.display = 'none';
  }

  /**
   * Validasi frontend sinkron (PBI-2) – tanpa server.
   * Kembalikan pesan error atau null jika valid.
   */
  function validateFrontend(text) {
    if (!text || text.trim().length === 0) return 'Pertanyaan tidak boleh kosong.';
    if (text.trim().length < MIN_CHARS)    return 'Pertanyaan minimal ' + MIN_CHARS + ' karakter.';
    if (text.length > MAX_CHARS)           return 'Pertanyaan maksimal ' + MAX_CHARS + ' karakter.';
    return null;
  }

  /**
   * Validasi ke server (PBI-2 endpoint) – debounced 600ms.
   */
  async function validateWithServer(text) {
    if (!cfg.validateUrl) return;
    if (text.trim().length < 3) { hideInputHint(); return; }

    try {
      const res  = await fetch(cfg.validateUrl, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': cfg.csrfToken,
          'Accept': 'application/json',
        },
        body: JSON.stringify({ message: text }),
      });

      if (!res.ok) return;
      const data = await res.json();
      if (data.valid) {
        showInputHint(data.hint, data.is_relevant);
      }
    } catch {
      // Server tidak tersedia – abaikan hint saja
    }
  }

  /* ── Key handler (PBI-1 + PBI-2) ── */
  window.handleKey = function (e) {
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault();
      document.getElementById('chatForm').dispatchEvent(new Event('submit'));
    }
  };

  /* ── Input event (PBI-2: realtime counter + validation) ── */
  window.onChatInput = function (el) {
    // Resize textarea
    el.style.height = 'auto';
    el.style.height = Math.min(el.scrollHeight, 120) + 'px';

    const text = el.value;
    updateCharCounter(text);

    // Validasi frontend sinkron
    const err = validateFrontend(text);
    if (err && text.length > 0) {
      showInputError(err);
      hideInputHint();
    } else {
      clearInputError();
      // Debounce validasi ke server
      clearTimeout(validateTimer);
      validateTimer = setTimeout(() => validateWithServer(text), 600);
    }

    // Toggle send button
    sendBtn.disabled = !!err || text.trim().length === 0;
  };

  /* ════════════════════════════════════════════════════════
   * Send message via SSE streaming (PBI-1 + PBI-2)
   * Token Ollama langsung muncul real-time — tidak ada timeout
   * ════════════════════════════════════════════════════════ */
  window.sendMessage = async function (e) {
    e && e.preventDefault();
    if (isSending) return;

    const text = chatInput.value.trim();

    // PBI-2: Validasi frontend
    const frontendErr = validateFrontend(text);
    if (frontendErr) {
      showInputError(frontendErr);
      chatInput.focus();
      return;
    }
    clearInputError();
    hideInputHint();

    isSending = true;
    sendBtn.disabled = true;
    chatInput.value  = '';
    chatInput.style.height = 'auto';
    updateCharCounter('');

    if (emptyEl) emptyEl.style.display = 'none';
    if (heroEl)  heroEl.style.display  = 'none';

    // Tampilkan bubble user
    appendMessage({ role: 'user', content: text, time: currentTime() });
    scrollBottom(true);

    // Tampilkan typing indicator
    typingEl.classList.add('show');
    scrollBottom(true);

    // Buat bubble AI kosong untuk diisi token streaming
    const streamRow = document.createElement('div');
    streamRow.className = 'message-row ai stream-row';
    streamRow.innerHTML = `
      <div class="msg-avatar ai-avatar">✨</div>
      <div class="msg-body">
        <div class="msg-header">
          <span class="msg-name">LENTRA AI</span>
          <span class="ai-badge">AI</span>
          <span class="msg-time stream-time"></span>
        </div>
        <div class="msg-bubble ai stream-bubble"></div>
        <div class="stream-refs"></div>
      </div>`;
    streamRow.style.display = 'none';
    messagesEl.insertBefore(streamRow, typingEl);

    const streamBubble = streamRow.querySelector('.stream-bubble');
    const streamTime   = streamRow.querySelector('.stream-time');
    const streamRefs   = streamRow.querySelector('.stream-refs');

    // Buka SSE stream
    const params = new URLSearchParams({
      session_id: currentSessionId,
      message:    text,
      _token:     cfg.csrfToken,
    });

    const streamUrl = (cfg.streamUrl || '/chat/stream') + '?' + params.toString();
    const evtSource = new EventSource(streamUrl);

    let receivedAny = false;
    let streamContent = '';

    evtSource.onmessage = function (ev) {
      let data;
      try { data = JSON.parse(ev.data); } catch { return; }

      if (data.type === 'user_saved') {
        if (data.session_id) {
          currentSessionId = data.session_id;
        }
        // User message tersimpan — tampilkan AI bubble
        typingEl.classList.remove('show');
        streamRow.style.display = '';
        scrollBottom(true);
      }

      if (data.type === 'token') {
        receivedAny = true;
        streamContent += data.token;
        // Render token langsung ke bubble (escape HTML)
        streamBubble.textContent = streamContent;
        scrollBottom(false);
      }

      if (data.type === 'done') {
        evtSource.close();

        streamTime.textContent = data.time || currentTime();

        // Tambah ref cards jika ada pasal/sanksi/sumber regulasi.
        let refsHtml = renderReferenceCards(data);
        if (refsHtml) {
          streamRefs.className = 'ref-cards';
          streamRefs.innerHTML = refsHtml;
        }

        isSending        = false;
        sendBtn.disabled = false;
        chatInput.focus();
        scrollBottom(true);
      }
    };

    evtSource.onerror = function () {
      evtSource.close();
      typingEl.classList.remove('show');

      if (!receivedAny) {
        streamRow.remove();
        showToast('❌ Gagal terhubung ke AI. Pastikan Ollama berjalan.');
      }

      isSending        = false;
      sendBtn.disabled = false;
      chatInput.focus();
    };
  };


  /* ── Quick topic buttons ── */
  window.sendTopicMessage = function (button, text) {
    document.querySelectorAll('.topic-tag.active').forEach(tag => tag.classList.remove('active'));
    if (button) {
      button.classList.add('active');
    }
    sendQuickMessage(text);
  };

  window.sendQuickMessage = function (text) {
    chatInput.value = text;
    updateCharCounter(text);
    clearInputError();
    document.getElementById('chatForm').dispatchEvent(new Event('submit'));
  };

  /* ── Append message to DOM ── */
  function appendMessage(msg) {
    const row = document.createElement('div');
    row.className = 'message-row ' + (msg.role === 'user' ? 'user' : 'ai');

    if (msg.role === 'user') {
      row.innerHTML = `
        <div class="msg-avatar user-avatar">U</div>
        <div class="msg-body">
          <div class="msg-time">${escHtml(msg.time || currentTime())}</div>
          <div class="msg-bubble user">${escHtml(msg.content)}</div>
        </div>`;
    } else {
      let refs = renderReferenceCards(msg);
      row.innerHTML = `
        <div class="msg-avatar ai-avatar">✨</div>
        <div class="msg-body">
          <div class="msg-header">
            <span class="msg-name">LENTRA AI</span>
            <span class="ai-badge">AI</span>
            <span class="msg-time">${escHtml(msg.time || currentTime())}</span>
          </div>
          <div class="msg-bubble ai">${escHtml(msg.content)}</div>
          ${refs ? '<div class="ref-cards">' + refs + '</div>' : ''}
        </div>`;
    }

    messagesEl.insertBefore(row, typingEl);
  }

  /* ── History Panel ── */
  window.toggleHistory = function (e) {
    e && e.preventDefault();
    if (!cfg.isAuthenticated || !historyPanel) {
      showToast('Login untuk melihat riwayat percakapan.');
      return;
    }
    historyPanel.classList.contains('open') ? closeHistory() : openHistory();
  };

  function openHistory() {
    if (!historyPanel) return;
    historyPanel.classList.add('open');
    loadHistory();
  }

  window.closeHistory = function () {
    if (!historyPanel) return;
    historyPanel.classList.remove('open');
  };

  window.closeHistoryOnBg = function (e) {
    if (historyPanel && e.target === historyPanel) closeHistory();
  };

  async function loadHistory() {
    if (!cfg.historyUrl || !historyList) return;
    historyList.innerHTML = '<div class="history-empty">Memuat...</div>';
    try {
      const res  = await fetch(cfg.historyUrl, { headers: { 'Accept': 'application/json' } });
      const data = await res.json();
      renderHistory(data.sessions || []);
    } catch {
      historyList.innerHTML = '<div class="history-empty">Gagal memuat riwayat.</div>';
    }
  }

  function renderHistory(sessions) {
    if (!sessions.length) {
      historyList.innerHTML = '<div class="history-empty">Belum ada riwayat percakapan.</div>';
      return;
    }
    historyList.innerHTML = sessions.map(s => `
      <div class="history-item" onclick="switchSession('${escHtml(s.session_id)}')">
        <div class="history-item-title">${escHtml(s.title)}</div>
        <div class="history-item-preview">${escHtml(s.last_message)}</div>
        <div class="history-item-date">${escHtml(s.updated_at)}</div>
      </div>`).join('');
  }

  /* ── Switch session ── */
  window.switchSession = async function (sid) {
    try {
      const res  = await fetch(cfg.switchUrl + '/' + encodeURIComponent(sid), { headers: { 'Accept': 'application/json' } });
      const data = await res.json();
      if (data.success) {
        currentSessionId = data.session_id;
        const rows = messagesEl.querySelectorAll('.message-row:not(#typingIndicator)');
        rows.forEach(r => r.remove());
        if (emptyEl) emptyEl.style.display = 'none';
        if (heroEl)  heroEl.style.display  = 'none';
        (data.messages || []).forEach(m => appendMessage(m));
        scrollBottom(false);
        closeHistory();
      }
    } catch {
      showToast('Gagal memuat percakapan.');
    }
  };

  /* ── New Chat ── */
  window.startNewChat = async function () {
    if (!cfg.isAuthenticated) {
      clearVisibleChat();
      showToast('Percakapan sementara dibersihkan.');
      return;
    }

    try {
      const res  = await fetch(cfg.newSessionUrl, {
        method: 'POST',
        headers: {
          'X-CSRF-TOKEN': cfg.csrfToken,
          'Accept': 'application/json',
          'Content-Type': 'application/json',
        },
      });
      const data = await res.json();
      if (data.success) {
        currentSessionId = data.session_id;
        const rows = messagesEl.querySelectorAll('.message-row:not(#typingIndicator)');
        rows.forEach(r => r.remove());
        if (emptyEl) { emptyEl.style.display = 'flex'; }
        if (heroEl)  { heroEl.style.display  = ''; }
        clearInputError();
        hideInputHint();
        updateCharCounter('');
        closeHistory();
        chatInput.focus();
        showToast('✅ Percakapan baru dimulai.');
      }
    } catch {
      showToast('Gagal membuat percakapan baru.');
    }
  };

  function clearVisibleChat() {
    const rows = messagesEl.querySelectorAll('.message-row:not(#typingIndicator)');
    rows.forEach(r => r.remove());
    if (emptyEl) { emptyEl.style.display = 'flex'; }
    if (heroEl)  { heroEl.style.display  = ''; }
    clearInputError();
    hideInputHint();
    updateCharCounter('');
    closeHistory();
    chatInput.focus();
  }

  /* ── Helpers ── */
  function currentTime() {
    const d = new Date();
    return String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0');
  }

  function escHtml(str) {
    if (!str) return '';
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function renderReferenceCards(data) {
    const references = Array.isArray(data.references) ? data.references : [];
    const firstReference = references[0] || {};
    const pasal = data.pasal || firstReference.pasal;
    const sanksi = data.sanksi || firstReference.sanksi;
    const source = firstReference.source;
    const title = firstReference.title;
    const topic = firstReference.topic;

    let refs = '';
    if (pasal) {
      refs += `<div class="ref-card pasal">
        <div class="ref-card-icon">📋</div>
        <div class="ref-card-content">
          <div class="ref-card-label">Pasal</div>
          <div class="ref-card-value">${escHtml(pasal)}</div>
        </div></div>`;
    }
    if (sanksi) {
      refs += `<div class="ref-card sanksi">
        <div class="ref-card-icon">⚠️</div>
        <div class="ref-card-content">
          <div class="ref-card-label">Sanksi</div>
          <div class="ref-card-value">${escHtml(sanksi)}</div>
        </div></div>`;
    }
    if (source || title || topic) {
      const sourceValue = [title, source, topic].filter(Boolean).join(' · ');
      refs += `<div class="ref-card source-ref">
        <div class="ref-card-icon">📚</div>
        <div class="ref-card-content">
          <div class="ref-card-label">Sumber Regulasi</div>
          <div class="ref-card-value">${escHtml(sourceValue)}</div>
        </div></div>`;
    }

    return refs;
  }

  /* ── Init ── */
  document.addEventListener('DOMContentLoaded', function () {
    scrollBottom(false);
    if (chatInput) {
      chatInput.focus();
      updateCharCounter('');
      // Pastikan send button disabled saat awal
      sendBtn.disabled = true;
    }
  });

})();
