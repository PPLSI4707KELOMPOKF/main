/* LENTRA AI – Chat JavaScript (PBI-1) */
(function () {
  'use strict';

  const cfg = window.LENTRA || {};
  let currentSessionId = cfg.sessionId || '';
  let isSending = false;

  /* ── DOM Refs ── */
  const chatInput     = document.getElementById('chatInput');
  const sendBtn       = document.getElementById('sendBtn');
  const messagesEl    = document.getElementById('messagesContainer');
  const typingEl      = document.getElementById('typingIndicator');
  const emptyEl       = document.getElementById('emptyState');
  const heroEl        = document.getElementById('heroSection');
  const historyPanel  = document.getElementById('historyPanel');
  const historyList   = document.getElementById('historyList');
  const toastEl       = document.getElementById('toast');

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

  /* ── Key handler ── */
  window.handleKey = function (e) {
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault();
      document.getElementById('chatForm').dispatchEvent(new Event('submit'));
    }
  };

  /* ── Send message ── */
  window.sendMessage = async function (e) {
    e && e.preventDefault();
    if (isSending) return;
    const text = chatInput.value.trim();
    if (!text) return;

    isSending = true;
    sendBtn.disabled = true;
    chatInput.value = '';
    chatInput.style.height = 'auto';

    // Hide empty state & hero
    if (emptyEl) emptyEl.style.display = 'none';
    if (heroEl)  heroEl.style.display  = 'none';

    // Optimistically append user bubble
    appendMessage({ role: 'user', content: text, time: currentTime() });
    scrollBottom(true);

    // Show typing
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
      isSending = false;
      sendBtn.disabled = false;
      chatInput.focus();
    }
  };

  /* ── Quick topic button ── */
  window.sendQuickMessage = function (text) {
    chatInput.value = text;
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

    // Insert before typing indicator
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
        // Clear existing messages
        const rows = messagesEl.querySelectorAll('.message-row:not(#typingIndicator)');
        rows.forEach(r => r.remove());
        if (emptyEl) emptyEl.style.display = 'none';
        if (heroEl)  heroEl.style.display  = 'none';
        // Render messages
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
        headers: { 'X-CSRF-TOKEN': cfg.csrfToken, 'Accept': 'application/json', 'Content-Type': 'application/json' },
      });
      const data = await res.json();
      if (data.success) {
        currentSessionId = data.session_id;
        const rows = messagesEl.querySelectorAll('.message-row:not(#typingIndicator)');
        rows.forEach(r => r.remove());
        if (emptyEl) { emptyEl.style.display = 'flex'; }
        if (heroEl)  { heroEl.style.display  = ''; }
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
    return String(d.getHours()).padStart(2,'0') + ':' + String(d.getMinutes()).padStart(2,'0');
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
    chatInput && chatInput.focus();
  });

})();
