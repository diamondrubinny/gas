/**
 * Gas Safety — public bridge page.
 *
 * Reads ?eq=TOKEN (or legacy Monday ID) from the URL, fetches the equipment
 * via the public /qr/{code} endpoint, and renders a clean read-only card with
 * a "report fault" action. No login required.
 */
(function () {
  'use strict';

  var CFG = window.GSM_BRIDGE || {};
  var MOUNT = document.getElementById('gsm-bridge');
  if (!MOUNT || !CFG.restRoot) return;

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function getCode() {
    var p = new URLSearchParams(window.location.search);
    return p.get('eq') || p.get('token') || p.get('code') || '';
  }

  function api(path, opts) {
    opts = opts || {};
    var headers = { 'Content-Type': 'application/json' };
    if (CFG.nonce) headers['X-WP-Nonce'] = CFG.nonce;
    // Permalink-safe (see auth.js): join query with "&" under ?rest_route=.
    var url = CFG.restRoot.indexOf('?') > -1 ? (CFG.restRoot + path.replace('?', '&')) : (CFG.restRoot + path);
    return fetch(url, {
      method: opts.method || 'GET',
      headers: headers,
      body: opts.body ? JSON.stringify(opts.body) : undefined,
      credentials: 'same-origin'
    }).then(function (r) {
      return r.json().then(function (j) {
        if (!r.ok) throw new Error((j && j.message) || ('HTTP ' + r.status));
        return j;
      });
    });
  }

  function field(label, value) {
    if (!value) return '';
    return '<div style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid var(--gs-border)">' +
      '<span style="color:var(--gs-muted);font-size:13px">' + esc(label) + '</span>' +
      '<span style="font-weight:600">' + esc(value) + '</span></div>';
  }

  function statusPill(status) {
    var cls = /תקול|פסול|faulty/i.test(status) ? 'danger' :
      (/תחזוקה|maintenance/i.test(status) ? 'warn' : 'ok');
    return '<span class="gs-pill gs-pill--' + cls + '">' + esc(status || 'לא ידוע') + '</span>';
  }

  function render(eq) {
    var C = CFG.cfg || {};
    function on(k) { return C[k] !== false; } // default visible

    var alerts = '';
    if (eq.open_faults > 0) {
      alerts += '<div class="gs-pill gs-pill--danger" style="margin-inline-start:6px">⚠ ' + eq.open_faults + ' תקלות פתוחות</div>';
    }
    if (eq.open_defects > 0) {
      alerts += '<div class="gs-pill gs-pill--warn">⚠ ' + eq.open_defects + ' ליקויים פתוחים</div>';
    }

    var navy = C.primaryColor || 'var(--gs-navy)';

    MOUNT.innerHTML =
      '<div class="gs-card" style="max-width:560px;margin:0 auto">' +
      '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px">' +
      '<h2 style="margin:0;color:' + esc(navy) + ';font-size:22px">' + esc(eq.name) + '</h2>' +
      (on('showStatus') ? statusPill(eq.status) : '') + '</div>' +
      (alerts ? '<div style="margin-bottom:12px;display:flex;flex-wrap:wrap;gap:6px">' + alerts + '</div>' : '') +
      field('מספר ציוד', eq.asset_code) +
      field('סוג הגז', eq.gas_type) +
      (C.compactMode ? '' : field('קטגוריה', eq.category)) +
      field('פקולטה / יחידה', eq.faculty) +
      field('בניין', eq.building) +
      (C.compactMode ? '' : field('קומה', eq.floor)) +
      field('מס\' חדר', eq.room) +
      (C.compactMode ? '' : field('מיקום', eq.location)) +
      field('מספר פאנל', eq.panel_number) +
      (C.compactMode ? '' : field('לחץ עבודה', eq.work_pressure)) +
      (on('showPI') ? field('חוקר ראשי', eq.pi_name) : '') +
      (on('showLabManager') ? field('מנהלי מעבדה', eq.lab_managers) : '') +
      (on('showMaintenance') ? field('אנשי תחזוקה', eq.maint_staff) : '') +
      field('טלפון', eq.phone) +
      field('ביקורת רבעונית אחרונה', eq.last_quarterly) +
      field('ביקורת רבעונית הבאה', eq.next_quarterly) +
      (on('showFaultButton')
        ? '<div style="margin-top:20px;display:flex;gap:10px"><button class="gs-btn gs-btn--gold" id="gsm-br-fault">⚠ דווח תקלה</button></div>'
        : '') +
      '</div>';

    var faultBtn = document.getElementById('gsm-br-fault');
    if (faultBtn) {
      faultBtn.addEventListener('click', function () { openFaultForm(eq); });
    }
  }

  function openFaultForm(eq) {
    var openedAt = Date.now();
    var backdrop = document.createElement('div');
    backdrop.className = 'gs-modal-backdrop';
    backdrop.innerHTML =
      '<div class="gs-modal">' +
      '<div class="gs-modal__title">דיווח תקלה — ' + esc(eq.name) + '</div>' +
      '<div class="gs-field"><label class="gs-label">תיאור התקלה *</label>' +
      '<textarea class="gs-textarea" id="br-desc" rows="3"></textarea></div>' +
      '<div class="gs-field"><label class="gs-label">שם המדווח</label>' +
      '<input class="gs-input" id="br-reporter"></div>' +
      '<div class="gs-field"><label class="gs-label">עדיפות</label>' +
      '<select class="gs-select" id="br-priority">' +
      '<option value="normal">רגיל</option><option value="high">גבוה</option>' +
      '<option value="urgent">דחוף</option><option value="low">נמוך</option></select></div>' +
      // Honeypot: hidden from humans, bots tend to fill it. Server drops it.
      '<input type="text" id="br-hp" name="website" tabindex="-1" autocomplete="off" aria-hidden="true" style="position:absolute;left:-9999px;width:1px;height:1px;opacity:0">' +
      '<div id="br-err"></div>' +
      '<div class="gs-modal__actions">' +
      '<button class="gs-btn gs-btn--gold" id="br-send">שלח דיווח</button>' +
      '<button class="gs-btn gs-btn--ghost" id="br-cancel">ביטול</button>' +
      '</div></div>';
    document.body.appendChild(backdrop);

    function close() { backdrop.remove(); }
    backdrop.addEventListener('click', function (e) { if (e.target === backdrop) close(); });
    document.getElementById('br-cancel').addEventListener('click', close);
    document.getElementById('br-send').addEventListener('click', function () {
      var desc = document.getElementById('br-desc').value.trim();
      if (!desc) {
        document.getElementById('br-err').innerHTML = '<div class="gs-error" style="margin-top:10px">תיאור התקלה חובה</div>';
        return;
      }
      this.disabled = true;
      api('/report-fault', {
        method: 'POST',
        body: {
          token: eq.qr_token || getCode(),
          description: desc,
          reporter: document.getElementById('br-reporter').value.trim(),
          priority: document.getElementById('br-priority').value,
          hp: (document.getElementById('br-hp') || {}).value || '',
          ts: openedAt
        }
      }).then(function () {
        backdrop.querySelector('.gs-modal').innerHTML =
          '<div style="text-align:center;padding:20px">' +
          '<div style="font-size:40px">✓</div>' +
          '<h3 style="color:var(--gs-ok-dark)">הדיווח נשלח בהצלחה</h3>' +
          '<p style="color:var(--gs-muted)">תודה. הצוות יטפל בתקלה.</p>' +
          '<button class="gs-btn" type="button" id="br-done">סגור</button>' +
          '</div>';
        var done = document.getElementById('br-done');
        if (done) {
          done.addEventListener('click', function () { backdrop.remove(); });
          done.focus();
        }
      }).catch(function (e) {
        document.getElementById('br-err').innerHTML = '<div class="gs-error" style="margin-top:10px">' + esc(e.message) + '</div>';
        document.getElementById('br-send').disabled = false;
      });
    });
  }

  function showError(msg) {
    MOUNT.innerHTML = '<div class="gs-card" style="max-width:560px;margin:0 auto">' +
      '<div class="gs-error">' + esc(msg) + '</div></div>';
  }

  // ---- boot ----------------------------------------------------------------

  var code = getCode();
  if (!code) {
    showError('לא נמצא מזהה ציוד בכתובת. יש לסרוק קוד QR תקין.');
    return;
  }
  api('/qr/' + encodeURIComponent(code))
    .then(render)
    .catch(function (e) { showError(e.message); });
})();
