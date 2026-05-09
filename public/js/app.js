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
   * Send message (PBI-1 + PBI-2)
   * ════════════════════════════════════════════════════════ */
  window.sendMessage = async function (e) {
    e && e.preventDefault();
    if (isSending) return;

    const text = chatInput.value.trim();

    // PBI-2: Validasi frontend sebelum kirim
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

    // Sembunyikan empty state & hero
    if (emptyEl) emptyEl.style.display = 'none';
    if (heroEl)  heroEl.style.display  = 'none';

    // Tampilkan bubble user secara optimistik
    appendMessage({ role: 'user', content: text, time: currentTime() });
    scrollBottom(true);

    // Tampilkan typing indicator
    typingEl.classList.add('show');
    scrollBottom(true);

    try {
      const res = await fetch(cfg.sendUrl, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': cfg.csrfToken,
          'Accept': 'application/json',
        },
        body: JSON.stringify({ message: text, session_id: currentSessionId }),
      });

      // PBI-2: tangani response 422 (validation error dari backend)
      if (res.status === 422) {
        const errData = await res.json();
        typingEl.classList.remove('show');
        // Hapus bubble user yang sudah ditambahkan
        const lastRow = messagesEl.querySelector('.message-row.user:last-of-type');
        if (lastRow) lastRow.remove();
        showInputError(errData.message || 'Input tidak valid.');
        chatInput.value = text;
        updateCharCounter(text);
        return;
      }

      if (!res.ok) throw new Error('Server error ' + res.status);
      const data = await res.json();

      typingEl.classList.remove('show');

      if (data.success) {
        appendMessage(data.assistant_message);
        scrollBottom(true);
      }
    } catch (err) {
      typingEl.classList.remove('show');
      showToast('❌ Gagal terhubung ke server. Coba lagi.');
      console.error(err);
    } finally {
      isSending        = false;
      sendBtn.disabled = false;
      chatInput.focus();
    }
  };

  /* ── Quick topic button ── */
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
      let refs = '';
      if (msg.pasal) {
        refs += `<div class="ref-card pasal">
          <div class="ref-card-icon">📋</div>
          <div class="ref-card-content">
            <div class="ref-card-label">Pasal</div>
            <div class="ref-card-value">${escHtml(msg.pasal)}</div>
          </div></div>`;
      }
      if (msg.sanksi) {
        refs += `<div class="ref-card sanksi">
          <div class="ref-card-icon">⚠️</div>
          <div class="ref-card-content">
            <div class="ref-card-label">Sanksi</div>
            <div class="ref-card-value">${escHtml(msg.sanksi)}</div>
          </div></div>`;
      }
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
    historyPanel.classList.contains('open') ? closeHistory() : openHistory();
  };

  function openHistory() {
    historyPanel.classList.add('open');
    loadHistory();
  }

  window.closeHistory = function () {
    historyPanel.classList.remove('open');
  };

  window.closeHistoryOnBg = function (e) {
    if (e.target === historyPanel) closeHistory();
  };

  async function loadHistory() {
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
