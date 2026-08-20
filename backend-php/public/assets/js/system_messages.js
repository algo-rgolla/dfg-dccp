// assets/js/system_messages.js
(function () {
  document.addEventListener('DOMContentLoaded', init);

  const STORE_KEY = 'sysmsg.dismissed'; // array of string IDs

  function loadDismissed() {
    try { return new Set(JSON.parse(sessionStorage.getItem(STORE_KEY) || '[]')); }
    catch { return new Set(); }
  }
  function saveDismissed(set) {
    sessionStorage.setItem(STORE_KEY, JSON.stringify([...set]));
  }
  function markDismissed(id) {
    const s = loadDismissed();
    s.add(String(id));
    saveDismissed(s);
  }

  async function init() {
    console.log('[sysmsg] script loaded');
    const host = document.getElementById('systemMessages');
    if (!host) { console.warn('[sysmsg] #systemMessages not found'); return; }

    // 🚫 Skip fetch entirely if we’re on the login page
    const path = window.location.href.toLowerCase();
    if (path.includes('route=auth/login') || path.includes('route=auth/loginform')) {
      console.log('[sysmsg] skipped — user not logged in or on login screen');
      return;
    }

    // Delegated handlers
    host.addEventListener('click', onAckClick);
    host.addEventListener('click', onDismissClick);

    try {
      const url = 'index.php?route=systemmessages/feed';
      console.log('[sysmsg] fetching feed:', url);
      const res = await fetch(url, { credentials: 'same-origin' });
      console.log('[sysmsg] feed status:', res.status);
      if (!res.ok) return;

      const contentType = res.headers.get('content-type') || '';
      if (!contentType.includes('application/json')) {
        console.warn('[sysmsg] skipping — response not JSON');
        return;
      }

      const items = await res.json();
      console.log('[sysmsg] feed items:', Array.isArray(items) ? items.length : '(not array)', items);

      if (!Array.isArray(items) || items.length === 0) {
        host.innerHTML = '';
        return;
      }

      const dismissed = loadDismissed();
      const visible = items.filter(m => !dismissed.has(String(m.MessageID)));
      console.log('[sysmsg] dismissed in session:', [...dismissed], 'visible:', visible.length);

      if (visible.length === 0) {
        host.innerHTML = '';
        return;
      }

      host.innerHTML = visible.map(renderMsg).join('');
    } catch (e) {
      console.error('[sysmsg] load failed', e);
    }
  }

  function sevToBs(sev) {
    const s = String(sev ?? '');
    const mapNum = { '0': 'secondary', '1': 'info', '2': 'warning', '3': 'danger', '4': 'success' };
    const mapTxt = { info: 'info', success: 'success', warning: 'warning', danger: 'danger' };
    return mapNum[s] || mapTxt[s.toLowerCase?.()] || 'info';
  }

  function esc(s='') {
    return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
            .replace(/"/g,'&quot;').replace(/'/g,'&#039;');
  }

  function renderMsg(m) {
    const bs = sevToBs(m.Severity);
    const needAck = String(m.RequireAck ?? '0') === '1';
    const title = esc(m.Title || '');
    const bodyHtml = m.BodyHtml || ''; // trusted admin HTML

    // Only allow dismiss (X) when ack is NOT required
    const closeBtn = needAck
      ? ''
      : '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>';

    const alertClasses = 'alert alert-' + bs + (needAck ? '' : ' alert-dismissible') + ' fade show';

    return `
      <div class="${alertClasses}" role="alert" data-msg-id="${m.MessageID}">
        <div class="d-flex align-items-start">
          <i class="bi bi-megaphone-fill me-2"></i>
          <div>
            <div class="fw-semibold mb-1">${title}</div>
            <div class="sysmsg-body">${bodyHtml}</div>
            ${needAck ? `
              <div class="mt-2">
                <button type="button" class="btn btn-sm btn-primary sysmsg-ack" data-msg-id="${m.MessageID}">
                  <i class="bi bi-check2"></i> Acknowledge
                </button>
              </div>` : ``}
          </div>
        </div>
        ${closeBtn}
      </div>`;
  }

  // --- Dismiss via "X": persist for current session ---
  function onDismissClick(e) {
    const closeBtn = e.target.closest('.btn-close');
    if (!closeBtn) return;

    const alertEl = closeBtn.closest('.alert[data-msg-id]');
    const id = alertEl?.getAttribute('data-msg-id');
    if (!id) return;

    console.log('[sysmsg] dismiss via X → remember for session:', id);
    markDismissed(id);
    // Bootstrap will handle the visual close; nothing else needed here.
  }

  // --- Acknowledge required messages ---
  async function onAckClick(e) {
    const btn = e.target.closest('.sysmsg-ack');
    if (!btn) return;

    e.preventDefault();
    e.stopPropagation();

    const id = btn.getAttribute('data-msg-id');
    console.log('[sysmsg] ack click → POST MessageID=', id);

    try {
      const r = await fetch('index.php?route=systemmessages/ack', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        credentials: 'same-origin',
        body: `MessageID=${encodeURIComponent(id)}`
      });

      const txt = await r.text();
      console.log('[sysmsg] ack response:', r.status, txt);

      if (!r.ok) {
        alert(`Acknowledge failed (${r.status}). Check Network tab for systemmessages/ack.`);
        return;
      }

      // Also mark dismissed in-session to avoid any flicker on immediate reloads
      markDismissed(id);

      // Visual feedback + remove alert
      btn.disabled = true;
      btn.innerHTML = '<i class="bi bi-check2"></i> Acknowledged';

      const alertEl = btn.closest('.alert');
      if (alertEl) {
        setTimeout(() => {
          if (typeof bootstrap !== 'undefined' && bootstrap.Alert) {
            bootstrap.Alert.getOrCreateInstance(alertEl).close();
          } else {
            alertEl.remove();
          }
        }, 200);
      }
    } catch (err) {
      console.error('[sysmsg] ack error', err);
      alert('Acknowledge error: ' + (err?.message || err));
    }
  }
})();
