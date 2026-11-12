/**
 * Gas Safety — authenticated views (dashboard, equipment, faults,
 * inspections, admin). Each renders a full screen with the app chrome
 * (header + nav). Navigation swaps the whole screen via the router.
 */
(function () {
  'use strict';
  var esc = GSM.esc;
  var api = GSM.api;

  // ---- shared chrome -------------------------------------------------------

  function chrome(active, bodyHtml) {
    var u = GSM.session.user || {};
    var manage = GSM.can('manage'), edit = GSM.can('edit');
    var issuance = manage || (u.roleKey === 'ahmat'); // QR issuance: manager + אחמ"ת
    // Grouped navigation mirroring the portal. Each item: [route, label, visible].
    // Items that are not yet built are simply omitted (no dead links); an empty
    // group is hidden entirely.
    var GROUPS = [
      ['סקירה וניתוח', [
        ['dashboard', 'דשבורד', true],
        ['bi', 'BI וניתוח', manage],
        ['reports', 'הנפקת דוחות', true],
        ['audit', 'יומן בקרה', manage]
      ]],
      ['ציוד ומערכות', [
        ['myequip', 'הציוד שלי', true],
        ['quality', 'טיוב מידע', true],
        ['equipment', 'ניהול ציוד', true],
        ['contacts', 'אנשי קשר', manage],
        ['panels', 'מספור פאנל', manage],
        ['import', 'ייבוא ציוד', manage]
      ]],
      ['תקלות וביקורות', [
        ['faults', 'תקלות', true],
        ['inspections', 'ביקורות', true],
        ['inspcfg', 'הגדרות ביקורת', manage]
      ]],
      ['QR והנפקות', [
        ['qr', 'מדבקות QR', edit],
        ['qrissue', 'ניהול הנפקות', issuance]
      ]],
      ['מערכת והגדרות', [
        ['profile', 'הפרופיל שלי', true],
        ['security', 'אבטחה', true],
        ['users', 'ניהול אנשי אחמ"ת', manage],
        ['brsetup', 'עיצוב עמוד סריקה', manage],
        ['database', 'מסד נתונים', manage],
        ['restorepts', 'נקודות שחזור', manage],
        ['legacy', 'משיכה מהאתר הישן', manage],
        ['privacy', 'בקשות פרטיות', manage],
        ['comm', 'תקשורת והתראות', manage],
        ['integrations', 'אינטגרציות', manage],
        ['admin', 'הגדרות אתר', manage],
        ['help', 'עזרה ומדריך', true]
      ]]
    ];

    // Visibility: capability AND not globally-hidden AND allowed by the
    // role-permissions matrix (locked items always show — anti-lockout).
    var hidden = u.navHidden || [];
    var allowed = u.navAllowed; // null => capability defaults
    var LOCKED = { dashboard: 1, profile: 1, help: 1, admin: 1, users: 1 };
    function itemOk(it) {
      if (!it[2]) return false;
      var id = it[0];
      if (LOCKED[id]) return true;
      if (hidden.indexOf(id) > -1) return false;
      if (allowed && allowed.indexOf(id) === -1) return false;
      return true;
    }

    // Decorative group icons (inline SVG, hidden from assistive tech).
    var NAV_ICONS = {
      'סקירה וניתוח': '<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>',
      'ציוד ומערכות': '<path d="M9 3h6M10 3v6l-5 9a2 2 0 0 0 2 3h10a2 2 0 0 0 2-3l-5-9V3M7.5 15h9"/>',
      'תקלות וביקורות': '<path d="M12 3 2 20h20L12 3zM12 10v5M12 17.5v.5"/>',
      'QR והנפקות': '<path d="M4 4h6v6H4zM14 4h6v6h-6zM4 14h6v6H4zM14 14h3v3h-3zM20 17v3h-3"/>',
      'מערכת והגדרות': '<circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3M5 5l2 2M17 17l2 2M19 5l-2 2M7 17l-2 2"/>'
    };
    function navIcon(name) {
      return NAV_ICONS[name] ? '<svg class="gs-navgrp__ico" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' + NAV_ICONS[name] + '</svg>' : '';
    }
    var brand = (window.GSM_CONFIG && window.GSM_CONFIG.branding) || {};
    var navOrder = (window.GSM_CONFIG && window.GSM_CONFIG.navOrder) || [];
    var navHtml = GROUPS.map(function (g) {
      var items = g[1].filter(itemOk);
      if (!items.length) return '';
      if (navOrder.length) {
        items = items.slice().sort(function (a, b) {
          var ia = navOrder.indexOf(a[0]); var ib = navOrder.indexOf(b[0]);
          return (ia < 0 ? 999 : ia) - (ib < 0 ? 999 : ib);
        });
      }
      var hasActive = items.some(function (it) { return it[0] === active; });
      var itemsHtml = items.map(function (it) {
        return '<button class="gs-navgrp__item' + (it[0] === active ? ' is-active' : '') +
          '" type="button" data-gsm-nav="/' + esc(it[0]) + '">' + esc(it[1]) + '</button>';
      }).join('');
      return '<div class="gs-navgrp' + (hasActive ? ' is-current' : '') + '">' +
        '<button class="gs-navgrp__btn" type="button" data-gsm-action="navToggle">' + navIcon(g[0]) + esc(g[0]) + ' <span class="gs-navgrp__caret">▾</span></button>' +
        '<div class="gs-navgrp__menu">' + itemsHtml + '</div></div>';
    }).join('');

    return '' +
      '<div class="gs-shell">' +
      '<button type="button" class="gs-skip" data-gsm-action="skipToMain">דלג לתוכן הראשי</button>' +
      '<header class="gs-header">' +
      '<img class="gs-header__logo" src="' + esc(brand.logo_url || GSM.asset('img/brand/logo-header.png')) + '" alt="יחידת הבטיחות — הטכניון">' +
      '<h1 class="gs-header__title">' + esc(brand.title || 'מערכת גזים') + '</h1>' +
      '<span class="gs-header__spacer"></span>' +
      '<span class="gs-header__user">' + esc(u.name || '') +
      (u.roleLabel ? ' · ' + esc(u.roleLabel) : '') + '</span>' +
      '<button class="gs-header__logout" type="button" data-gsm-action="logout">יציאה</button>' +
      '</header>' +
      '<nav class="gs-nav gs-nav--grouped" aria-label="תפריט ראשי">' + navHtml + '</nav>' +
      '<main class="gs-main" id="gsm-main" tabindex="-1">' + bodyHtml + '</main>' +
      '</div>';
  }

  // Dropdown toggle for the grouped nav (works on touch + desktop).
  GSM._navToggle = function (btn) {
    var grp = btn.parentNode;
    var open = grp.classList.contains('open');
    document.querySelectorAll('.gs-navgrp.open').forEach(function (g) { g.classList.remove('open'); });
    if (!open) grp.classList.add('open');
  };
  if (!window.__gsmNavClose) {
    window.__gsmNavClose = true;
    document.addEventListener('click', function (e) {
      if (!e.target.closest || !e.target.closest('.gs-navgrp')) {
        document.querySelectorAll('.gs-navgrp.open').forEach(function (g) { g.classList.remove('open'); });
      }
    });
  }

  GSM._logout = function () {
    GSM.logout().then(function () { GSM.navigate('/'); });
  };

  // Delegated click actions (see router.js) — replaces inline onclick="".
  // Ids come from data-id and are passed through as positive integers only.
  function idOf(el) { var n = parseInt(el.getAttribute('data-id'), 10); return n > 0 ? n : 0; }
  GSM.registerAction('navToggle', function (el) { GSM._navToggle(el); });
  GSM.registerAction('skipToMain', function () { var m = document.getElementById('gsm-main'); if (m) m.focus(); });
  GSM.registerAction('logout', function () { GSM._logout(); });
  GSM.registerAction('export', function (el) { GSM._export(el.getAttribute('data-type'), el); });
  GSM.registerAction('rpRestore', function (el) { var id = idOf(el); if (id) GSM._rpRestore(id, el); });
  GSM.registerAction('rpDownload', function (el) { var id = idOf(el); if (id) GSM._rpDownload(id); });
  GSM.registerAction('rpDelete', function (el) { var id = idOf(el); if (id) GSM._rpDelete(id, el); });
  GSM.registerAction('auditUndo', function (el) { var id = idOf(el); if (id) GSM._auditUndo(id, el); });

  function loading() { return '<div class="gs-loading">טוען…</div>'; }

  // Download an Excel export of {type} via the authenticated REST endpoint.
  GSM._export = function (type, btn) {
    if (btn) { btn.disabled = true; var old = btn.textContent; btn.textContent = 'מכין…'; }
    api('/export/' + type).then(function (r) {
      var bin = atob(r.b64 || '');
      var bytes = new Uint8Array(bin.length);
      for (var i = 0; i < bin.length; i++) bytes[i] = bin.charCodeAt(i);
      var blob = new Blob([bytes], { type: r.mime || 'application/octet-stream' });
      var url = URL.createObjectURL(blob);
      var a = document.createElement('a');
      a.href = url; a.download = r.filename || (type + '.xlsx');
      document.body.appendChild(a); a.click();
      setTimeout(function () { URL.revokeObjectURL(url); a.remove(); }, 1200);
      if (btn) { btn.disabled = false; btn.textContent = old; }
    }).catch(function (e) {
      if (btn) { btn.disabled = false; btn.textContent = '⬇️ Excel'; }
      alert('שגיאת ייצוא: ' + (e.message || e));
    });
  };

  function exportBtn(type) {
    return '<button class="gs-btn gs-btn--sm gs-btn--export" type="button" data-gsm-action="export" data-type="' + esc(type) + '">⬇️ Excel</button>';
  }

  // ---- FIELD MESSAGES + FEEDBACK (dashboard cards) --------------------------

  function renderFieldMessages(host, focusId) {
    if (!host) return;
    api('/field-messages').then(function (d) {
      var items = d.items || [], mgr = !!d.manage;
      var unread = items.filter(function (m) { return m.status === 'unread'; }).length;
      var list = items.length ? items.slice(0, 30).map(function (m) {
        return '<li class="gs-msg' + (m.status === 'read' ? ' is-read' : '') + '"><div class="gs-msg__hd"><b>' + esc(m.author) + '</b> <bdi dir="ltr" class="gs-msg__when">' + esc(m.created_at.slice(0, 16)) + '</bdi>' +
          (m.status === 'unread' ? ' <span class="gs-pill gs-pill--warn">לא נקראה</span>' : ' <span class="gs-pill gs-pill--ok">נקראה</span>') + '</div>' +
          '<div class="gs-msg__tx">' + esc(m.text) + '</div>' +
          (mgr ? '<div class="gs-msg__act">' +
            (m.status === 'unread' ? '<button type="button" class="gs-btn gs-btn--sm gs-btn--ghost" data-fm-read="' + m.id + '">סמן כנקראה<span class="gs-visually-hidden">: הודעה של ' + esc(m.author) + '</span></button> ' : '') +
            '<button type="button" class="gs-btn gs-btn--sm gs-btn--ghost" data-fm-del="' + m.id + '">מחק<span class="gs-visually-hidden">: הודעה של ' + esc(m.author) + '</span></button></div>' : '') +
          '</li>';
      }).join('') : '';
      host.innerHTML =
        '<div class="gs-card" style="margin-top:20px"><h3 class="gs-card__title" id="fm-title" tabindex="-1">📣 הודעות מהשטח' +
        (mgr && unread ? ' <span class="gs-pill gs-pill--danger">חדשות: ' + unread + '</span>' : '') + '</h3>' +
        '<p style="color:var(--gs-muted);font-size:13px;margin-top:0">' + (mgr ? 'הודעות קצרות שמשתמשי המערכת שולחים למנהלים.' : 'הודעה קצרה למנהלי המערכת. כאן מוצגות ההודעות ששלחתם.') + '</p>' +
        '<div class="gs-field"><label class="gs-label" for="fm-text">הודעה חדשה</label>' +
        '<textarea class="gs-textarea" id="fm-text" rows="2" maxlength="2000"></textarea></div>' +
        '<button type="button" class="gs-btn gs-btn--sm" id="fm-send">שלח למנהלים</button> ' +
        '<span id="fm-st" role="status" aria-live="polite" style="margin-inline-start:8px;color:var(--gs-muted)"></span>' +
        (list ? '<ul class="gs-msglist" aria-label="הודעות מהשטח">' + list + '</ul>' : '<div class="gs-empty" style="margin-top:10px">אין הודעות.</div>') +
        (mgr && items.length ? '<div style="margin-top:10px"><button type="button" class="gs-btn gs-btn--sm gs-btn--ghost" id="fm-clear-read">נקה הודעות שנקראו</button> ' +
          '<button type="button" class="gs-btn gs-btn--sm gs-btn--ghost" id="fm-clear-all">נקה הכל</button></div>' : '') +
        '</div>';
      if (focusId) { var f = document.getElementById(focusId); if (f) f.focus(); }
      var st = document.getElementById('fm-st');
      document.getElementById('fm-send').addEventListener('click', function () {
        var btn = this, ta = document.getElementById('fm-text'), text = ta.value.trim();
        if (!text) { st.textContent = 'כתבו הודעה לפני השליחה.'; ta.focus(); return; }
        btn.disabled = true; st.textContent = 'שולח…';
        api('/field-messages', { method: 'POST', body: { text: text } })
          .then(function () { renderFieldMessages(host, 'fm-title'); setTimeout(function () { var s2 = document.getElementById('fm-st'); if (s2) s2.textContent = '✓ ההודעה נשלחה למנהלים.'; }, 300); })
          .catch(function (e) { st.textContent = '✗ ' + (e.message || e); btn.disabled = false; });
      });
      host.querySelectorAll('[data-fm-read]').forEach(function (b) {
        b.addEventListener('click', function () { b.disabled = true; api('/field-messages/' + b.getAttribute('data-fm-read') + '/read', { method: 'POST' }).then(function () { renderFieldMessages(host, 'fm-title'); }).catch(function (e) { st.textContent = '✗ ' + (e.message || e); b.disabled = false; }); });
      });
      host.querySelectorAll('[data-fm-del]').forEach(function (b) {
        b.addEventListener('click', function () {
          if (!confirm('למחוק את ההודעה?')) return;
          api('/field-messages/' + b.getAttribute('data-fm-del'), { method: 'DELETE' }).then(function () { renderFieldMessages(host, 'fm-title'); }).catch(function (e) { st.textContent = '✗ ' + (e.message || e); });
        });
      });
      [['fm-clear-read', true, 'למחוק את כל ההודעות שנקראו?'], ['fm-clear-all', false, 'למחוק את כל ההודעות מהשטח, כולל אלה שלא נקראו?']].forEach(function (c) {
        var b = document.getElementById(c[0]);
        if (b) b.addEventListener('click', function () {
          if (!confirm(c[2])) return;
          api('/field-messages/clear', { method: 'POST', body: { read_only: c[1] } }).then(function () { renderFieldMessages(host, 'fm-title'); }).catch(function (e) { st.textContent = '✗ ' + (e.message || e); });
        });
      });
    }).catch(function (e) { host.innerHTML = '<div class="gs-error" role="alert" style="margin-top:20px">' + esc(e.message || e) + '</div>'; });
  }

  var FB_TYPE = { bug: 'תקלה במערכת', feature: 'בקשת שיפור', question: 'שאלה' };
  var FB_PRIO = { low: ['נמוכה', 'ok'], medium: ['בינונית', 'warn'], high: ['גבוהה', 'warn'], critical: ['קריטית', 'danger'] };
  var FB_STATUS = { open: ['פתוח', 'warn'], in_progress: ['בטיפול', 'warn'], done: ['בוצע', 'ok'], wontfix: ['נדחה', 'danger'] };
  var fbFilter = 'active';

  function renderFeedback(host, focusId) {
    if (!host) return;
    function opts(map, sel) { return Object.keys(map).map(function (k) { var v = map[k]; return '<option value="' + k + '"' + (k === sel ? ' selected' : '') + '>' + esc(Array.isArray(v) ? v[0] : v) + '</option>'; }).join(''); }
    api('/feedback').then(function (d) {
      var all = d.items || [], mgr = !!d.manage;
      var active = all.filter(function (m) { return m.status === 'open' || m.status === 'in_progress'; });
      var items = fbFilter === 'active' ? active : all;
      var list = items.length ? items.slice(0, 50).map(function (m) {
        var pr = FB_PRIO[m.priority] || [m.priority, 'warn'], stt = FB_STATUS[m.status] || [m.status, 'warn'];
        return '<li class="gs-msg"><div class="gs-msg__hd"><b>' + esc(m.title || FB_TYPE[m.type] || 'פנייה') + '</b> <span class="gs-pill gs-pill--' + stt[1] + '">' + esc(stt[0]) + '</span> ' +
          '<span class="gs-pill gs-pill--' + pr[1] + '">עדיפות ' + esc(pr[0]) + '</span></div>' +
          '<div class="gs-msg__meta">' + esc(FB_TYPE[m.type] || m.type) + ' · ' + esc(m.author) + ' · <bdi dir="ltr">' + esc(m.created_at.slice(0, 16)) + '</bdi>' + (m.area ? ' · ' + esc(m.area) : '') +
          (m.handled_by && m.status !== 'open' ? ' · עודכן על ידי ' + esc(m.handled_by) : '') + '</div>' +
          '<div class="gs-msg__tx">' + esc(m.text) + '</div>' +
          (mgr ? '<div class="gs-msg__act"><label class="gs-visually-hidden" for="fb-st-' + m.id + '">סטטוס הפנייה: ' + esc(m.title || FB_TYPE[m.type] || '') + '</label>' +
            '<select class="gs-input" id="fb-st-' + m.id + '" data-fb-status="' + m.id + '" style="max-width:140px;display:inline-block">' + opts(FB_STATUS, m.status) + '</select> ' +
            '<button type="button" class="gs-btn gs-btn--sm gs-btn--ghost" data-fb-del="' + m.id + '">מחק<span class="gs-visually-hidden">: ' + esc(m.title || 'פנייה') + '</span></button></div>' : '') +
          '</li>';
      }).join('') : '';
      host.innerHTML =
        '<div class="gs-card" style="margin-top:20px"><h3 class="gs-card__title" id="fb-title" tabindex="-1">📝 משוב ובקשות שיפור' +
        (mgr && active.length ? ' <span class="gs-pill gs-pill--warn">פעילות: ' + active.length + '</span>' : '') + '</h3>' +
        '<p style="color:var(--gs-muted);font-size:13px;margin-top:0">נתקלתם בבעיה במערכת או רוצים שיפור? פתחו פנייה, והמנהלים יעדכנו את הסטטוס שלה.' + (mgr ? '' : ' כאן מוצגות הפניות שפתחתם.') + '</p>' +
        '<button type="button" class="gs-btn gs-btn--sm" id="fb-toggle" aria-expanded="false" aria-controls="fb-form">+ פנייה חדשה</button>' +
        '<div id="fb-form" hidden style="margin-top:12px;padding:12px;border:1px solid var(--gs-border);border-radius:10px">' +
        '<div class="gs-field"><label class="gs-label" for="fb-ttl">כותרת קצרה</label><input class="gs-input" id="fb-ttl" maxlength="160"></div>' +
        '<div style="display:flex;gap:10px;flex-wrap:wrap"><div class="gs-field" style="flex:1;min-width:150px"><label class="gs-label" for="fb-type">סוג</label><select class="gs-input" id="fb-type">' + opts(FB_TYPE, 'bug') + '</select></div>' +
        '<div class="gs-field" style="flex:1;min-width:150px"><label class="gs-label" for="fb-prio">עדיפות</label><select class="gs-input" id="fb-prio">' + opts(FB_PRIO, 'medium') + '</select></div></div>' +
        '<div class="gs-field"><label class="gs-label" for="fb-area">מסך או אזור רלוונטי (לא חובה)</label><input class="gs-input" id="fb-area" maxlength="120"></div>' +
        '<div class="gs-field"><label class="gs-label" for="fb-text">פירוט (חובה): מה קרה, או מה רוצים לשפר</label><textarea class="gs-textarea" id="fb-text" rows="4" maxlength="2000" aria-required="true"></textarea></div>' +
        '<button type="button" class="gs-btn gs-btn--sm" id="fb-send">פתח פנייה</button></div> ' +
        '<span id="fb-st" role="status" aria-live="polite" style="margin-inline-start:8px;color:var(--gs-muted)"></span>' +
        '<div style="margin-top:12px" role="group" aria-label="סינון פניות">' +
        '<button type="button" class="gs-btn gs-btn--sm' + (fbFilter === 'active' ? '' : ' gs-btn--ghost') + '" data-fb-filter="active" aria-pressed="' + (fbFilter === 'active') + '">פעילות (' + active.length + ')</button> ' +
        '<button type="button" class="gs-btn gs-btn--sm' + (fbFilter === 'all' ? '' : ' gs-btn--ghost') + '" data-fb-filter="all" aria-pressed="' + (fbFilter === 'all') + '">הכול (' + all.length + ')</button></div>' +
        (list ? '<ul class="gs-msglist" aria-label="פניות משוב">' + list + '</ul>' : '<div class="gs-empty" style="margin-top:10px">אין פניות להצגה.</div>') +
        '</div>';
      if (focusId) { var f = document.getElementById(focusId); if (f) f.focus(); }
      var st = document.getElementById('fb-st');
      var tg = document.getElementById('fb-toggle');
      tg.addEventListener('click', function () {
        var form = document.getElementById('fb-form'); form.hidden = !form.hidden;
        tg.setAttribute('aria-expanded', String(!form.hidden));
        if (!form.hidden) document.getElementById('fb-ttl').focus();
      });
      document.getElementById('fb-send').addEventListener('click', function () {
        var btn = this, text = document.getElementById('fb-text').value.trim();
        if (!text) { st.textContent = 'יש למלא פירוט.'; document.getElementById('fb-text').focus(); return; }
        btn.disabled = true; st.textContent = 'שולח…';
        api('/feedback', { method: 'POST', body: { text: text, title: document.getElementById('fb-ttl').value.trim(), type: document.getElementById('fb-type').value, priority: document.getElementById('fb-prio').value, area: document.getElementById('fb-area').value.trim() } })
          .then(function () { fbFilter = 'active'; renderFeedback(host, 'fb-title'); setTimeout(function () { var s2 = document.getElementById('fb-st'); if (s2) s2.textContent = '✓ הפנייה נפתחה.'; }, 300); })
          .catch(function (e) { st.textContent = '✗ ' + (e.message || e); btn.disabled = false; });
      });
      host.querySelectorAll('[data-fb-filter]').forEach(function (b) { b.addEventListener('click', function () { fbFilter = b.getAttribute('data-fb-filter'); renderFeedback(host, 'fb-title'); }); });
      host.querySelectorAll('[data-fb-status]').forEach(function (sel) {
        sel.addEventListener('change', function () {
          sel.disabled = true;
          api('/feedback/' + sel.getAttribute('data-fb-status') + '/status', { method: 'POST', body: { status: sel.value } })
            .then(function () { renderFeedback(host); setTimeout(function () { var s2 = document.getElementById('fb-st'); if (s2) s2.textContent = '✓ הסטטוס עודכן.'; }, 300); })
            .catch(function (e) { st.textContent = '✗ ' + (e.message || e); sel.disabled = false; });
        });
      });
      host.querySelectorAll('[data-fb-del]').forEach(function (b) {
        b.addEventListener('click', function () {
          if (!confirm('למחוק את הפנייה?')) return;
          api('/feedback/' + b.getAttribute('data-fb-del'), { method: 'DELETE' }).then(function () { renderFeedback(host, 'fb-title'); }).catch(function (e) { st.textContent = '✗ ' + (e.message || e); });
        });
      });
    }).catch(function (e) { host.innerHTML = '<div class="gs-error" role="alert" style="margin-top:20px">' + esc(e.message || e) + '</div>'; });
  }

  // ---- DASHBOARD -----------------------------------------------------------

  GSM.registerView('dashboard', function (mount) {
    mount.innerHTML = chrome('dashboard', loading());
    var main = mount.querySelector('.gs-main');

    Promise.all([api('/dashboard'), api('/dashboard/layout').catch(function () { return null; })]).then(function (both) {
      var d = both[0], layout = both[1];
      var s = d.stats || {};
      function stat(num, label, mod) {
        return '<div class="gs-stat' + (mod ? ' gs-stat--' + mod : '') + '">' +
          '<div class="gs-stat__num">' + esc(num) + '</div>' +
          '<div class="gs-stat__label">' + esc(label) + '</div></div>';
      }
      var stats =
        stat(s.equipment || 0, 'פריטי ציוד') +
        stat(s.inspections || 0, 'ביקורות') +
        stat(s.open_defects || 0, 'ליקויים פתוחים', 'danger') +
        stat(s.faults_open || 0, 'תקלות פתוחות') +
        stat(s.quarterly_due || 0, 'רבעוניות קרובות', 'ok');

      var dl = (d.deadlines || []).map(function (row) {
        var cls = row.days_left < 0 ? 'danger' : (row.days_left <= 14 ? 'warn' : 'ok');
        var txt = row.days_left < 0 ? ('באיחור ' + Math.abs(row.days_left) + ' ימים') :
          (row.days_left === 0 ? 'היום' : ('בעוד ' + row.days_left + ' ימים'));
        return '<tr><td><b>' + esc(row.name) + '</b></td>' +
          '<td><span class="gs-pill gs-pill--' + cls + '">' + esc(txt) + '</span></td>' +
          '<td>' + esc(row.open_defects || 0) + '</td>' +
          '<td>' + esc(row.last_quarterly || '—') + '</td></tr>';
      }).join('');

      var cov = d.coverage || {};
      var covCard =
        '<div class="gs-card" style="margin-top:20px"><div class="gs-card__title">📡 כיסוי סריקות וביקורת חודשית</div>' +
        '<div class="gs-grid gs-grid--stats">' +
          stat((cov.coverage_pct != null ? cov.coverage_pct + '%' : '—'), 'כיסוי סריקה (30 ימים)', (cov.coverage_pct >= 70 ? 'ok' : (cov.coverage_pct >= 40 ? 'warn' : 'danger'))) +
          stat((cov.scanned_30d || 0) + '/' + (cov.total || 0), 'ציוד שנסרק (30 ימים)') +
          stat(cov.not_scanned_30d || 0, 'לא נסרק (30 ימים)', 'warn') +
          stat(cov.monthly_overdue || 0, 'ביקורת חודשית באיחור', (cov.monthly_overdue > 0 ? 'danger' : 'ok')) +
        '</div>' +
        '<div style="margin-top:14px"><div id="gsm-cov-trend-t" style="font-size:13px;color:var(--gs-muted);margin-bottom:6px">סריקות ב-14 הימים האחרונים</div>' +
        // Fixed-height box: with maintainAspectRatio:false Chart.js takes the
        // parent's height, so an unsized parent made the chart thousands of px tall.
        '<div class="gs-chartbox gs-chartbox--trend"><canvas id="gsm-cov-trend" role="img" aria-labelledby="gsm-cov-trend-t" aria-describedby="gsm-cov-trend-d"></canvas></div>' +
        '<p id="gsm-cov-trend-d" class="gs-visually-hidden">' + esc((cov.trend_14d || []).map(function (x) { return (x.date || '') + ': ' + (x.count || 0); }).join(', ')) + '</p>' +
        '</div></div>';
      // Sections by id, so each user can reorder / hide them.
      var SEC = {
        stats: '<div class="gs-grid gs-grid--stats" style="margin-top:20px">' + stats + '</div>',
        coverage: covCard,
        messages: '<div id="gsm-fm"></div>',
        feedback: '<div id="gsm-fb"></div>',
        reports: '<div class="gs-card" style="margin-top:20px"><div class="gs-card__title">⬇️ הנפקת דוחות (Excel)</div>' +
          '<div style="display:flex;flex-wrap:wrap;gap:8px">' +
          '<button class="gs-btn gs-btn--sm gs-btn--export" type="button" data-gsm-action="export" data-type="equipment">⬇️ ציוד</button>' +
          '<button class="gs-btn gs-btn--sm gs-btn--export" type="button" data-gsm-action="export" data-type="inspections">⬇️ ביקורות</button>' +
          '<button class="gs-btn gs-btn--sm gs-btn--export" type="button" data-gsm-action="export" data-type="faults">⬇️ תקלות</button>' +
          '<button class="gs-btn gs-btn--sm gs-btn--export" type="button" data-gsm-action="export" data-type="scans">⬇️ סריקות</button>' +
          '</div></div>',
        deadlines: '<div class="gs-card" style="margin-top:20px"><div class="gs-card__title">⏳ דד-ליין ביקורות רבעוניות</div>' +
          (dl ? '<table class="gs-table"><thead><tr><th>ציוד</th><th>סטטוס</th><th>ליקויים</th><th>ביקורת אחרונה</th></tr></thead><tbody>' + dl + '</tbody></table>'
              : '<div class="gs-empty">אין נתונים להצגה</div>') +
          '</div>'
      };
      var lay = layout || {};
      var order = (lay.order && lay.order.length) ? lay.order : Object.keys(SEC);
      var hiddenSec = lay.hidden || [];
      var visible = order.filter(function (id) { return SEC[id] && hiddenSec.indexOf(id) < 0; });
      main.innerHTML =
        '<div class="gs-toolbar" style="margin-bottom:0"><h2 class="gs-h2">דשבורד</h2><div class="gs-toolbar__actions">' +
        '<button type="button" class="gs-btn gs-btn--sm gs-btn--ghost" id="dash-custom" aria-expanded="false" aria-controls="dash-custom-panel">התאמת הדשבורד</button></div></div>' +
        '<div id="dash-custom-panel" hidden></div>' +
        (visible.length ? visible.map(function (id) { return '<section data-dash="' + id + '">' + SEC[id] + '</section>'; }).join('')
          : '<div class="gs-empty" style="margin-top:20px">כל חלקי הדשבורד מוסתרים. לחצו על "התאמת הדשבורד" כדי להציג אותם.</div>');

      // Personal layout editor.
      document.getElementById('dash-custom').addEventListener('click', function () {
        var btnC = this, panel = document.getElementById('dash-custom-panel');
        if (!panel.hidden) { panel.hidden = true; btnC.setAttribute('aria-expanded', 'false'); return; }
        var labels = lay.sections || {};
        panel.innerHTML = '<div class="gs-card" style="margin-top:12px"><h3 class="gs-card__title" id="dash-custom-title" tabindex="-1">התאמת הדשבורד</h3>' +
          '<p style="color:var(--gs-muted);font-size:13px;margin-top:0">בחרו אילו חלקים להציג ובאיזה סדר. ההגדרה אישית ונשמרת בחשבון שלכם.</p>' +
          '<ul id="dash-custom-list" style="list-style:none;margin:0;padding:0">' + order.filter(function (id) { return SEC[id]; }).map(function (id) {
            var name = labels[id] || id;
            return '<li class="gs-ord-row" data-id="' + esc(id) + '" style="display:flex;align-items:center;gap:8px;padding:6px 10px;border:1px solid var(--gs-border);border-radius:8px;margin-bottom:6px">' +
              '<label style="flex:1;display:flex;gap:8px;align-items:center"><input type="checkbox" class="dash-vis"' + (hiddenSec.indexOf(id) < 0 ? ' checked' : '') + '><span>' + esc(name) + '</span></label>' +
              '<button type="button" class="gs-btn gs-btn--sm gs-btn--ghost dash-up" aria-label="הזז למעלה: ' + esc(name) + '"><span aria-hidden="true">↑</span></button>' +
              '<button type="button" class="gs-btn gs-btn--sm gs-btn--ghost dash-down" aria-label="הזז למטה: ' + esc(name) + '"><span aria-hidden="true">↓</span></button></li>';
          }).join('') + '</ul>' +
          '<button type="button" class="gs-btn gs-btn--sm" id="dash-custom-save">שמור</button> ' +
          '<button type="button" class="gs-btn gs-btn--sm gs-btn--ghost" id="dash-custom-reset">חזרה לברירת המחדל</button> ' +
          '<span id="dash-custom-st" role="status" aria-live="polite" style="margin-inline-start:8px;color:var(--gs-muted)"></span></div>';
        panel.hidden = false; btnC.setAttribute('aria-expanded', 'true');
        document.getElementById('dash-custom-title').focus();
        panel.querySelectorAll('.dash-up').forEach(function (b) { b.addEventListener('click', function () { var r = b.closest('li'), pv = r.previousElementSibling; if (pv) { r.parentNode.insertBefore(r, pv); b.focus(); } }); });
        panel.querySelectorAll('.dash-down').forEach(function (b) { b.addEventListener('click', function () { var r = b.closest('li'), nx = r.nextElementSibling; if (nx) { r.parentNode.insertBefore(nx, r); b.focus(); } }); });
        function saveLayout(body) {
          var st = document.getElementById('dash-custom-st'); st.textContent = 'שומר…';
          api('/dashboard/layout', { method: 'POST', body: body }).then(function () { GSM.render(); })
            .catch(function (e) { st.textContent = '✗ ' + (e.message || e); });
        }
        document.getElementById('dash-custom-save').addEventListener('click', function () {
          var o = [], h = [];
          panel.querySelectorAll('#dash-custom-list li').forEach(function (li) { o.push(li.getAttribute('data-id')); if (!li.querySelector('.dash-vis').checked) h.push(li.getAttribute('data-id')); });
          saveLayout({ order: o, hidden: h });
        });
        document.getElementById('dash-custom-reset').addEventListener('click', function () { saveLayout({ order: [], hidden: [] }); });
      });

      renderFieldMessages(document.getElementById('gsm-fm'));
      renderFeedback(document.getElementById('gsm-fb'));
      try {
        var _tr = cov.trend_14d || [];
        var _cv = document.getElementById('gsm-cov-trend');
        if (_cv && window.Chart && _tr.length) {
          new Chart(_cv.getContext('2d'), {
            type: 'bar',
            data: {
              labels: _tr.map(function (x) { return (x.date || '').slice(5); }),
              datasets: [{ data: _tr.map(function (x) { return x.count || 0; }), backgroundColor: '#0b2545', maxBarThickness: 28 }]
            },
            options: { plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }, responsive: true, maintainAspectRatio: false }
          });
        }
      } catch (_e) { /* trend chart is optional */ }
    }).catch(function (e) { main.innerHTML = '<div class="gs-error" role="alert">' + esc(e.message) + '</div>'; });
  });

  // ---- PANEL NUMBERING -----------------------------------------------------

  GSM.registerView('panels', function (mount) {
    if (!GSM.can('manage')) { GSM.navigate('/dashboard'); return; }
    mount.innerHTML = chrome('panels',
      '<div class="gs-toolbar"><h2 class="gs-h2">מספור פאנל אוטומטי</h2></div>' +
      '<div class="gs-card" style="max-width:520px">' +
      '<p style="color:var(--gs-muted);font-size:13px">הקצאת מספרי פאנל רציפים לקבוצת ציוד, לפי סדר שם. אפשר לסנן לפי בניין, ולבחור אם לדרוס מספרים קיימים.</p>' +
      '<div class="gs-field"><label class="gs-label">בניין (ריק = כל הציוד)</label><input class="gs-input" id="pn-building" placeholder="למשל: כימיה"></div>' +
      '<div class="gs-field"><label class="gs-label">תחילית (אופציונלי)</label><input class="gs-input" id="pn-prefix" placeholder="למשל: P-"></div>' +
      '<div class="gs-field"><label class="gs-label">מספר התחלה</label><input class="gs-input" id="pn-start" type="number" value="1" style="max-width:140px"></div>' +
      '<label class="gs-check"><input type="checkbox" id="pn-over"> דרוס מספרי פאנל קיימים</label>' +
      '<div style="margin-top:12px"><button class="gs-btn" id="pn-apply">הקצה מספרים</button><span id="pn-status" role="status" aria-live="polite" style="margin-inline-start:10px;color:var(--gs-muted)"></span></div>' +
      '</div>');
    var btn = mount.querySelector('#pn-apply');
    btn.addEventListener('click', function () {
      if (!confirm('להקצות מספרי פאנל? הפעולה מעדכנת את הציוד המתאים.')) { return; }
      var status = mount.querySelector('#pn-status');
      btn.disabled = true; status.textContent = 'מעדכן…';
      api('/equipment/assign-panels', { method: 'POST', body: {
        building: mount.querySelector('#pn-building').value.trim(),
        prefix: mount.querySelector('#pn-prefix').value,
        start: parseInt(mount.querySelector('#pn-start').value, 10) || 1,
        overwrite: mount.querySelector('#pn-over').checked
      } }).then(function (r) {
        status.textContent = '✓ עודכנו ' + (r.updated || 0) + ', דולגו ' + (r.skipped || 0);
        btn.disabled = false;
      }).catch(function (e) { status.textContent = '✗ ' + (e.message || e); btn.disabled = false; });
    });
  });

  // ---- CONTACTS ------------------------------------------------------------

  GSM.registerView('contacts', function (mount) {
    if (!GSM.can('manage')) { GSM.navigate('/dashboard'); return; }
    mount.innerHTML = chrome('contacts', loading());
    var main = mount.querySelector('.gs-main');
    function roleSelect(id) {
      return '<div class="gs-field"><label class="gs-label">תפקיד</label><select class="gs-input" id="' + id + '">' +
        '<option value="pi">חוקר ראשי</option><option value="lab">מנהל מעבדה</option><option value="maint">תחזוקה</option></select></div>';
    }
    function load() {
      api('/contacts').then(function (list) {
        list = list || [];
        var rows = list.map(function (c) {
          return '<tr><td>' + esc(c.name) + '</td><td><span class="gs-pill gs-pill--ok">' + esc(c.role_label) + '</span></td><td>' + esc(c.count) + '</td></tr>';
        }).join('');
        main.innerHTML =
          '<div class="gs-toolbar"><h2 class="gs-h2">אנשי קשר</h2><div class="gs-toolbar__actions" style="color:var(--gs-muted)">' + list.length + ' שמות</div></div>' +
          '<div class="gs-grid gs-grid--2">' +
          '<div class="gs-card"><div class="gs-card__title">שיוך איש קשר לקבוצה</div>' +
          roleSelect('ca-role') +
          '<div class="gs-field"><label class="gs-label">שם</label><input class="gs-input" id="ca-name"></div>' +
          '<div class="gs-field"><label class="gs-label">בניין (ריק = הכל)</label><input class="gs-input" id="ca-building"></div>' +
          '<button class="gs-btn" id="ca-apply">שייך</button><span id="ca-status" role="status" aria-live="polite" style="margin-inline-start:8px;color:var(--gs-muted)"></span>' +
          '<p style="color:var(--gs-muted);font-size:12px;margin-top:8px">חוקר ראשי — נקבע (מחליף). מנהל מעבדה / תחזוקה — מתווסף אם חסר.</p></div>' +
          '<div class="gs-card"><div class="gs-card__title">החלפת / הסרת שם בכל הציוד</div>' +
          roleSelect('cr-role') +
          '<div class="gs-field"><label class="gs-label">שם קיים</label><input class="gs-input" id="cr-from"></div>' +
          '<div class="gs-field"><label class="gs-label">שם חדש (ריק = הסרה)</label><input class="gs-input" id="cr-to"></div>' +
          '<button class="gs-btn" id="cr-apply">החלף בכל הציוד</button><span id="cr-status" role="status" aria-live="polite" style="margin-inline-start:8px;color:var(--gs-muted)"></span></div>' +
          '</div>' +
          '<div class="gs-card" style="margin-top:16px"><div class="gs-card__title">רשימת אנשי קשר</div>' +
          (list.length ? '<table class="gs-table"><thead><tr><th>שם</th><th>תפקיד</th><th>כמות ציוד</th></tr></thead><tbody>' + rows + '</tbody></table>' : '<div class="gs-empty">אין אנשי קשר</div>') +
          '</div>';
        main.querySelector('#ca-apply').addEventListener('click', function () {
          var a = this, st = main.querySelector('#ca-status'); a.disabled = true; st.textContent = 'משייך…';
          api('/contacts/assign', { method: 'POST', body: {
            role: main.querySelector('#ca-role').value, name: main.querySelector('#ca-name').value, building: main.querySelector('#ca-building').value.trim()
          } }).then(function (r) { st.textContent = '✓ ' + (r.updated || 0) + ' עודכנו'; a.disabled = false; load(); })
            .catch(function (e) { st.textContent = '✗ ' + (e.message || e); a.disabled = false; });
        });
        main.querySelector('#cr-apply').addEventListener('click', function () {
          if (!confirm('להחליף/להסיר את השם בכל הציוד?')) { return; }
          var rp = this, st = main.querySelector('#cr-status'); rp.disabled = true; st.textContent = 'מעדכן…';
          api('/contacts/replace', { method: 'POST', body: {
            role: main.querySelector('#cr-role').value, from: main.querySelector('#cr-from').value, to: main.querySelector('#cr-to').value
          } }).then(function (r) { st.textContent = '✓ ' + (r.updated || 0) + ' עודכנו'; rp.disabled = false; load(); })
            .catch(function (e) { st.textContent = '✗ ' + (e.message || e); rp.disabled = false; });
        });
      }).catch(function (e) { main.innerHTML = '<div class="gs-error" role="alert">' + esc(e.message) + '</div>'; });
    }
    load();
  });

  // ---- MY EQUIPMENT --------------------------------------------------------

  GSM.registerView('myequip', function (mount) {
    mount.innerHTML = chrome('myequip', loading());
    var main = mount.querySelector('.gs-main');
    api('/equipment/mine').then(function (r) {
      var items = (r && r.items) || [];
      var rows = items.map(function (it) {
        var pill = it.status ? '<span class="gs-pill gs-pill--' + statusCls(it.status) + '">' + esc(it.status) + '</span>' : '—';
        return '<tr>' +
          '<td><span class="gs-code">' + esc(it.asset_code || '—') + '</span></td>' +
          '<td><a href="#/equipment/' + it.id + '" style="color:var(--gs-navy);font-weight:600">' + esc(it.name) + '</a></td>' +
          '<td><span class="gs-pill gs-pill--ok">' + esc(it.my_role || '') + '</span></td>' +
          '<td>' + esc(it.gas_type || '') + '</td>' +
          '<td>' + esc(it.building || '') + '</td>' +
          '<td>' + esc(it.room || '') + '</td>' +
          '<td>' + pill + '</td>' +
          '<td>' + esc(it.next_quarterly || '—') + '</td></tr>';
      }).join('');
      main.innerHTML =
        '<div class="gs-toolbar"><h2 class="gs-h2">הציוד שלי</h2><div class="gs-toolbar__actions" style="color:var(--gs-muted)">' + items.length + ' פריטים</div></div>' +
        (items.length
          ? '<table class="gs-table"><thead><tr><th>מספר</th><th>שם</th><th>התפקיד שלי</th><th>גז</th><th>בניין</th><th>חדר</th><th>סטטוס</th><th>רבעונית הבאה</th></tr></thead><tbody>' + rows + '</tbody></table>'
          : '<div class="gs-empty">לא נמצא ציוד המשויך אליך לפי השם או האימייל שלך. אם צריך — עדכנו בכרטיס הציוד את שם החוקר הראשי / מנהל המעבדה / איש התחזוקה.</div>');
    }).catch(function (e) { main.innerHTML = '<div class="gs-error" role="alert">' + esc(e.message) + '</div>'; });
  });

  // ---- EQUIPMENT -----------------------------------------------------------

  GSM.registerView('equipment', function (mount, param) {
    if (param) { return equipmentDetail(mount, param); }
    mount.innerHTML = chrome('equipment', loading());
    var main = mount.querySelector('.gs-main');

    api('/equipment?per_page=200').then(function (items) {
      var rows = (items || []).map(function (it) {
        var pill = it.status ? '<span class="gs-pill gs-pill--' + statusCls(it.status) + '">' + esc(it.status) + '</span>' : '—';
        var actions = GSM.can('edit')
          ? '<button class="gs-btn gs-btn--sm gs-btn--ghost" data-edit="' + it.id + '">ערוך</button>'
          : '';
        return '<tr>' +
          '<td><span class="gs-code">' + esc(it.asset_code || '—') + '</span></td>' +
          '<td><a href="#/equipment/' + it.id + '" style="color:var(--gs-navy);font-weight:600">' + esc(it.name) + '</a></td>' +
          '<td>' + esc(it.gas_type || '') + '</td>' +
          '<td>' + esc(it.building || '') + '</td>' +
          '<td>' + esc(it.room || '') + '</td>' +
          '<td>' + pill + '</td>' +
          '<td>' + actions + '</td></tr>';
      }).join('');

      var addBtn = GSM.can('edit') ? '<button class="gs-btn" id="gsm-eq-add">+ הוסף ציוד</button>' : '';
      main.innerHTML =
        '<div class="gs-toolbar"><h2 class="gs-h2">ציוד</h2>' +
        '<div class="gs-toolbar__actions"><input class="gs-input" id="gsm-eq-search" placeholder="חיפוש…" style="max-width:240px">' + exportBtn('equipment') + addBtn + '</div></div>' +
        '<table class="gs-table"><thead><tr><th>מספר</th><th>שם</th><th>סוג גז</th><th>בניין</th><th>חדר</th><th>סטטוס</th><th></th></tr></thead>' +
        '<tbody id="gsm-eq-body">' + (rows || '<tr><td colspan="7"><div class="gs-empty">אין ציוד</div></td></tr>') + '</tbody></table>';

      var search = document.getElementById('gsm-eq-search');
      if (search) search.addEventListener('input', function () {
        var q = this.value.toLowerCase();
        document.querySelectorAll('#gsm-eq-body tr').forEach(function (tr) {
          tr.style.display = tr.textContent.toLowerCase().indexOf(q) > -1 ? '' : 'none';
        });
      });
      var add = document.getElementById('gsm-eq-add');
      if (add) add.addEventListener('click', function () { GSM_Forms.openEquipment(null, function () { GSM.navigate('/equipment'); }); });
      document.querySelectorAll('[data-edit]').forEach(function (b) {
        b.addEventListener('click', function () {
          api('/equipment/' + b.getAttribute('data-edit')).then(function (it) {
            GSM_Forms.openEquipment(it, function () { GSM.navigate('/equipment'); });
          });
        });
      });
    }).catch(function (e) { main.innerHTML = '<div class="gs-error" role="alert">' + esc(e.message) + '</div>'; });
  });

  function equipmentDetail(mount, id) {
    if (!/^\d+$/.test(String(id))) { GSM.navigate('/equipment'); return; }
    mount.innerHTML = chrome('equipment', loading());
    var main = mount.querySelector('.gs-main');
    api('/equipment/' + id).then(function (it) {
      function f(label, val) { return val ? '<div class="gs-detail__row"><span class="gs-detail__k">' + esc(label) + '</span><span class="gs-detail__v">' + esc(val) + '</span></div>' : ''; }
      main.innerHTML =
        '<div class="gs-toolbar"><h2 class="gs-h2">' + esc(it.name) + '</h2>' +
        '<div class="gs-toolbar__actions">' +
        (GSM.can('edit') ? '<button class="gs-btn gs-btn--sm" id="gsm-eq-edit">ערוך</button>' : '') +
        '<button class="gs-btn gs-btn--sm gs-btn--ghost" type="button" data-gsm-nav="/equipment">← חזרה</button></div></div>' +
        '<div class="gs-card">' +
        f('מספר ציוד', it.asset_code) + f('סוג הגז', it.gas_type) + f('קטגוריה', it.category) + f('פקולטה', it.faculty) +
        f('בניין', it.building) + f('קומה', it.floor) + f('חדר', it.room) +
        f('מיקום', it.location) + f('מספר פאנל', it.panel_number) + f('לחץ עבודה', it.work_pressure) +
        f('חוקר ראשי', it.pi_name) + f('מנהלי מעבדה', it.lab_managers) + f('אנשי תחזוקה', it.maint_staff) +
        f('טלפון', it.phone) + f('סטטוס', it.status) +
        f('ביקורת אחרונה', it.last_quarterly) + f('ביקורת הבאה', it.next_quarterly) +
        f('תאריך רכישה', it.purchase_date) +
        (it.manual_url && /^https:\/\//i.test(it.manual_url) ? '<div class="gs-detail__row"><span class="gs-detail__k">חוברת הפעלה</span><span class="gs-detail__v"><a href="' + esc(it.manual_url) + '" target="_blank" rel="noopener noreferrer">פתיחת החוברת<span class="gs-visually-hidden"> (נפתח בלשונית חדשה)</span></a></span></div>' : '') +
        f('הערות', it.notes) +
        '</div>' +
        qrCard(it);
      var ed = document.getElementById('gsm-eq-edit');
      if (ed) ed.addEventListener('click', function () { GSM_Forms.openEquipment(it, function () { GSM.navigate('/equipment/' + id); }); });
      wireQr(it);
    }).catch(function (e) { main.innerHTML = '<div class="gs-error" role="alert">' + esc(e.message) + '</div>'; });
  }

  // QR card: shows the printable QR for this equipment + actions.
  function qrCard(it) {
    if (!it.qr_token) {
      return '<div class="gs-card" style="margin-top:16px"><div class="gs-card__title">קוד QR</div>' +
        '<div class="gs-empty">לפריט זה אין עדיין קוד QR. ערוך ושמור כדי ליצור.</div></div>';
    }
    var url = bridgeUrl(it.qr_token);
    return '<div class="gs-card gs-qr-card" style="margin-top:16px">' +
      '<div class="gs-card__title">קוד QR לציוד</div>' +
      '<div class="gs-qr-wrap">' +
      '<div id="gsm-qr-canvas" class="gs-qr-box"></div>' +
      '<div class="gs-qr-meta">' +
      '<p class="gs-qr-name">' + esc(it.name) + '</p>' +
      '<p class="gs-qr-url"><a href="' + esc(url) + '" target="_blank" rel="noopener">' + esc(url) + '</a></p>' +
      '<div class="gs-qr-actions">' +
      '<button class="gs-btn gs-btn--sm" id="gsm-qr-print">🖨️ הדפס</button>' +
      '<button class="gs-btn gs-btn--sm gs-btn--ghost" id="gsm-qr-download">⬇️ הורד PNG</button>' +
      '</div></div></div></div>';
  }

  function bridgeUrl(token) {
    // Prefer the real bridge-page permalink supplied by PHP (correct under any
    // permalink setting). Fall back to deriving /bridge from restRoot.
    var base = (window.GSM_CONFIG && window.GSM_CONFIG.bridgeUrl) || '';
    if (!base) {
      base = ((window.GSM_CONFIG && window.GSM_CONFIG.restRoot) || '').replace(/\/wp-json\/gas\/v1\/?$/, '') + '/bridge';
    }
    // Join the token param safely whether or not base already has a query.
    var sep = base.indexOf('?') > -1 ? '&' : '?';
    return base + sep + 'eq=' + encodeURIComponent(token);
  }

  function wireQr(it) {
    if (!it.qr_token) return;
    var box = document.getElementById('gsm-qr-canvas');
    if (!box || !GSM.qrPng) return;
    var url = bridgeUrl(it.qr_token);
    // One <img> with a text alternative (WCAG 1.1.1).
    var im = document.createElement('img');
    im.src = GSM.qrPng(url, 180);
    im.width = 180;
    im.height = 180;
    im.alt = 'קוד QR לציוד ' + (it.name || '') + ' — מוביל לעמוד הפרטים';
    box.innerHTML = '';
    box.appendChild(im);

    var printBtn = document.getElementById('gsm-qr-print');
    if (printBtn) printBtn.addEventListener('click', function () { printQr(it, box); });
    var dlBtn = document.getElementById('gsm-qr-download');
    if (dlBtn) dlBtn.addEventListener('click', function () { downloadQr(it, box); });
  }

  function qrImgData(box) {
    var img = box.querySelector('img');
    if (img && img.src) return img.src;
    var canvas = box.querySelector('canvas');
    if (canvas) return canvas.toDataURL('image/png');
    return '';
  }

  function downloadQr(it, box) {
    var data = qrImgData(box);
    if (!data) return;
    var a = document.createElement('a');
    a.href = data;
    a.download = 'QR-' + (it.name || 'equipment').replace(/[^\w\u0590-\u05FF-]/g, '_') + '.png';
    document.body.appendChild(a); a.click(); a.remove();
  }

  function printQr(it, box) {
    var data = qrImgData(box);
    var w = window.open('', '_blank');
    if (!w) return;
    w.document.write('<!DOCTYPE html><html dir="rtl"><head><meta charset="utf-8"><title>QR - ' + esc(it.name) + '</title>' +
      '<style>body{font-family:Arial,sans-serif;text-align:center;padding:40px}h2{color:#002147;margin:12px 0 4px}p{color:#555;margin:2px}img{margin:16px auto;display:block}</style>' +
      '</head><body>' +
      '<h2>' + esc(it.name) + '</h2>' +
      (it.building ? '<p>' + esc(it.building) + (it.room ? ' · חדר ' + esc(it.room) : '') + '</p>' : '') +
      '<img src="' + esc(data) + '" width="240" height="240" alt="QR">' +
      '<p>יחידת הבטיחות · הטכניון</p>' +
      '</body></html>');
    w.document.close();
    printWhenReady(w, 0);
  }

  // A window opened with window.open('') inherits this page's CSP, so it can
  // not run inline <script>. The opener (same origin) triggers print instead,
  // once the popup document and its images have loaded.
  function printWhenReady(w, delay) {
    var tries = 0;
    (function wait() {
      var d;
      try { d = w.document; } catch (e) { return; } // popup closed
      if (!d || w.closed) return;
      var imgs = Array.prototype.slice.call(d.images || []);
      var ready = d.readyState === 'complete' && imgs.every(function (i) { return i.complete; });
      if (ready || tries++ > 50) {
        setTimeout(function () { try { w.focus(); w.print(); } catch (e) { /* closed */ } }, delay || 0);
        return;
      }
      setTimeout(wait, 100);
    })();
  }

  function statusCls(s) {
    if (/תקול|פסול|faulty/i.test(s)) return 'danger';
    if (/תחזוקה|maintenance/i.test(s)) return 'warn';
    return 'ok';
  }

  // ---- QR STICKERS (batch sticker issuing) ---------------------------------
  // Mirrors the old qr-generator.html: pick equipment, print a sheet of
  // peel-and-stick labels. Each label shows the asset number + QR (-> bridge)
  // + name + location. Monday numbers are preserved; new items print their
  // new G-#### number. Requires edit; token/code backfill requires manage.

  var STICKER_SIZES = {
    small:  { label: 'קטן (4 בשורה)',  qr: 150, w: 46, cols: 4 },
    medium: { label: 'בינוני (3 בשורה)', qr: 190, w: 62, cols: 3 },
    large:  { label: 'גדול (2 בשורה)',  qr: 240, w: 92, cols: 2 }
  };

  GSM.registerView('qr', function (mount) {
    if (!GSM.can('edit')) { GSM.navigate('/dashboard'); return; }
    mount.innerHTML = chrome('qr', loading());
    var main = mount.querySelector('.gs-main');

    // Ensure every item has a token + asset number before we draw labels.
    // Manage-only; a lab manager simply prints what already exists.
    var prep = GSM.can('manage')
      ? api('/qr/prepare', { method: 'POST' }).catch(function () { return null; })
      : Promise.resolve(null);

    prep.then(function () {
      return api('/equipment?per_page=1000');
    }).then(function (items) {
      items = (items || []).slice().sort(function (a, b) {
        return String(a.name || '').localeCompare(String(b.name || ''), 'he');
      });

      // Building filter options.
      var buildings = {};
      items.forEach(function (it) { if (it.building) buildings[it.building] = 1; });
      var buildingOpts = '<option value="">כל הבניינים</option>' +
        Object.keys(buildings).sort().map(function (b) {
          return '<option value="' + esc(b) + '">' + esc(b) + '</option>';
        }).join('');

      var sizeOpts = Object.keys(STICKER_SIZES).map(function (k) {
        return '<option value="' + k + '"' + (k === 'medium' ? ' selected' : '') + '>' + esc(STICKER_SIZES[k].label) + '</option>';
      }).join('');

      var rows = items.map(function (it) {
        var loc = [it.building, it.room ? 'חדר ' + it.room : ''].filter(Boolean).join(' · ');
        var ready = it.qr_token ? '' : ' data-notoken="1"';
        return '<tr class="gsm-qr-row"' + ready + ' data-id="' + it.id + '" data-building="' + esc(it.building || '') + '">' +
          '<td style="width:36px;text-align:center"><input type="checkbox" class="gsm-qr-cb" ' +
            'data-token="' + esc(it.qr_token || '') + '" data-code="' + esc(it.asset_code || '') + '" ' +
            'data-name="' + esc(it.name || '') + '" data-loc="' + esc(loc) + '"' + (it.qr_token ? '' : ' disabled') +
            ' aria-label="' + esc('בחירת מדבקה: ' + (it.asset_code ? it.asset_code + ' ' : '') + (it.name || '')) + '"></td>' +
          '<td><span class="gs-code">' + esc(it.asset_code || '—') + '</span></td>' +
          '<td>' + esc(it.name || '') + '</td>' +
          '<td>' + esc(loc || '—') + '</td>' +
          '<td>' + (it.qr_token ? '<span class="gs-pill gs-pill--ok">מוכן</span>' : '<span class="gs-pill gs-pill--warn">אין QR</span>') + '</td>' +
          '</tr>';
      }).join('');

      main.innerHTML =
        '<div class="gs-toolbar"><h2 class="gs-h2">מדבקות QR להנפקה</h2></div>' +
        '<div class="gs-card">' +
        '<p style="color:var(--gs-muted);margin:0 0 14px">בחרו ציוד → "צור גיליון להדפסה". כל מדבקה כוללת את <b>מספר הציוד</b>, קוד QR, שם ומיקום. מספרי Monday הקיימים נשמרים; ציוד חדש מקבל מספר חדש (G-####).</p>' +
        '<div class="gs-qr-controls">' +
        '<input class="gs-input" id="gsm-qr-search" placeholder="חיפוש…" style="max-width:220px">' +
        '<select class="gs-select" id="gsm-qr-building" style="max-width:200px" aria-label="סינון לפי בניין">' + buildingOpts + '</select>' +
        '<select class="gs-select" id="gsm-qr-size" style="max-width:200px" aria-label="גודל מדבקה">' + sizeOpts + '</select>' +
        '<button class="gs-btn gs-btn--ghost gs-btn--sm" id="gsm-qr-all">בחר הכל</button>' +
        '<button class="gs-btn gs-btn--ghost gs-btn--sm" id="gsm-qr-none">נקה</button>' +
        '<span class="gs-qr-count" id="gsm-qr-count" role="status" aria-live="polite">0 נבחרו</span>' +
        '<button class="gs-btn gs-btn--gold" id="gsm-qr-make">🖨️ צור גיליון להדפסה</button>' +
        '</div>' +
        '<table class="gs-table gs-qr-table"><thead><tr>' +
        '<th></th><th>מספר</th><th>שם</th><th>מיקום</th><th>QR</th>' +
        '</tr></thead><tbody id="gsm-qr-body">' +
        (rows || '<tr><td colspan="5"><div class="gs-empty">אין ציוד</div></td></tr>') +
        '</tbody></table></div>';

      wireStickerControls();
    }).catch(function (e) { main.innerHTML = '<div class="gs-error" role="alert">' + esc(e.message) + '</div>'; });
  });

  function wireStickerControls() {
    var body = document.getElementById('gsm-qr-body');
    var countEl = document.getElementById('gsm-qr-count');

    function visibleRows() {
      return Array.prototype.filter.call(body.querySelectorAll('.gsm-qr-row'), function (tr) {
        return tr.style.display !== 'none';
      });
    }
    function updateCount() {
      var n = body.querySelectorAll('.gsm-qr-cb:checked').length;
      if (countEl) countEl.textContent = n + ' נבחרו';
    }
    function applyFilter() {
      var q = (document.getElementById('gsm-qr-search').value || '').toLowerCase();
      var b = document.getElementById('gsm-qr-building').value || '';
      body.querySelectorAll('.gsm-qr-row').forEach(function (tr) {
        var okText = tr.textContent.toLowerCase().indexOf(q) > -1;
        var okB = !b || tr.getAttribute('data-building') === b;
        tr.style.display = (okText && okB) ? '' : 'none';
      });
    }

    document.getElementById('gsm-qr-search').addEventListener('input', applyFilter);
    document.getElementById('gsm-qr-building').addEventListener('change', applyFilter);
    body.addEventListener('change', function (e) { if (e.target.classList.contains('gsm-qr-cb')) updateCount(); });

    document.getElementById('gsm-qr-all').addEventListener('click', function () {
      visibleRows().forEach(function (tr) {
        var cb = tr.querySelector('.gsm-qr-cb');
        if (cb && !cb.disabled) cb.checked = true;
      });
      updateCount();
    });
    document.getElementById('gsm-qr-none').addEventListener('click', function () {
      body.querySelectorAll('.gsm-qr-cb').forEach(function (cb) { cb.checked = false; });
      updateCount();
    });

    document.getElementById('gsm-qr-make').addEventListener('click', function () {
      var picked = Array.prototype.map.call(body.querySelectorAll('.gsm-qr-cb:checked'), function (cb) {
        return {
          token: cb.getAttribute('data-token'),
          code: cb.getAttribute('data-code'),
          name: cb.getAttribute('data-name'),
          loc: cb.getAttribute('data-loc')
        };
      }).filter(function (x) { return x.token; });

      if (!picked.length) { alert('לא נבחר ציוד עם קוד QR.'); return; }
      var sizeKey = document.getElementById('gsm-qr-size').value || 'medium';
      buildStickerSheet(picked, STICKER_SIZES[sizeKey] || STICKER_SIZES.medium);
    });
  }

  // Render a QR for `url` and return a PNG data URL (synchronous).
  function qrDataUrl(url, size) {
    if (!GSM.qrPng) return '';
    try { return GSM.qrPng(url, size); } catch (e) { return ''; }
  }

  // Open a print window with a sheet of peel-and-stick labels.
  function buildStickerSheet(items, size) {
    var labels = items.map(function (it) {
      var url = bridgeUrl(it.token);
      var img = qrDataUrl(url, size.qr);
      return '<div class="lbl">' +
        '<div class="code">' + esc(it.code || '') + '</div>' +
        (img ? '<img class="qr" src="' + img + '" alt="QR">' : '<div class="qr qr--missing">QR</div>') +
        '<div class="nm">' + esc(it.name || '') + '</div>' +
        (it.loc ? '<div class="loc">' + esc(it.loc) + '</div>' : '') +
        '<div class="org">יחידת הבטיחות · הטכניון</div>' +
        '</div>';
    }).join('');

    var w = window.open('', '_blank');
    if (!w) { alert('חלון ההדפסה נחסם. אפשרו חלונות קופצים ונסו שוב.'); return; }
    var css =
      '@page{margin:8mm}' +
      '*{box-sizing:border-box}' +
      'body{font-family:Rubik,Arial,sans-serif;margin:0;padding:6mm;direction:rtl}' +
      '.sheet{display:flex;flex-wrap:wrap;gap:4mm;align-content:flex-start}' +
      '.lbl{width:' + size.w + 'mm;border:1px dashed #b9c2cc;border-radius:3mm;padding:3mm 2mm;' +
        'display:flex;flex-direction:column;align-items:center;text-align:center;page-break-inside:avoid;break-inside:avoid}' +
      '.code{font-weight:800;color:#002147;font-size:' + (size.w >= 90 ? '18' : (size.w >= 62 ? '15' : '12')) + 'px;margin-bottom:1.5mm;letter-spacing:.3px}' +
      '.qr{width:' + (size.w - 10) + 'mm;height:' + (size.w - 10) + 'mm;display:block}' +
      '.qr--missing{display:flex;align-items:center;justify-content:center;border:1px solid #ccc;color:#999}' +
      '.nm{font-weight:600;color:#1a1a1a;font-size:' + (size.w >= 90 ? '13' : '11') + 'px;margin-top:1.5mm;line-height:1.2}' +
      '.loc{color:#555;font-size:' + (size.w >= 90 ? '11' : '9') + 'px;margin-top:.5mm}' +
      '.org{color:#8a94a0;font-size:8px;margin-top:1mm}' +
      '@media print{.noprint{display:none}}';
    w.document.write(
      '<!DOCTYPE html><html dir="rtl" lang="he"><head><meta charset="utf-8">' +
      '<title>מדבקות QR — יחידת הבטיחות</title>' +
      '<link href="' + esc(GSM.asset('css/fonts.css')) + '" rel="stylesheet">' +
      '<style>' + css + '</style></head><body>' +
      '<div class="noprint" style="padding:8px 6mm;background:#002147;color:#fff;display:flex;justify-content:space-between;align-items:center;margin:-6mm -6mm 6mm">' +
      '<span>' + items.length + ' מדבקות · מוכן להדפסה</span>' +
      '<button type="button" id="gsm-print-now" style="background:#f0b323;border:0;padding:8px 18px;border-radius:6px;font-weight:700;cursor:pointer">🖨️ הדפס</button>' +
      '</div>' +
      '<div class="sheet">' + labels + '</div>' +
      '</body></html>'
    );
    w.document.close();
    var printNow = w.document.getElementById('gsm-print-now');
    if (printNow) printNow.addEventListener('click', function () { w.print(); });
    printWhenReady(w, 400);
  }

  // ---- FAULTS --------------------------------------------------------------

  GSM.registerView('faults', function (mount) {
    mount.innerHTML = chrome('faults', loading());
    var main = mount.querySelector('.gs-main');
    api('/faults?per_page=200').then(function (items) {
      var rows = (items || []).map(function (f) {
        return '<tr><td>' + esc(f.equipment_id || '') + '</td><td>' + esc(f.description || '') + '</td>' +
          '<td><span class="gs-pill gs-pill--' + priCls(f.priority) + '">' + esc(priLabel(f.priority)) + '</span></td>' +
          '<td><span class="gs-pill gs-pill--' + statCls(f.status) + '">' + esc(statLabel(f.status)) + '</span></td>' +
          '<td>' + esc(f.opened_date || '') + '</td>' +
          '<td>' + (GSM.can('edit') ? '<button class="gs-btn gs-btn--sm gs-btn--ghost" data-fedit="' + f.id + '">ערוך</button>' : '') + '</td></tr>';
      }).join('');
      var addBtn = GSM.can('report') ? '<button class="gs-btn gs-btn--gold" id="gsm-ft-add">+ דווח תקלה</button>' : '';
      main.innerHTML =
        '<div class="gs-toolbar"><h2 class="gs-h2">תקלות</h2><div class="gs-toolbar__actions">' + exportBtn('faults') + addBtn + '</div></div>' +
        '<table class="gs-table"><thead><tr><th>ציוד</th><th>תיאור</th><th>עדיפות</th><th>סטטוס</th><th>נפתחה</th><th></th></tr></thead>' +
        '<tbody>' + (rows || '<tr><td colspan="6"><div class="gs-empty">אין תקלות</div></td></tr>') + '</tbody></table>';
      var add = document.getElementById('gsm-ft-add');
      if (add) add.addEventListener('click', function () { GSM_Forms.openFault(null, function () { GSM.navigate('/faults'); }); });
      document.querySelectorAll('[data-fedit]').forEach(function (b) {
        b.addEventListener('click', function () {
          api('/faults/' + b.getAttribute('data-fedit')).then(function (f) { GSM_Forms.openFault(f, function () { GSM.navigate('/faults'); }); });
        });
      });
    }).catch(function (e) { main.innerHTML = '<div class="gs-error" role="alert">' + esc(e.message) + '</div>'; });
  });

  function priCls(p) { return (p === 'urgent' || p === 'high') ? 'danger' : (p === 'normal' ? 'warn' : 'ok'); }
  function priLabel(p) { return { low: 'נמוך', normal: 'רגיל', high: 'גבוה', urgent: 'דחוף' }[p] || p; }
  function statCls(s) { return (s === 'resolved' || s === 'closed') ? 'ok' : 'warn'; }
  function statLabel(s) { return { open: 'פתוח', in_progress: 'בטיפול', resolved: 'טופל', closed: 'סגור' }[s] || s; }

  // ---- INSPECTIONS ---------------------------------------------------------

  GSM.registerView('inspections', function (mount) {
    mount.innerHTML = chrome('inspections', loading());
    var main = mount.querySelector('.gs-main');
    api('/inspections?per_page=200').then(function (items) {
      var rows = (items || []).map(function (i) {
        return '<tr><td>' + esc(i.equipment_id || '') + '</td><td>' + esc(typeLabel(i.type)) + '</td>' +
          '<td>' + esc(i.inspection_date || '') + '</td><td>' + esc(i.inspector || '') + '</td>' +
          '<td><span class="gs-pill gs-pill--' + (i.result === 'defects' ? 'danger' : 'ok') + '">' +
          esc(i.result === 'defects' ? 'ליקויים' : 'תקין') + '</span></td></tr>';
      }).join('');
      var addBtn = GSM.can('report') ? '<button class="gs-btn gs-btn--gold" id="gsm-in-add">+ ביקורת חדשה</button>' : '';
      main.innerHTML =
        '<div class="gs-toolbar"><h2 class="gs-h2">ביקורות</h2><div class="gs-toolbar__actions">' + exportBtn('inspections') + addBtn + '</div></div>' +
        '<table class="gs-table"><thead><tr><th>ציוד</th><th>סוג</th><th>תאריך</th><th>מבצע</th><th>תוצאה</th></tr></thead>' +
        '<tbody>' + (rows || '<tr><td colspan="5"><div class="gs-empty">אין ביקורות</div></td></tr>') + '</tbody></table>';
      var add = document.getElementById('gsm-in-add');
      if (add) add.addEventListener('click', function () { GSM_Forms.openInspection(null, function () { GSM.navigate('/inspections'); }); });
    }).catch(function (e) { main.innerHTML = '<div class="gs-error" role="alert">' + esc(e.message) + '</div>'; });
  });

  function typeLabel(t) { return { quarterly: 'רבעונית', monthly: 'חודשית', adhoc: 'אד-הוק' }[t] || t; }

  // ---- ADMIN ---------------------------------------------------------------

  // Canonical nav-item registry (for the role-perms matrix + menu show/hide).
  var GSM_NAV_ITEMS = [
    { id: 'dashboard', label: 'דשבורד', locked: true },
    { id: 'bi', label: 'BI וניתוח' },
    { id: 'reports', label: 'הנפקת דוחות' },
    { id: 'audit', label: 'יומן בקרה' },
    { id: 'quality', label: 'טיוב מידע' },
    { id: 'equipment', label: 'ניהול ציוד' },
    { id: 'myequip', label: 'הציוד שלי' },
    { id: 'contacts', label: 'אנשי קשר' },
    { id: 'panels', label: 'מספור פאנל' },
    { id: 'import', label: 'ייבוא ציוד' },
    { id: 'faults', label: 'תקלות' },
    { id: 'inspections', label: 'ביקורות' },
    { id: 'inspcfg', label: 'הגדרות ביקורת' },
    { id: 'qr', label: 'מדבקות QR' },
    { id: 'qrissue', label: 'ניהול הנפקות' },
    { id: 'profile', label: 'הפרופיל שלי', locked: true },
    { id: 'security', label: 'אבטחה' },
    { id: 'users', label: 'ניהול אנשי אחמ"ת', locked: true },
    { id: 'brsetup', label: 'עיצוב עמוד סריקה' },
    { id: 'database', label: 'מסד נתונים' },
    { id: 'restorepts', label: 'נקודות שחזור' },
    { id: 'legacy', label: 'משיכה מהאתר הישן' },
    { id: 'privacy', label: 'בקשות פרטיות' },
    { id: 'comm', label: 'תקשורת והתראות' },
    { id: 'integrations', label: 'אינטגרציות' },
    { id: 'admin', label: 'הגדרות אתר', locked: true },
    { id: 'help', label: 'עזרה ומדריך', locked: true }
  ];
  var GSM_ROLES = [['ahmat', 'אחמ"ת'], ['lab_manager', 'מנהל מעבדה'], ['pi', 'חוקר ראשי']];

  // ---- SSO (Microsoft Entra ID) settings ---------------------------------------
  function renderSsoSettings(host) {
    if (!host) return;
    var ROLES = { pi: 'חוקר ראשי', lab_manager: 'מנהל מעבדה', ahmat: 'אחמ"ת' };
    function draw(d, note) {
      var ready = d.tenant && d.client_id && d.has_secret;
      host.innerHTML =
        '<div class="gs-card" style="margin-top:16px"><div class="gs-card__title">🏢 כניסה ארגונית (SSO) — Microsoft Entra ID</div>' +
        (d.bypassed ? '<div class="gs-error" role="alert" style="margin-bottom:10px">הכניסה הארגונית מנוטרלת כרגע בהגדרות השרת (GSM_SSO_DISABLE).</div>' : '') +
        (!d.https && (d.enabled || d.has_secret) ? '<div class="gs-error" role="alert" style="margin-bottom:10px">האתר אינו פועל ב-https. Microsoft מקבלת כתובת חזרה ב-https בלבד (מלבד localhost).</div>' : '') +
        '<p style="font-size:13px;margin-top:0" role="status">' + (ready ? (d.enabled ? '<b>פעיל.</b> במסך הכניסה מופיע כפתור "כניסה עם החשבון הארגוני".' : 'מוגדר, כבוי.') : 'לא מוגדר.') + '</p>' +
        '<p style="color:var(--gs-muted);font-size:12.5px">צוות ה-IT רושם את המערכת ב-Entra (App registration מסוג Web, דייר יחיד) עם כתובת החזרה שלמטה, יוצר client secret, ומוסר את שלושת הערכים. ' +
        'האימות הנוסף (MFA) נאכף אצל Microsoft לפי מדיניות הארגון, ולכן כניסה ארגונית לא מבקשת את קוד האימות של המערכת.</p>' +
        '<div class="gs-field"><label class="gs-label" for="sso-redirect">כתובת חזרה (Redirect URI) לרישום ב-Entra</label><input class="gs-input" id="sso-redirect" readonly dir="ltr" value="' + esc(d.redirect_uri) + '" data-gsm-action="select" style="font-size:12px"></div>' +
        '<div class="gs-field"><label class="gs-label" for="sso-tenant">Directory (tenant) ID</label><input class="gs-input" id="sso-tenant" dir="ltr" autocomplete="off" spellcheck="false" value="' + esc(d.tenant || '') + '" placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx" style="max-width:420px"></div>' +
        '<div class="gs-field"><label class="gs-label" for="sso-client">Application (client) ID</label><input class="gs-input" id="sso-client" dir="ltr" autocomplete="off" spellcheck="false" value="' + esc(d.client_id || '') + '" placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx" style="max-width:420px"></div>' +
        '<div class="gs-field"><label class="gs-label" for="sso-secret">Client secret (הערך, לא המזהה)</label><input class="gs-input" id="sso-secret" type="password" dir="ltr" autocomplete="off" spellcheck="false" placeholder="' + (d.has_secret ? 'שמור. השאירו ריק כדי לא לשנות' : '') + '" style="max-width:420px"></div>' +
        '<div class="gs-field"><label class="gs-label" for="sso-domains">דומיינים מותרים (מופרדים בפסיק; ריק = כל חשבון בדייר)</label><input class="gs-input" id="sso-domains" dir="ltr" value="' + esc((d.domains || []).join(', ')) + '" placeholder="technion.ac.il" style="max-width:420px"></div>' +
        '<label style="display:flex;gap:8px;align-items:flex-start;margin:8px 0"><input type="checkbox" id="sso-auto"' + (d.auto_create ? ' checked' : '') + ' aria-describedby="sso-auto-help"><span><b>צור חשבון אוטומטית למי שנכנס בפעם הראשונה</b>' +
        '<span id="sso-auto-help" style="display:block;color:var(--gs-muted);font-size:12.5px">כבוי (מומלץ): רק מי שכבר הוגדר כמשתמש במערכת, עם אותו אימייל, יכול להיכנס. דלוק: כל בעל חשבון בדומיינים המותרים מקבל חשבון עם התפקיד שלמטה. מחייב רשימת דומיינים.</span></span></label>' +
        '<div class="gs-field"><label class="gs-label" for="sso-role">תפקיד לחשבון שנוצר אוטומטית</label><select class="gs-input" id="sso-role" style="max-width:220px">' +
        Object.keys(ROLES).map(function (k) { return '<option value="' + k + '"' + (d.default_role === k ? ' selected' : '') + '>' + esc(ROLES[k]) + '</option>'; }).join('') + '</select></div>' +
        '<label style="display:flex;gap:8px;align-items:center;margin:8px 0"><input type="checkbox" id="sso-enabled"' + (d.enabled ? ' checked' : '') + '><span><b>כניסה ארגונית פעילה</b></span></label>' +
        '<button type="button" class="gs-btn" id="sso-save">שמור</button> ' +
        (d.tenant ? '<button type="button" class="gs-btn gs-btn--ghost" id="sso-test">בדיקת חיבור ל-Microsoft</button> ' : '') +
        (d.has_secret ? '<button type="button" class="gs-btn gs-btn--ghost" id="sso-clear">הסר סוד וכבה</button> ' : '') +
        '<span id="sso-st" role="status" aria-live="polite" style="margin-inline-start:8px;color:var(--gs-muted)">' + esc(note || '') + '</span></div>';
      var st = document.getElementById('sso-st');
      function send(body, btn) {
        btn.disabled = true; st.textContent = 'שומר…';
        api('/sso', { method: 'POST', body: body }).then(function (nd) { draw(nd, '✓ נשמר.'); })
          .catch(function (e) { st.textContent = '✗ ' + (e.message || e); btn.disabled = false; });
      }
      document.getElementById('sso-save').addEventListener('click', function () {
        var body = { tenant: document.getElementById('sso-tenant').value.trim(), client_id: document.getElementById('sso-client').value.trim(),
          domains: document.getElementById('sso-domains').value, auto_create: document.getElementById('sso-auto').checked,
          default_role: document.getElementById('sso-role').value, enabled: document.getElementById('sso-enabled').checked };
        var sec = document.getElementById('sso-secret').value.trim();
        if (sec) body.secret = sec;
        send(body, this);
      });
      var clr = document.getElementById('sso-clear');
      if (clr) clr.addEventListener('click', function () {
        if (!confirm('להסיר את ה-client secret ולכבות את הכניסה הארגונית?')) return;
        send({ clear: true }, this);
      });
      var tb = document.getElementById('sso-test');
      if (tb) tb.addEventListener('click', function () {
        tb.disabled = true; st.textContent = 'בודק…';
        api('/sso/test', { method: 'POST' }).then(function (r) {
          tb.disabled = false;
          st.textContent = r.ok ? '✓ הדייר נמצא אצל Microsoft (' + (r.keys || 0) + ' מפתחות חתימה). כניסה מלאה נבדקת בלחיצה על הכפתור במסך הכניסה.'
            : (r.reason === 'unknown-tenant' ? '✗ Microsoft לא מכירה את ה-Directory ID הזה.' : '✗ אין תשובה מ-Microsoft (בדקו שהשרת יכול לגשת ל-login.microsoftonline.com).');
        }).catch(function (e) { st.textContent = '✗ ' + (e.message || e); tb.disabled = false; });
      });
    }
    api('/sso').then(function (d) { draw(d); }).catch(function (e) { host.innerHTML = '<div class="gs-error" role="alert">' + esc(e.message || e) + '</div>'; });
  }

  // ---- reCAPTCHA settings ----------------------------------------------------
  function renderCaptchaSettings(host, msg) {
    if (!host) return;
    function draw(d, note) {
      var state = !d.site_key || !d.has_secret ? 'לא מוגדר.'
        : (!d.enabled ? 'מוגדר, כבוי.' : (d.enforce ? '<b>פעיל, מצב אכיפה</b>: בקשות חשודות נחסמות.' : '<b>פעיל, מצב ניטור</b>: הבדיקה רצה אבל לא חוסמת.'));
      host.innerHTML =
        '<div class="gs-card" style="margin-top:16px"><div class="gs-card__title">🛡 reCAPTCHA (Google) — הגנה מבוטים</div>' +
        (d.bypassed ? '<div class="gs-error" role="alert" style="margin-bottom:10px">reCAPTCHA מנוטרל כרגע בהגדרות השרת (GSM_CAPTCHA_DISABLE).</div>' : '') +
        '<p style="font-size:13px;margin-top:0" role="status">' + state + (d.tested_at ? ' בדיקה מוצלחת אחרונה: <bdi dir="ltr">' + esc(d.tested_at.slice(0, 16)) + '</bdi>.' : '') + '</p>' +
        '<p style="color:var(--gs-muted);font-size:12.5px">reCAPTCHA v3, בלי תיבת סימון. מגן על הכניסה בסיסמה ועל דיווח תקלה מעמוד הסריקה. הקוד של Google נטען רק כשהמשתמש לוחץ על הכפתור המוגן, ולצד הכפתור מוצגת הודעה על כך. ' +
        'מידע טכני על הלוחץ עובר ל-Google, ולכן נדרשים עדכון של מדיניות הפרטיות ובדיקה משפטית לפני ההפעלה. את המפתחות יוצרים בקונסולת reCAPTCHA של Google (סוג v3), עם שם המתחם של האתר.</p>' +
        '<div class="gs-field"><label class="gs-label" for="cap-site">מפתח אתר (Site Key)</label><input class="gs-input" id="cap-site" dir="ltr" autocomplete="off" spellcheck="false" value="' + esc(d.site_key || '') + '" style="max-width:460px"></div>' +
        '<div class="gs-field"><label class="gs-label" for="cap-secret">מפתח סודי (Secret Key)</label><input class="gs-input" id="cap-secret" type="password" dir="ltr" autocomplete="off" spellcheck="false" placeholder="' + (d.has_secret ? 'שמור. השאירו ריק כדי לא לשנות' : '') + '" style="max-width:460px"></div>' +
        '<div class="gs-field"><label class="gs-label" for="cap-score">ציון מינימלי (0.1 מקל, 0.9 מחמיר)</label><input class="gs-input" id="cap-score" type="number" min="0.1" max="0.9" step="0.1" value="' + esc(d.min_score) + '" style="max-width:120px"></div>' +
        '<fieldset style="border:0;padding:0;margin:0 0 8px"><legend class="gs-label">על מה להגן</legend>' +
        '<label style="display:flex;gap:8px;align-items:center;margin:4px 0"><input type="checkbox" id="cap-login"' + (d.login ? ' checked' : '') + '><span>כניסה בסיסמה</span></label>' +
        '<label style="display:flex;gap:8px;align-items:center;margin:4px 0"><input type="checkbox" id="cap-bridge"' + (d.bridge ? ' checked' : '') + '><span>דיווח תקלה מעמוד הסריקה</span></label></fieldset>' +
        '<label style="display:flex;gap:8px;align-items:center;margin:8px 0"><input type="checkbox" id="cap-enabled"' + (d.enabled ? ' checked' : '') + '><span><b>reCAPTCHA פעיל</b></span></label>' +
        '<label style="display:flex;gap:8px;align-items:flex-start;margin:8px 0"><input type="checkbox" id="cap-enforce"' + (d.enforce ? ' checked' : '') + (d.tested_at ? '' : ' disabled') + ' aria-describedby="cap-enforce-help"><span><b>מצב אכיפה (חסימה בפועל)</b>' +
        '<span id="cap-enforce-help" style="display:block;color:var(--gs-muted);font-size:12.5px">אפשר להדליק רק אחרי "בדיקה" מוצלחת עם המפתחות השמורים, כדי לא לנעול את הכניסה. אם Google לא זמין, הבקשה עוברת.</span></span></label>' +
        '<button type="button" class="gs-btn" id="cap-save">שמור</button> ' +
        (d.site_key && d.has_secret ? '<button type="button" class="gs-btn gs-btn--ghost" id="cap-test">בדיקה מול Google</button> <button type="button" class="gs-btn gs-btn--ghost" id="cap-clear">הסר מפתחות</button> ' : '') +
        '<span id="cap-st" role="status" aria-live="polite" style="margin-inline-start:8px;color:var(--gs-muted)">' + esc(note || '') + '</span></div>';
      var st = document.getElementById('cap-st');
      function send(body, btn, after) {
        btn.disabled = true; st.textContent = 'שומר…';
        api('/captcha', { method: 'POST', body: body }).then(function (nd) { if (after) after(nd); else draw(nd, '✓ נשמר.'); })
          .catch(function (e) { st.textContent = '✗ ' + (e.message || e); btn.disabled = false; });
      }
      document.getElementById('cap-save').addEventListener('click', function () {
        var body = { site_key: document.getElementById('cap-site').value.trim(), min_score: parseFloat(document.getElementById('cap-score').value) || 0.5,
          login: document.getElementById('cap-login').checked, bridge: document.getElementById('cap-bridge').checked,
          enabled: document.getElementById('cap-enabled').checked, enforce: document.getElementById('cap-enforce').checked };
        var sec = document.getElementById('cap-secret').value.trim();
        if (sec) body.secret = sec;
        var keysChanged = !!sec || body.site_key !== (d.site_key || '');
        send(body, this, keysChanged ? function () {
          // The page's security policy allows Google only from the next load.
          try { window.sessionStorage.setItem('gsm_cap_note', '1'); } catch (e) { /* ignore */ }
          window.location.reload();
        } : null);
      });
      var clr = document.getElementById('cap-clear');
      if (clr) clr.addEventListener('click', function () {
        if (!confirm('להסיר את מפתחות reCAPTCHA ולכבות את ההגנה?')) return;
        send({ clear: true, enforce: false }, this);
      });
      var tb = document.getElementById('cap-test');
      if (tb) tb.addEventListener('click', function () {
        if (!window.GSM_captchaToken) { st.textContent = 'רעננו את הדף ונסו שוב.'; return; }
        tb.disabled = true; st.textContent = 'בודק מול Google…';
        window.GSM_captchaToken('test', d.site_key).then(function (tok) {
          if (!tok) { st.textContent = '✗ לא התקבל אסימון מ-Google. בדקו את מפתח האתר ואת שם המתחם בקונסולת reCAPTCHA.'; tb.disabled = false; return null; }
          return api('/captcha/test', { method: 'POST', body: { token: tok } }).then(function (r) {
            draw(r.config, r.works ? '✓ הבדיקה הצליחה' + (r.score != null ? ' (ציון ' + r.score + ')' : '') + '. אפשר להדליק מצב אכיפה.' : '✗ הבדיקה נכשלה: ' + (r.reason || ''));
          });
        }).catch(function (e) { st.textContent = '✗ ' + (e.message || e); tb.disabled = false; });
      });
    }
    api('/captcha').then(function (d) {
      var note = msg || '';
      try { if (window.sessionStorage.getItem('gsm_cap_note')) { window.sessionStorage.removeItem('gsm_cap_note'); note = '✓ המפתחות נשמרו. עכשיו אפשר להריץ "בדיקה מול Google".'; } } catch (e) { /* ignore */ }
      draw(d, note);
    }).catch(function (e) { host.innerHTML = '<div class="gs-error" role="alert">' + esc(e.message || e) + '</div>'; });
  }

  // ---- Chat widget (Botwa) settings -----------------------------------------
  function renderChatSettings(host) {
    if (!host) return;
    function draw(d) {
      var where = Object.keys(d.placements || {}).map(function (k) {
        return '<label style="display:flex;gap:8px;align-items:center;margin:4px 0"><input type="checkbox" class="chat-where" data-k="' + esc(k) + '"' +
          (d.where && d.where[k] ? ' checked' : '') + '><span>' + esc(d.placements[k]) + '</span></label>';
      }).join('');
      host.innerHTML =
        '<div class="gs-card" style="margin-top:16px"><div class="gs-card__title">💬 צ׳אט (Botwa)</div>' +
        '<p style="font-size:13px;margin-top:0" role="status">' + (d.configured
          ? ('מוגדר: <bdi dir="ltr">' + esc(d.origin) + '</bdi> · מפתח <bdi dir="ltr">' + esc(d.key_masked) + '</bdi> · ' + (d.enabled ? '<b>פעיל</b>' : 'כבוי'))
          : 'לא מוגדר. הדביקו את קוד ההטמעה שקיבלתם מספק הצ׳אט.') + '</p>' +
        '<div class="gs-field"><label class="gs-label" for="chat-snippet">קוד ההטמעה (‎&lt;script src=&quot;…/widget.js&quot; data-key=&quot;wc_…&quot;&gt;‎)</label>' +
        '<textarea class="gs-textarea" id="chat-snippet" rows="3" style="direction:ltr;font-family:monospace;font-size:12px" placeholder="&lt;script src=&quot;https://…/widget.js&quot; data-key=&quot;wc_…&quot; async&gt;&lt;/script&gt;"></textarea></div>' +
        '<div class="gs-field"><label class="gs-label" for="chat-label">טקסט הכפתור</label>' +
        '<input class="gs-input" id="chat-label" maxlength="40" value="' + esc(d.label || '') + '" style="max-width:240px"></div>' +
        '<fieldset style="border:0;padding:0;margin:0 0 10px"><legend class="gs-label">איפה להציג</legend>' + where + '</fieldset>' +
        '<label style="display:flex;gap:8px;align-items:center;margin:8px 0"><input type="checkbox" id="chat-enabled"' + (d.enabled ? ' checked' : '') + '><span><b>הצ׳אט פעיל</b></span></label>' +
        '<p style="color:var(--gs-muted);font-size:12px">הכפתור נטען מהמערכת עצמה. רק אחרי <b>לחיצה</b> הוא פונה לשרת הצ׳אט, כך שלמבקרים שלא פתחו את הצ׳אט לא נשלח דבר לספק. ' +
        'ההודעות שהמשתמש כותב בצ׳אט עוברות לספק החיצוני, ולכן נדרשים הסכם עיבוד מידע (DPA) מולו ועדכון מדיניות הפרטיות. המערכת לא שולחת לספק שם, אימייל או טלפון של המשתמש.</p>' +
        '<button type="button" class="gs-btn" id="chat-save">שמור</button> ' +
        (d.configured ? '<button type="button" class="gs-btn gs-btn--ghost" id="chat-clear">הסר את הגדרות הצ׳אט</button> ' : '') +
        '<span id="chat-status" role="status" aria-live="polite" style="margin-inline-start:8px;color:var(--gs-muted)"></span></div>';
      function send(body, btn) {
        var st = document.getElementById('chat-status'); btn.disabled = true; st.textContent = 'שומר…';
        api('/chat', { method: 'POST', body: body })
          .then(function (nd) { draw(nd); document.getElementById('chat-status').textContent = '✓ נשמר. הצ׳אט יופיע בטעינה הבאה של הדפים.'; })
          .catch(function (e) { st.textContent = '✗ ' + (e.message || e); btn.disabled = false; });
      }
      document.getElementById('chat-save').addEventListener('click', function () {
        var w = {};
        document.querySelectorAll('.chat-where').forEach(function (c) { w[c.getAttribute('data-k')] = c.checked; });
        var body = { label: document.getElementById('chat-label').value, where: w, enabled: document.getElementById('chat-enabled').checked };
        var sn = document.getElementById('chat-snippet').value.trim();
        if (sn) body.snippet = sn;
        send(body, this);
      });
      var clr = document.getElementById('chat-clear');
      if (clr) clr.addEventListener('click', function () {
        if (!confirm('להסיר את הגדרות הצ׳אט? הצ׳אט ייעלם מכל הדפים.')) return;
        send({ clear: true }, this);
      });
    }
    api('/chat').then(draw).catch(function (e) {
      host.innerHTML = '<div class="gs-error" role="alert">' + esc(e.message || e) + '</div>';
    });
  }

  // ---- Data retention (privacy: storage limitation) ----------------------
  function renderRetention(host) {
    if (!host) return;
    function draw(d) {
      var keys = Object.keys(d.labels || {});
      var rows = keys.map(function (k) {
        var id = 'ret-' + k;
        return '<div class="gs-field" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">' +
          '<label class="gs-label" for="' + id + '" style="flex:1 1 220px;margin:0">' + esc(d.labels[k]) + '</label>' +
          '<input class="gs-input" id="' + id + '" type="number" min="0" max="1200" step="1" inputmode="numeric" value="' + esc(d.config[k]) + '" style="max-width:110px" aria-describedby="ret-help">' +
          '<span style="color:var(--gs-muted);font-size:13px">חודשים · יימחקו בהרצה הבאה: <b>' + esc((d.preview || {})[k] || 0) + '</b></span></div>';
      }).join('');
      var last = d.last && d.last._at
        ? ('הרצה אחרונה: <bdi dir="ltr">' + esc(d.last._at) + '</bdi> (' + (d.last._trigger === 'auto' ? 'אוטומטית' : 'ידנית') + ') — נמחקו: ' +
           keys.map(function (k) { return esc(d.labels[k]) + ' ' + esc(d.last[k] || 0); }).join(', '))
        : 'טרם בוצעה הרצה.';
      var state = d.active
        ? 'הניקוי האוטומטי פעיל ורץ פעם ביום.'
        : ('הניקוי האוטומטי יתחיל ב-<bdi dir="ltr">' + esc(d.active_from) + '</bdi>. עד אז אפשר לשנות את התקופות, ושום דבר לא יימחק אוטומטית.');
      host.innerHTML =
        '<div class="gs-card" style="margin-top:16px"><div class="gs-card__title">🗂 מדיניות שמירת מידע</div>' +
        '<p id="ret-help" style="color:var(--gs-muted);font-size:13px;margin-top:0">רשומות ישנות מהתקופה שנקבעה נמחקות אוטומטית (צמצום שמירת מידע אישי). <b>0 = שמירה ללא הגבלה.</b> ' +
        'בקשות מחיקה פתוחות לעולם לא נמחקות. כל ניקוי נרשם ביומן הבקרה. <b>מחיקה היא בלתי הפיכה</b>: הרשומות האלה לא כלולות בנקודות השחזור.</p>' +
        '<p style="font-size:13px" role="status">' + state + '</p>' +
        rows +
        '<div style="margin-top:8px"><button type="button" class="gs-btn" id="ret-save">שמור תקופות</button> ' +
        '<button type="button" class="gs-btn gs-btn--danger" id="ret-run">הרץ ניקוי עכשיו</button> ' +
        '<span id="ret-status" role="status" aria-live="polite" style="margin-inline-start:8px;color:var(--gs-muted)"></span></div>' +
        '<p style="color:var(--gs-muted);font-size:12px;margin-top:10px">' + last + '</p>' +
        '<p style="color:var(--gs-muted);font-size:12px">ברירות המחדל (24 / 24 / 84 / 24 חודשים) הן נקודת פתיחה שמרנית, ויש לאשר אותן מול ממונה הפרטיות או היועץ המשפטי.</p>' +
        '<div style="margin-top:12px;padding-top:12px;border-top:1px solid var(--gs-border)"><b style="font-size:14px">איפוס סטטיסטיקת סריקות</b>' +
        '<p id="scan-reset-help" style="color:var(--gs-muted);font-size:12.5px;margin:4px 0 8px">מוחק את כל רשומות הסריקה: הכיסוי, הגרף ודוח הסריקות בדשבורד מתחילים מאפס. לא משפיע על הציוד, על הביקורות או על הנפקות ה-QR. הפעולה בלתי הפיכה.</p>' +
        '<button type="button" class="gs-btn gs-btn--sm gs-btn--danger" id="scan-reset" aria-describedby="scan-reset-help">אפס סטטיסטיקת סריקות</button> ' +
        '<span id="scan-reset-st" role="status" aria-live="polite" style="margin-inline-start:8px;color:var(--gs-muted)"></span></div>' +
        '</div>';
      document.getElementById('scan-reset').addEventListener('click', function () {
        if (!confirm('לאפס את כל סטטיסטיקת הסריקות? הפעולה בלתי הפיכה.')) return;
        if (!confirm('אישור אחרון: כל רשומות הסריקה יימחקו.')) return;
        var b = this, st = document.getElementById('scan-reset-st'); b.disabled = true; st.textContent = 'מאפס…';
        api('/retention/reset-scans', { method: 'POST', body: { confirm: 'RESET' } })
          .then(function (r) { st.textContent = '✓ נמחקו ' + (r.deleted || 0) + ' רשומות סריקה.'; b.disabled = false; })
          .catch(function (e) { st.textContent = '✗ ' + (e.message || e); b.disabled = false; });
      });
      function collect() {
        var out = {};
        keys.forEach(function (k) { var v = parseInt(document.getElementById('ret-' + k).value, 10); out[k] = isNaN(v) || v < 0 ? 0 : v; });
        return out;
      }
      document.getElementById('ret-save').addEventListener('click', function () {
        var b = this, st = document.getElementById('ret-status'); b.disabled = true; st.textContent = 'שומר…';
        api('/retention', { method: 'POST', body: { config: collect() } })
          .then(function (nd) { draw(nd); document.getElementById('ret-status').textContent = '✓ נשמר'; })
          .catch(function (e) { st.textContent = '✗ ' + (e.message || e); b.disabled = false; });
      });
      document.getElementById('ret-run').addEventListener('click', function () {
        var pv = d.preview || {};
        var n = keys.reduce(function (a, k) { return a + (pv[k] || 0); }, 0);
        if (!confirm('למחוק עכשיו ' + n + ' רשומות ישנות לפי התקופות השמורות? הפעולה בלתי הפיכה.\n(שינויים שלא נשמרו לא ישפיעו. כדאי לשמור קודם.)')) return;
        var b = this, st = document.getElementById('ret-status'); b.disabled = true; st.textContent = 'מנקה…';
        api('/retention/run', { method: 'POST' })
          .then(function (nd) {
            draw(nd);
            var r = nd.result || {}, tot = keys.reduce(function (a, k) { return a + (r[k] || 0); }, 0);
            document.getElementById('ret-status').textContent = '✓ נמחקו ' + tot + ' רשומות';
          })
          .catch(function (e) { st.textContent = '✗ ' + (e.message || e); b.disabled = false; });
      });
    }
    api('/retention').then(draw).catch(function (e) {
      host.innerHTML = '<div class="gs-error" role="alert">' + esc(e.message || e) + '</div>';
    });
  }

  function refreshNav() { GSM.checkSession().then(function () { GSM.render(); }); }

  function renderGovernance(container) {
    Promise.all([
      api('/settings/role-perms').catch(function () { return { perms: {} }; }),
      api('/settings/menu').catch(function () { return { hidden: [] }; })
    ]).then(function (res) {
      var perms = (res[0] && res[0].perms) || {};
      var hidden = (res[1] && res[1].hidden) || [];

      var head = '<th>פריט</th>' + GSM_ROLES.map(function (r) { return '<th>' + esc(r[1]) + '</th>'; }).join('');
      var rows = GSM_NAV_ITEMS.map(function (it) {
        var cells = GSM_ROLES.map(function (r) {
          var list = perms[r[0]];
          var checked = it.locked || (list ? list.indexOf(it.id) > -1 : true);
          return '<td style="text-align:center"><input type="checkbox" class="gsm-pm" data-role="' + r[0] + '" data-id="' + it.id + '"' + (checked ? ' checked' : '') + (it.locked ? ' disabled' : '') +
            ' aria-label="' + esc(it.label + ' — ' + r[1]) + '"></td>';
        }).join('');
        return '<tr><td>' + esc(it.label) + '</td>' + cells + '</tr>';
      }).join('');
      var matrix = '<div class="gs-card" style="margin-top:16px"><div class="gs-card__title">🔐 הרשאות לפי תפקיד</div>' +
        '<p style="color:var(--gs-muted);font-size:13px">סמן אילו פריטי תפריט כל תפקיד רואה. מנהל רואה הכול. פריטים נעולים תמיד מוצגים.</p>' +
        '<table class="gs-table"><thead><tr>' + head + '</tr></thead><tbody>' + rows + '</tbody></table>' +
        '<button class="gs-btn" id="pm-save">שמור הרשאות</button> <button class="gs-btn gs-btn--ghost" id="pm-reset">אפס לברירת מחדל</button> <span id="pm-status" role="status" aria-live="polite" style="margin-inline-start:8px;color:var(--gs-muted)"></span></div>';

      var menuRows = GSM_NAV_ITEMS.map(function (it) {
        var visible = hidden.indexOf(it.id) === -1;
        return '<label style="display:inline-flex;gap:6px;align-items:center;margin:0 0 8px 16px"><input type="checkbox" class="gsm-menu" data-id="' + it.id + '"' + (visible ? ' checked' : '') + (it.locked ? ' disabled' : '') + '><span>' + esc(it.label) + '</span></label>';
      }).join('');
      var menu = '<div class="gs-card" style="margin-top:16px"><div class="gs-card__title">🧭 הצגת תפריט</div>' +
        '<p style="color:var(--gs-muted);font-size:13px">בטל סימון כדי להסתיר פריט מהתפריט (לכל המשתמשים). פריטים נעולים לא ניתנים להסתרה.</p>' +
        menuRows + '<div style="margin-top:8px"><button class="gs-btn" id="menu-save">שמור תפריט</button> <span id="menu-status" role="status" aria-live="polite" style="margin-inline-start:8px;color:var(--gs-muted)"></span></div></div>';

      var savedOrder = (window.GSM_CONFIG && window.GSM_CONFIG.navOrder) || [];
      var ordered = GSM_NAV_ITEMS.slice().sort(function (a, b) {
        var ia = savedOrder.indexOf(a.id); var ib = savedOrder.indexOf(b.id);
        return (ia < 0 ? 999 : ia) - (ib < 0 ? 999 : ib);
      });
      var orderRows = ordered.map(function (it) {
        return '<div class="gs-ord-row" data-id="' + it.id + '" style="display:flex;align-items:center;gap:8px;padding:6px 10px;border:1px solid var(--gs-border);border-radius:8px;margin-bottom:6px;background:var(--gs-surface-2)">' +
          '<span style="flex:1">' + esc(it.label) + '</span>' +
          '<button class="gs-btn gs-btn--sm gs-btn--ghost gsm-ord-up" type="button">↑</button>' +
          '<button class="gs-btn gs-btn--sm gs-btn--ghost gsm-ord-down" type="button">↓</button></div>';
      }).join('');
      var orderCard = '<div class="gs-card" style="margin-top:16px"><div class="gs-card__title">↕ סידור תפריט</div>' +
        '<p style="color:var(--gs-muted);font-size:13px">שנה את סדר הפריטים בתפריט (בתוך הקבוצות) בעזרת החיצים, ושמור.</p>' +
        '<div id="gsm-ord-list">' + orderRows + '</div>' +
        '<div style="margin-top:8px"><button class="gs-btn" id="ord-save">שמור סדר</button> <span id="ord-status" role="status" aria-live="polite" style="margin-inline-start:8px;color:var(--gs-muted)"></span></div></div>';

      container.innerHTML = matrix + menu + orderCard;

      container.querySelectorAll('.gsm-ord-up').forEach(function (b) {
        b.addEventListener('click', function () { var row = b.closest('.gs-ord-row'); var prev = row.previousElementSibling; if (prev) { row.parentNode.insertBefore(row, prev); } });
      });
      container.querySelectorAll('.gsm-ord-down').forEach(function (b) {
        b.addEventListener('click', function () { var row = b.closest('.gs-ord-row'); var next = row.nextElementSibling; if (next) { row.parentNode.insertBefore(next, row); } });
      });
      document.getElementById('ord-save').addEventListener('click', function () {
        var btn = this, st = document.getElementById('ord-status'); btn.disabled = true; st.textContent = 'שומר…';
        var order = [];
        document.querySelectorAll('#gsm-ord-list .gs-ord-row').forEach(function (r) { order.push(r.getAttribute('data-id')); });
        api('/settings', { method: 'POST', body: { nav_order: order } }).then(function () {
          if (window.GSM_CONFIG) { window.GSM_CONFIG.navOrder = order; }
          st.textContent = '✓ נשמר'; btn.disabled = false; refreshNav();
        }).catch(function (e) { st.textContent = '✗ ' + (e.message || e); btn.disabled = false; });
      });

      document.getElementById('pm-save').addEventListener('click', function () {
        var st = document.getElementById('pm-status'); this.disabled = true; st.textContent = 'שומר…';
        var out = {}; GSM_ROLES.forEach(function (r) { out[r[0]] = []; });
        document.querySelectorAll('.gsm-pm:checked').forEach(function (cb) { out[cb.getAttribute('data-role')].push(cb.getAttribute('data-id')); });
        api('/settings/role-perms', { method: 'POST', body: { perms: out } })
          .then(function () { st.textContent = '✓ נשמר'; refreshNav(); })
          .catch(function (e) { st.textContent = '✗ ' + e.message; });
      });
      document.getElementById('pm-reset').addEventListener('click', function () {
        var st = document.getElementById('pm-status'); st.textContent = 'מאפס…';
        api('/settings/role-perms', { method: 'POST', body: { perms: {} } })
          .then(function () { st.textContent = '✓ אופס'; refreshNav(); })
          .catch(function (e) { st.textContent = '✗ ' + e.message; });
      });
      document.getElementById('menu-save').addEventListener('click', function () {
        var st = document.getElementById('menu-status'); this.disabled = true; st.textContent = 'שומר…';
        var h = [];
        document.querySelectorAll('.gsm-menu').forEach(function (cb) { if (!cb.disabled && !cb.checked) h.push(cb.getAttribute('data-id')); });
        api('/settings/menu', { method: 'POST', body: { hidden: h } })
          .then(function () { st.textContent = '✓ נשמר'; refreshNav(); })
          .catch(function (e) { st.textContent = '✗ ' + e.message; });
      });
    });
  }

  GSM.registerView('admin', function (mount) {
    if (!GSM.can('manage')) { GSM.navigate('/dashboard'); return; }
    mount.innerHTML = chrome('admin', loading());
    var main = mount.querySelector('.gs-main');

    api('/settings').then(function (cfg) {
      cfg = cfg || {};
      var brb = cfg.branding || {};
      var brandCard =
        '<div class="gs-card" style="margin-top:16px"><div class="gs-card__title">🎨 מיתוג</div>' +
        '<div class="gs-field"><label class="gs-label">כותרת המערכת (האדר)</label><input class="gs-input" id="br-title" value="' + esc(brb.title || '') + '" placeholder="מערכת גזים"></div>' +
        '<div class="gs-field"><label class="gs-label">תת-כותרת (מסך הבית)</label><input class="gs-input" id="br-sub" value="' + esc(brb.subtitle || '') + '" placeholder="יחידת הבטיחות · הטכניון"></div>' +
        '<div class="gs-field"><label class="gs-label">כתובת לוגו (URL, אופציונלי — להאדר)</label><input class="gs-input" id="br-logo" value="' + esc(brb.logo_url || '') + '" placeholder="https://..."></div>' +
        '<button class="gs-btn" id="br-save">שמור מיתוג</button><span id="br-status" role="status" aria-live="polite" style="margin-inline-start:10px;color:var(--gs-muted)"></span>' +
        '<p style="color:var(--gs-muted);font-size:12px;margin-top:8px">ייכנס לתוקף מלא לאחר רענון הדף.</p></div>';
      main.innerHTML =
        '<div class="gs-toolbar"><h2 class="gs-h2">הגדרות אתר</h2></div>' +
        '<div class="gs-card"><div class="gs-card__title">⚙ ספי איחור</div>' +
        '<div class="gs-field"><label class="gs-label">ביקורת רבעונית נחשבת באיחור אחרי (ימים)</label>' +
        '<input class="gs-input" id="gsm-set-q" type="number" value="' + esc(cfg.quarterly_days || 90) + '" style="max-width:160px"></div>' +
        '<div class="gs-field"><label class="gs-label">ביקורת חודשית נחשבת באיחור אחרי (ימים)</label>' +
        '<input class="gs-input" id="gsm-set-m" type="number" value="' + esc(cfg.monthly_days || 30) + '" style="max-width:160px"></div>' +
        '<button class="gs-btn" id="gsm-set-save">שמור הגדרות</button>' +
        '<span id="gsm-set-status" role="status" aria-live="polite" style="margin-inline-start:10px;color:var(--gs-muted)"></span>' +
        '</div>' +
        brandCard +
        '<div id="gsm-gov"></div>' +
        '<div id="gsm-retention"></div>' +
        '<div id="gsm-chat-settings"></div>' +
        '<div id="gsm-sso-settings"></div>' +
        '<div id="gsm-captcha-settings"></div>' +
        '<div class="gs-card" style="margin-top:16px"><div class="gs-card__title">📥 הגירה וגיבוי</div>' +
        '<p style="color:var(--gs-muted)">ייבוא/גיבוי נתונים ומפתח הגירה נמצאים בעמוד "Gas Migration" בפאנל הניהול של WordPress.</p></div>';

      renderGovernance(document.getElementById('gsm-gov'));
      renderRetention(document.getElementById('gsm-retention'));
      renderChatSettings(document.getElementById('gsm-chat-settings'));
      renderSsoSettings(document.getElementById('gsm-sso-settings'));
      renderCaptchaSettings(document.getElementById('gsm-captcha-settings'));

      var save = document.getElementById('gsm-set-save');
      save.addEventListener('click', function () {
        var status = document.getElementById('gsm-set-status');
        status.textContent = 'שומר…';
        save.disabled = true;
        api('/settings', {
          method: 'POST',
          body: {
            quarterly_days: parseInt(document.getElementById('gsm-set-q').value, 10) || 90,
            monthly_days: parseInt(document.getElementById('gsm-set-m').value, 10) || 30
          }
        }).then(function () {
          status.textContent = '✓ נשמר';
          save.disabled = false;
        }).catch(function (e) {
          status.textContent = '✗ ' + e.message;
          save.disabled = false;
        });
      });

      var brSave = document.getElementById('br-save');
      if (brSave) {
        brSave.addEventListener('click', function () {
          var btn = this, st = document.getElementById('br-status'); btn.disabled = true; st.textContent = 'שומר…';
          var payload = { branding: {
            title: document.getElementById('br-title').value,
            subtitle: document.getElementById('br-sub').value,
            logo_url: document.getElementById('br-logo').value.trim()
          } };
          api('/settings', { method: 'POST', body: payload }).then(function (r) {
            if (window.GSM_CONFIG) { window.GSM_CONFIG.branding = r.branding || payload.branding; }
            st.textContent = '✓ נשמר (רענן לתצוגה מלאה)'; btn.disabled = false;
          }).catch(function (e) { st.textContent = '✗ ' + (e.message || e); btn.disabled = false; });
        });
      }
    }).catch(function (e) { main.innerHTML = '<div class="gs-error" role="alert">' + esc(e.message) + '</div>'; });
  });

  // ---- USERS (staff management) -------------------------------------------

  GSM.registerView('users', function (mount) {
    if (!GSM.can('manage')) { GSM.navigate('/dashboard'); return; }
    mount.innerHTML = chrome('users', loading());
    var main = mount.querySelector('.gs-main');

    api('/users').then(function (users) {
      users = users || [];
      var rows = users.map(function (u) {
        var badge = u.is_admin ? ' <span class="gs-pill gs-pill--ok">מנהל אתר</span>' : '';
        var active = u.active ? '<span class="gs-pill gs-pill--ok">פעיל</span>' : '<span class="gs-pill gs-pill--danger">מושבת</span>';
        var actions = u.is_admin ? '—' :
          '<button class="gs-btn gs-btn--sm gs-btn--ghost" data-uedit="' + u.id + '">ערוך</button> ' +
          '<button class="gs-btn gs-btn--sm gs-btn--ghost" data-ureset="' + u.id + '">איפוס סיסמה</button>' +
          (u.twofa ? ' <button class="gs-btn gs-btn--sm gs-btn--ghost" data-u2fa="' + u.id + '" data-name="' + esc(u.name) + '">איפוס 2FA ו-Passkeys</button>' : '');
        if (u.twofa) badge += ' <span class="gs-pill gs-pill--ok">2FA</span>';
        return '<tr><td>' + esc(u.name) + badge + '</td><td>' + esc(u.email) + '</td><td>' + esc(u.role_label) + '</td><td>' + active + '</td><td>' + esc(u.last_login || '—') + '</td><td>' + actions + '</td></tr>';
      }).join('');

      main.innerHTML =
        '<div class="gs-toolbar"><h2 class="gs-h2">ניהול משתמשים</h2><div class="gs-toolbar__actions"><button class="gs-btn gs-btn--ghost" id="gsm-user-import" aria-expanded="false" aria-controls="gsm-user-import-panel">ייבוא מקובץ</button> <button class="gs-btn" id="gsm-user-add">+ משתמש חדש</button></div></div>' +
        '<div id="gsm-user-import-panel" hidden></div>' +
        '<table class="gs-table"><thead><tr><th>שם</th><th>אימייל</th><th>תפקיד</th><th>סטטוס</th><th>כניסה אחרונה</th><th></th></tr></thead><tbody>' +
        (rows || '<tr><td colspan="6"><div class="gs-empty">אין משתמשים</div></td></tr>') + '</tbody></table>' +
        '<p style="color:var(--gs-muted);font-size:13px;margin-top:10px">אחמ"ת → ביקורת רבעונית · מנהל מעבדה / חוקר ראשי → ביקורת חודשית · מנהל → הכול.</p>';

      // Import users from a file (old-system export or a spreadsheet).
      document.getElementById('gsm-user-import').addEventListener('click', function () {
        var tg = this, panel = document.getElementById('gsm-user-import-panel');
        if (!panel.hidden) { panel.hidden = true; tg.setAttribute('aria-expanded', 'false'); return; }
        var STATE = { 'new': ['ייווצר', 'ok'], existing: ['קיים', 'warn'], invalid: ['לא תקין', 'danger'], duplicate: ['כפול', 'warn'] };
        var ROLE = { manager: 'מנהל', lab_manager: 'מנהל מעבדה', pi: 'חוקר ראשי', ahmat: 'אחמ"ת' };
        panel.innerHTML = '<div class="gs-card" style="margin-bottom:16px"><h3 class="gs-card__title" id="ui-title" tabindex="-1">ייבוא משתמשים מקובץ</h3>' +
          '<p style="color:var(--gs-muted);font-size:13px;margin-top:0">קובץ המשתמשים מהאתר הישן (לשונית "מיגרציה וגיבוי", ייצוא משתמשים, JSON), או גיליון CSV / Excel עם העמודות: שם, אימייל, תפקיד, סטטוס. ' +
          'קודי ה-PIN מהמערכת הישנה לא מיובאים. לכל משתמש חדש נקבעת סיסמה אקראית, ואפשר לשלוח לו קישור לקביעת סיסמה. משתמשים שכבר קיימים לא משתנים.</p>' +
          '<div class="gs-field"><label class="gs-label" for="ui-file">קובץ (JSON, CSV או XLSX, עד 2MB)</label><input class="gs-input" type="file" id="ui-file" accept=".json,.csv,.xlsx"></div>' +
          '<button type="button" class="gs-btn gs-btn--sm" id="ui-check">בדוק את הקובץ</button> ' +
          '<span id="ui-st" role="status" aria-live="polite" style="margin-inline-start:8px;color:var(--gs-muted)"></span>' +
          '<div id="ui-result" style="margin-top:12px"></div></div>';
        panel.hidden = false; tg.setAttribute('aria-expanded', 'true');
        document.getElementById('ui-title').focus();
        function send(apply, sendEmails) {
          var f = document.getElementById('ui-file').files[0];
          var fd = new FormData(); fd.append('file', f);
          if (apply) { fd.append('apply', '1'); if (sendEmails) fd.append('send_emails', '1'); }
          return fetch(GSM.restUrl('/users/import'), { method: 'POST', headers: { 'X-WP-Nonce': GSM.restNonce() }, body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json().then(function (j) { if (!r.ok) throw new Error((j && j.message) || ('HTTP ' + r.status)); return j; }); });
        }
        document.getElementById('ui-check').addEventListener('click', function () {
          var st = document.getElementById('ui-st'), btn = this, res = document.getElementById('ui-result');
          if (!document.getElementById('ui-file').files[0]) { st.textContent = 'בחרו קובץ.'; document.getElementById('ui-file').focus(); return; }
          btn.disabled = true; st.textContent = 'בודק…'; res.innerHTML = '';
          GSM.refreshNonce().then(function () { return send(false); }).then(function (d) {
            btn.disabled = false; st.textContent = '✓ הבדיקה הסתיימה. שום דבר לא נוצר.';
            var c = d.counts || {};
            var body = (d.rows || []).map(function (r) {
              var s2 = STATE[r.state] || [r.state, 'warn'];
              return '<tr><td>' + esc(r.name || '—') + '</td><td><bdi dir="ltr">' + esc(r.email || '—') + '</bdi></td><td>' + esc(ROLE[r.role] || r.role_text || '—') + '</td><td>' + (r.active ? 'פעיל' : 'מושבת') + '</td>' +
                '<td><span class="gs-pill gs-pill--' + s2[1] + '">' + esc(s2[0]) + '</span>' + (r.why ? ' <span style="font-size:12.5px">' + esc(r.why) + '</span>' : '') + '</td></tr>';
            }).join('');
            res.innerHTML = '<p style="font-size:14px"><b>' + (c['new'] || 0) + '</b> משתמשים חדשים ייווצרו · ' + (c.existing || 0) + ' כבר קיימים · ' + (c.invalid || 0) + ' לא תקינים · ' + (c.duplicate || 0) + ' כפולים</p>' +
              (body ? '<div style="overflow-x:auto"><table class="gs-table"><thead><tr><th>שם</th><th>אימייל</th><th>תפקיד</th><th>סטטוס</th><th>מה יקרה</th></tr></thead><tbody>' + body + '</tbody></table></div>' : '') +
              ((c['new'] || 0) > 0 ? '<label style="display:flex;gap:8px;align-items:center;margin:12px 0"><input type="checkbox" id="ui-emails" checked><span>שלח לכל משתמש חדש ופעיל אימייל עם קישור לקביעת סיסמה</span></label>' +
                '<button type="button" class="gs-btn" id="ui-apply">צור ' + (c['new'] || 0) + ' משתמשים</button>' : '');
            var ap = document.getElementById('ui-apply');
            if (ap) ap.addEventListener('click', function () {
              if (!confirm('ליצור ' + (c['new'] || 0) + ' משתמשים חדשים?')) return;
              ap.disabled = true; st.textContent = 'יוצר משתמשים…';
              send(true, document.getElementById('ui-emails').checked).then(function (o) {
                var r2 = o.result || {};
                alert('נוצרו ' + (r2.created || 0) + ' משתמשים' + (r2.emailed ? ', ונשלחו ' + r2.emailed + ' קישורים לקביעת סיסמה' : '') + ((r2.failed || []).length ? '. נכשלו: ' + r2.failed.length : '') + '.');
                GSM.render();
              }).catch(function (e) { st.textContent = '✗ ' + (e.message || e); ap.disabled = false; });
            });
          }).catch(function (e) { btn.disabled = false; st.textContent = ''; res.innerHTML = '<div class="gs-error" role="alert">' + esc(e.message || e) + '</div>'; });
        });
      });

      var add = document.getElementById('gsm-user-add');
      if (add) add.addEventListener('click', function () { GSM_Forms.openUser(null, function () { GSM.navigate('/users'); }); });
      document.querySelectorAll('[data-uedit]').forEach(function (b) {
        b.addEventListener('click', function () {
          var id = b.getAttribute('data-uedit');
          var u = users.filter(function (x) { return String(x.id) === String(id); })[0];
          GSM_Forms.openUser(u, function () { GSM.navigate('/users'); });
        });
      });
      document.querySelectorAll('[data-u2fa]').forEach(function (b) {
        b.addEventListener('click', function () {
          if (!confirm('לאפס את האימות הדו-שלבי של ' + (b.getAttribute('data-name') || '') + '? המשתמש ינותק מכל המכשירים וייכנס בסיסמה בלבד עד שיפעיל מחדש.')) return;
          api('/users/' + b.getAttribute('data-u2fa') + '/2fa-reset', { method: 'POST' })
            .then(function () { GSM.navigate('/users'); GSM.render(); })
            .catch(function (e) { alert('שגיאה: ' + (e.message || e)); });
        });
      });
      document.querySelectorAll('[data-ureset]').forEach(function (b) {
        b.addEventListener('click', function () {
          if (!confirm('לשלוח למשתמש מייל לאיפוס סיסמה?')) return;
          api('/users/' + b.getAttribute('data-ureset') + '/reset', { method: 'POST' })
            .then(function () { alert('נשלח מייל איפוס סיסמה.'); })
            .catch(function (e) { alert('שגיאה: ' + (e.message || e)); });
        });
      });
    }).catch(function (e) { main.innerHTML = '<div class="gs-error" role="alert">' + esc(e.message) + '</div>'; });
  });

  // ---- QR ISSUANCE MANAGER -------------------------------------------------

  var QRI_STATUSES = [
    ['issued', 'הונפק', '#1a9b54'],
    ['pending_reissue', 'ממתין להנפקה מחדש', '#c0392b'],
    ['archived', 'ארכיון', '#8e44ad']
  ];
  function qriLabel(s) { for (var i = 0; i < QRI_STATUSES.length; i++) { if (QRI_STATUSES[i][0] === s) return QRI_STATUSES[i][1]; } return s; }
  function qriColor(s) { for (var i = 0; i < QRI_STATUSES.length; i++) { if (QRI_STATUSES[i][0] === s) return QRI_STATUSES[i][2]; } return '#8a9bb3'; }

  GSM.registerView('qrissue', function (mount) {
    var u = GSM.session.user || {};
    if (!(GSM.can('manage') || u.roleKey === 'ahmat')) { GSM.navigate('/dashboard'); return; }
    mount.innerHTML = chrome('qrissue', loading());
    var main = mount.querySelector('.gs-main');
    var state = { items: [], group: 'all', search: '' };

    function reload() {
      api('/qr-issuance').then(function (d) {
        state.items = (d && d.items) || [];
        render();
      }).catch(function (e) { main.innerHTML = '<div class="gs-error" role="alert">' + esc(e.message) + '</div>'; });
    }

    function card(it) {
      var actions = QRI_STATUSES.filter(function (s) { return s[0] !== it.status; }).map(function (s) {
        return '<button class="gs-btn gs-btn--sm gs-btn--ghost" data-move="' + it.id + '" data-to="' + s[0] + '">→ ' + esc(s[1]) + '</button>';
      }).join(' ');
      if (it.status === 'archived') {
        actions += ' <button class="gs-btn gs-btn--sm gs-btn--danger" data-del="' + it.id + '" data-name="' + esc(it.equipment_name || '') + '">🗑 מחק</button>';
      }
      var meta = [];
      if (it.equipment_name) meta.push('<span><b>ציוד:</b> ' + esc(it.equipment_name) + '</span>');
      if (it.pi_name) meta.push('<span><b>חוקר:</b> ' + esc(it.pi_name) + '</span>');
      if (it.qr_number) meta.push('<span><b>QR #:</b> ' + esc(it.qr_number) + '</span>');
      if (it.issued_date) meta.push('<span><b>הונפק:</b> ' + esc(it.issued_date) + '</span>');
      // Scan link (the URL printed in the sticker's QR code) + copy.
      var link = it.qr_token
        ? '<div class="gs-qri-link"><a href="' + esc(bridgeUrl(it.qr_token)) + '" target="_blank" rel="noopener" dir="ltr">' + esc(bridgeUrl(it.qr_token)) + '</a>' +
          ' <button type="button" class="gs-btn gs-btn--sm gs-btn--ghost" data-copy="' + esc(bridgeUrl(it.qr_token)) + '"><span aria-hidden="true">📋</span> העתק<span class="gs-visually-hidden"> את קישור הסריקה של ' + esc(it.equipment_name || ('רשומה #' + it.id)) + '</span></button></div>'
        : '<div class="gs-qri-link" style="color:var(--gs-muted)">' + (parseInt(it.equipment_id, 10) > 0 ? 'לציוד אין עדיין קוד QR' : 'לא מקושר לפריט ציוד, ולכן אין קישור סריקה') + '</div>';
      return '<div class="gs-qri-card"><div class="gs-qri-dot" style="background:' + qriColor(it.status) + '"></div>' +
        '<div class="gs-qri-body"><div class="gs-qri-name">' + esc(it.equipment_name || ('רשומה #' + it.id)) + '</div>' +
        '<div class="gs-qri-meta">' + meta.join('') + '</div>' + link +
        '<div class="gs-qri-actions">' + actions + '</div></div></div>';
    }

    function render() {
      var q = state.search.toLowerCase().trim();
      var items = state.items.filter(function (x) {
        if (state.group !== 'all' && x.status !== state.group) return false;
        if (!q) return true;
        return (String(x.equipment_name || '').toLowerCase().indexOf(q) > -1) ||
          (String(x.pi_name || '').toLowerCase().indexOf(q) > -1) ||
          (String(x.qr_number || '').indexOf(q) > -1);
      });

      var counts = {};
      state.items.forEach(function (x) { counts[x.status] = (counts[x.status] || 0) + 1; });
      var tabs = '<button class="gs-qri-tab' + (state.group === 'all' ? ' on' : '') + '" data-g="all">הכל (' + state.items.length + ')</button>' +
        QRI_STATUSES.map(function (s) {
          return '<button class="gs-qri-tab' + (state.group === s[0] ? ' on' : '') + '" data-g="' + s[0] + '">' +
            '<span class="gs-qri-tdot" style="background:' + s[2] + '"></span>' + esc(s[1]) + ' (' + (counts[s[0]] || 0) + ')</button>';
        }).join('');

      var body;
      if (!items.length) {
        body = '<div class="gs-empty">אין רשומות בקבוצה זו</div>';
      } else if (state.group === 'all') {
        body = QRI_STATUSES.map(function (s) {
          var gi = items.filter(function (x) { return x.status === s[0]; });
          if (!gi.length) return '';
          return '<h3 class="gs-qri-h3"><span class="gs-qri-tdot" style="background:' + s[2] + '"></span>' + esc(s[1]) + ' (' + gi.length + ')</h3>' +
            '<div class="gs-qri-list">' + gi.map(card).join('') + '</div>';
        }).join('');
      } else {
        body = '<div class="gs-qri-list">' + items.map(card).join('') + '</div>';
      }

      main.innerHTML =
        '<div class="gs-toolbar"><h2 class="gs-h2">ניהול הנפקות QR</h2>' +
        '<div class="gs-toolbar__actions">' +
        '<button class="gs-btn" id="qri-add">+ רשום הנפקה</button> ' +
        '<button class="gs-btn gs-btn--ghost" id="qri-sync">סנכרן מהציוד</button></div></div>' +
        '<div id="qri-addbox"></div>' +
        '<div class="gs-field"><input class="gs-input" id="qri-search" placeholder="חיפוש לפי שם/חוקר/מספר QR…" value="' + esc(state.search) + '"></div>' +
        '<div class="gs-qri-tabs">' + tabs + '</div>' +
        '<div id="qri-body">' + body + '</div>';

      var se = document.getElementById('qri-search');
      se.addEventListener('input', function () { state.search = se.value; var b = document.getElementById('qri-body'); if (b) { /* re-render body only */ render(); var s2 = document.getElementById('qri-search'); if (s2) { s2.focus(); s2.setSelectionRange(s2.value.length, s2.value.length); } } });
      document.querySelectorAll('.gs-qri-tab').forEach(function (b) {
        b.addEventListener('click', function () { state.group = b.getAttribute('data-g'); render(); });
      });
      document.querySelectorAll('[data-move]').forEach(function (b) {
        b.addEventListener('click', function () {
          var id = b.getAttribute('data-move'), to = b.getAttribute('data-to');
          b.disabled = true;
          api('/qr-issuance/' + id + '/status', { method: 'POST', body: { status: to } })
            .then(function () { var it = state.items.filter(function (x) { return String(x.id) === String(id); })[0]; if (it) it.status = to; render(); })
            .catch(function (e) { alert('שגיאה: ' + (e.message || e)); b.disabled = false; });
        });
      });
      document.querySelectorAll('#qri-body [data-copy]').forEach(function (b) {
        b.addEventListener('click', function () {
          var url = b.getAttribute('data-copy');
          var done = function () { var o = b.innerHTML; b.textContent = '✓ הועתק'; setTimeout(function () { b.innerHTML = o; }, 1500); };
          if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(url).then(done, function () { window.prompt('העתיקו את הקישור:', url); });
          } else {
            window.prompt('העתיקו את הקישור:', url);
          }
        });
      });
      document.querySelectorAll('[data-del]').forEach(function (b) {
        b.addEventListener('click', function () {
          var id = b.getAttribute('data-del');
          if (!confirm('למחוק את "' + (b.getAttribute('data-name') || '') + '" מהארכיון?')) return;
          api('/qr-issuance/' + id, { method: 'DELETE' })
            .then(function () { state.items = state.items.filter(function (x) { return String(x.id) !== String(id); }); render(); })
            .catch(function (e) { alert('שגיאה: ' + (e.message || e)); });
        });
      });
      document.getElementById('qri-add').addEventListener('click', openAdd);
      document.getElementById('qri-sync').addEventListener('click', syncFromEquipment);
    }

    function openAdd() {
      var box = document.getElementById('qri-addbox');
      if (box.innerHTML) { box.innerHTML = ''; return; }
      box.innerHTML = '<div class="gs-card"><div class="gs-card__title">רישום הנפקה חדשה</div>' +
        '<div class="gs-field"><label class="gs-label">ציוד</label><select class="gs-input" id="qri-eq"><option value="">טוען…</option></select></div>' +
        '<div class="gs-field"><label class="gs-label">מספר QR</label><input class="gs-input" id="qri-num"></div>' +
        '<div class="gs-field"><label class="gs-label">תאריך הנפקה</label><input class="gs-input" id="qri-date" type="date"></div>' +
        '<div class="gs-field"><label class="gs-label">סטטוס</label><select class="gs-input" id="qri-st">' +
        QRI_STATUSES.map(function (s) { return '<option value="' + s[0] + '">' + esc(s[1]) + '</option>'; }).join('') + '</select></div>' +
        '<button class="gs-btn" id="qri-save">שמור</button> <span id="qri-sv-status" role="status" aria-live="polite" style="margin-inline-start:8px;color:var(--gs-muted)"></span></div>';
      api('/equipment?per_page=1000').then(function (d) {
        var list = (d && d.items) || (Array.isArray(d) ? d : []);
        var sel = document.getElementById('qri-eq');
        if (!sel) return;
        sel.innerHTML = '<option value="">— בחר ציוד —</option>' + list.map(function (e) {
          var code = e.asset_code ? (e.asset_code + ' · ') : '';
          return '<option value="' + e.id + '" data-num="' + esc(e.asset_code || '') + '">' + esc(code + (e.name || '')) + '</option>';
        }).join('');
        sel.addEventListener('change', function () {
          var opt = sel.options[sel.selectedIndex];
          var numEl = document.getElementById('qri-num');
          if (opt && numEl && !numEl.value) numEl.value = opt.getAttribute('data-num') || '';
        });
      }).catch(function () {});
      document.getElementById('qri-save').addEventListener('click', function () {
        var st = document.getElementById('qri-sv-status'); this.disabled = true; st.textContent = 'שומר…';
        var body = {
          equipment_id: parseInt(document.getElementById('qri-eq').value, 10) || 0,
          qr_number: document.getElementById('qri-num').value,
          issued_date: document.getElementById('qri-date').value,
          status: document.getElementById('qri-st').value
        };
        var btn = this;
        api('/qr-issuance', { method: 'POST', body: body })
          .then(function () { box.innerHTML = ''; reload(); })
          .catch(function (e) { st.textContent = '✗ ' + (e.message || e); btn.disabled = false; });
      });
    }

    function syncFromEquipment() {
      if (!confirm('ליצור רשומת הנפקה לכל פריט ציוד שאין לו עדיין רשומה? (קיימים יידלגו)')) return;
      api('/equipment?per_page=5000').then(function (d) {
        var list = (d && d.items) || (Array.isArray(d) ? d : []);
        var ids = list.map(function (e) { return e.id; });
        return api('/qr-issuance/bulk', { method: 'POST', body: { equipment_ids: ids } });
      }).then(function (r) {
        alert('נוצרו ' + (r.created || 0) + ' רשומות, דילוג על ' + (r.skipped || 0) + '.');
        reload();
      }).catch(function (e) { alert('שגיאה: ' + (e.message || e)); });
    }

    reload();
  });

  // ---- INTERNAL DATABASE BROWSER ------------------------------------------

  GSM.registerView('database', function (mount) {
    if (!GSM.can('manage')) { GSM.navigate('/dashboard'); return; }
    mount.innerHTML = chrome('database', loading());
    var main = mount.querySelector('.gs-main');
    var state = { tables: [], key: '', search: '', offset: 0, limit: 50 };

    api('/db/tables').then(function (d) {
      state.tables = (d && d.tables) || [];
      if (state.tables.length) state.key = state.tables[0].key;
      shell();
      loadTable();
    }).catch(function (e) { main.innerHTML = '<div class="gs-error" role="alert">' + esc(e.message) + '</div>'; });

    function shell() {
      var opts = state.tables.map(function (t) {
        return '<option value="' + t.key + '"' + (t.key === state.key ? ' selected' : '') + '>' + esc(t.label) + ' (' + t.count + ')</option>';
      }).join('');
      main.innerHTML =
        '<div class="gs-toolbar"><h2 class="gs-h2">מסד נתונים פנימי</h2></div>' +
        '<p style="color:var(--gs-muted);font-size:13px;margin:0 0 12px">צפייה, חיפוש וייצוא של טבלאות המערכת. עריכה מתבצעת דרך הטפסים המאומתים (ולידציה + יומן בקרה); טבלאות יומן הן לקריאה בלבד.</p>' +
        '<div class="gs-toolbar" style="gap:8px">' +
        '<select class="gs-input" id="db-table" style="max-width:260px" aria-label="בחירת טבלה">' + opts + '</select>' +
        '<input class="gs-input" id="db-search" placeholder="חיפוש…" style="max-width:220px" value="' + esc(state.search) + '">' +
        '<button class="gs-btn gs-btn--ghost gs-btn--sm" id="db-export">⬇️ CSV</button>' +
        '</div><div id="db-grid">' + loading() + '</div>';
      document.getElementById('db-table').addEventListener('change', function () { state.key = this.value; state.offset = 0; state.search = ''; var s = document.getElementById('db-search'); if (s) s.value = ''; loadTable(); });
      var se = document.getElementById('db-search');
      var t = null;
      se.addEventListener('input', function () { clearTimeout(t); t = setTimeout(function () { state.search = se.value; state.offset = 0; loadTable(); }, 350); });
      document.getElementById('db-export').addEventListener('click', function () {
        var url = GSM.restUrl('/db/export/' + state.key + '?_wpnonce=' + encodeURIComponent(GSM.restNonce()));
        var a = document.createElement('a'); a.href = url; a.download = state.key + '.csv';
        document.body.appendChild(a); a.click(); setTimeout(function () { a.remove(); }, 800);
      });
    }

    function loadTable() {
      var grid = document.getElementById('db-grid');
      if (grid) grid.innerHTML = loading();
      var path = '/db/table/' + state.key + '?limit=' + state.limit + '&offset=' + state.offset + (state.search ? ('&search=' + encodeURIComponent(state.search)) : '');
      api(path).then(function (d) {
        var cols = d.columns || [];
        var rows = d.rows || [];
        var head = cols.map(function (c) { return '<th>' + esc(c) + '</th>'; }).join('');
        if (d.editableVia) head += '<th></th>';
        var body = rows.map(function (r) {
          var tds = cols.map(function (c) { return '<td title="' + esc(r[c] || '') + '">' + esc(r[c] || '') + '</td>'; }).join('');
          if (d.editableVia) {
            tds += '<td><button class="gs-btn gs-btn--sm gs-btn--ghost" data-edit="' + esc(r.id || '') + '">ערוך</button></td>';
          }
          return '<tr>' + tds + '</tr>';
        }).join('');
        var from = d.total ? (state.offset + 1) : 0;
        var to = Math.min(state.offset + state.limit, d.total);
        var pager = '<div class="gs-toolbar" style="margin-top:10px;justify-content:space-between">' +
          '<span style="color:var(--gs-muted);font-size:13px">' + from + '–' + to + ' מתוך ' + d.total + (d.searchable ? '' : ' · (חיפוש לא זמין בטבלה זו)') + '</span>' +
          '<span><button class="gs-btn gs-btn--sm gs-btn--ghost" id="db-prev"' + (state.offset <= 0 ? ' disabled' : '') + '>→ הקודם</button> ' +
          '<button class="gs-btn gs-btn--sm gs-btn--ghost" id="db-next"' + (to >= d.total ? ' disabled' : '') + '>הבא ←</button></span></div>';

        grid.innerHTML = '<div style="overflow:auto"><table class="gs-table gs-table--db"><thead><tr>' + head + '</tr></thead><tbody>' +
          (body || '<tr><td colspan="' + (cols.length + 1) + '"><div class="gs-empty">אין נתונים</div></td></tr>') + '</tbody></table></div>' + pager;

        var pv = document.getElementById('db-prev'), nx = document.getElementById('db-next');
        if (pv) pv.addEventListener('click', function () { state.offset = Math.max(0, state.offset - state.limit); loadTable(); });
        if (nx) nx.addEventListener('click', function () { state.offset = state.offset + state.limit; loadTable(); });
        document.querySelectorAll('[data-edit]').forEach(function (b) {
          b.addEventListener('click', function () { editRow(d.editableVia, b.getAttribute('data-edit')); });
        });
      }).catch(function (e) { if (grid) grid.innerHTML = '<div class="gs-error" role="alert">' + esc(e.message) + '</div>'; });
    }

    function editRow(via, id) {
      if (!id) return;
      if (via === 'qrissue') { GSM.navigate('/qrissue'); return; }
      var map = { equipment: ['/equipment/', 'openEquipment'], faults: ['/faults/', 'openFault'], inspections: ['/inspections/', 'openInspection'] };
      var m = map[via];
      if (!m || !window.GSM_Forms || typeof GSM_Forms[m[1]] !== 'function') { alert('עריכה דרך מסך ' + via); return; }
      api(m[0] + id).then(function (full) {
        var item = (full && full.item) ? full.item : full;
        GSM_Forms[m[1]](item, function () { loadTable(); });
      }).catch(function (e) { alert('שגיאה בטעינת הרשומה: ' + (e.message || e)); });
    }
  });

  // ---- EQUIPMENT IMPORT (Excel / CSV with column mapping) -----------------

  GSM.registerView('import', function (mount) {
    if (!GSM.can('manage')) { GSM.navigate('/dashboard'); return; }
    mount.innerHTML = chrome('import', '');
    var main = mount.querySelector('.gs-main');
    var data = null; // { headers, rows, fields }

    function screen1() {
      main.innerHTML =
        '<div class="gs-toolbar"><h2 class="gs-h2">ייבוא ציוד מקובץ</h2></div>' +
        '<div class="gs-card"><div class="gs-card__title">1. בחר קובץ</div>' +
        '<p style="color:var(--gs-muted);font-size:13px">העלה קובץ <b>Excel (.xlsx)</b> או <b>CSV</b> שורה ראשונה = כותרות עמודות. אח״כ תמפה כל עמודה לשדה במערכת.</p>' +
        '<label class="gs-label" for="imp-file">קובץ לייבוא (xlsx / csv)</label>' +
        '<input type="file" id="imp-file" accept=".xlsx,.csv,.txt" class="gs-input" style="padding:8px">' +
        '<div style="margin-top:12px"><button class="gs-btn" id="imp-analyze">נתח קובץ ←</button> <span id="imp-status" role="status" aria-live="polite" style="margin-inline-start:8px;color:var(--gs-muted)"></span></div>' +
        '<p style="color:var(--gs-muted);font-size:12px;margin-top:10px">הערה: לעברית מומלץ XLSX (קידוד אמין). ייבוא דרך טפסים מאומתים — כל שורה עוברת ולידציה ונרשמת ביומן הבקרה. גודל מרבי 5MB.</p>' +
        '</div>';
      document.getElementById('imp-analyze').addEventListener('click', doAnalyze);
    }

    function doAnalyze() {
      var inp = document.getElementById('imp-file');
      var st = document.getElementById('imp-status');
      if (!inp.files || !inp.files[0]) { st.textContent = 'בחר קובץ קודם'; return; }
      var btn = document.getElementById('imp-analyze'); btn.disabled = true; st.textContent = 'מנתח…';
      var fd = new FormData(); fd.append('file', inp.files[0]);
      fetch(GSM.restUrl('/import/analyze'), {
        method: 'POST', headers: { 'X-WP-Nonce': GSM.restNonce() }, body: fd, credentials: 'same-origin'
      }).then(function (r) {
        return r.json().then(function (j) { if (!r.ok) { throw new Error((j && j.message) || ('HTTP ' + r.status)); } return j; });
      }).then(function (j) {
        data = j; screen2();
      }).catch(function (e) { btn.disabled = false; st.textContent = '✗ ' + (e.message || e); });
    }

    function guessCol(fieldKey, label, headers) {
      var lk = String(label).replace(/\s/g, '');
      for (var i = 0; i < headers.length; i++) {
        var h = String(headers[i]).replace(/\s/g, '');
        if (!h) continue;
        if (h === lk || h.indexOf(lk) > -1 || (lk.length > 1 && lk.indexOf(h) > -1)) return i;
        if (h.toLowerCase() === String(fieldKey).toLowerCase()) return i;
      }
      return -1;
    }

    function screen2() {
      var headers = data.headers || [];
      var fields = data.fields || {};
      var rows = data.rows || [];

      var colOpts = function (sel) {
        var o = '<option value="">— לא לייבא —</option>';
        for (var i = 0; i < headers.length; i++) {
          o += '<option value="' + i + '"' + (sel === i ? ' selected' : '') + '>' + esc(headers[i] || ('עמודה ' + (i + 1))) + '</option>';
        }
        return o;
      };

      var mapRows = Object.keys(fields).map(function (fk) {
        var g = guessCol(fk, fields[fk], headers);
        return '<div class="gs-field" style="margin:0"><label class="gs-label">' + esc(fields[fk]) +
          (fk === 'name' ? ' <span style="color:#c0392b">*</span>' : '') + '</label>' +
          '<select class="gs-input imp-map" data-field="' + fk + '">' + colOpts(g) + '</select></div>';
      }).join('');

      var keyOpts = ['asset_code', 'monday_id', 'name'].map(function (k) {
        return '<option value="' + k + '"' + (k === 'asset_code' ? ' selected' : '') + '>' + esc(fields[k] || k) + '</option>';
      }).join('');

      // Preview (first 8 rows).
      var prevHead = headers.map(function (h) { return '<th>' + esc(h) + '</th>'; }).join('');
      var prevBody = rows.slice(0, 8).map(function (r) {
        return '<tr>' + headers.map(function (h, i) { return '<td title="' + esc(r[i] || '') + '">' + esc(r[i] || '') + '</td>'; }).join('') + '</tr>';
      }).join('');

      main.innerHTML =
        '<div class="gs-toolbar"><h2 class="gs-h2">ייבוא ציוד — מיפוי</h2>' +
        '<div class="gs-toolbar__actions"><button class="gs-btn gs-btn--ghost" id="imp-back">→ קובץ אחר</button></div></div>' +
        '<p style="color:var(--gs-muted);font-size:13px">נמצאו <b>' + rows.length + '</b> שורות ו‑<b>' + headers.length + '</b> עמודות' +
        (data.truncated ? ' (הוצג חלק — הקובץ גדול)' : '') + '. מפה כל שדה לעמודה המתאימה (אפשר להשאיר "לא לייבא"). ' +
        'בעדכון של ציוד קיים, <b>תא ריק לא מוחק</b> ערך קיים. רק תאים מלאים מעדכנים.</p>' +

        '<div class="gs-card"><div class="gs-card__title">2. מיפוי עמודות → שדות</div>' +
        '<div class="gs-grid gs-grid--2">' + mapRows + '</div></div>' +

        '<div class="gs-card" style="margin-top:14px"><div class="gs-card__title">3. זיהוי רשומות קיימות (עדכון מול יצירה)</div>' +
        '<div class="gs-field"><label class="gs-label">שדה מזהה (לפיו נמצא רשומה קיימת לעדכון)</label>' +
        '<select class="gs-input" id="imp-key" style="max-width:260px">' + keyOpts + '</select></div>' +
        '<label style="display:inline-flex;gap:8px;align-items:center"><input type="checkbox" id="imp-create" checked><span>צור רשומות חדשות עבור מה שלא נמצא</span></label>' +
        '</div>' +

        '<div class="gs-card" style="margin-top:14px"><div class="gs-card__title">תצוגה מקדימה (8 שורות ראשונות)</div>' +
        '<div style="overflow:auto"><table class="gs-table gs-table--db"><thead><tr>' + prevHead + '</tr></thead><tbody>' + (prevBody || '') + '</tbody></table></div></div>' +

        '<div style="margin-top:14px"><button class="gs-btn" id="imp-run">ייבא עכשיו</button> <span id="imp-run-status" role="status" aria-live="polite" style="margin-inline-start:8px;color:var(--gs-muted)"></span></div>' +
        '<div id="imp-result"></div>';

      document.getElementById('imp-back').addEventListener('click', screen1);
      document.getElementById('imp-run').addEventListener('click', runImport);
    }

    function runImport() {
      var mapping = {};
      document.querySelectorAll('.imp-map').forEach(function (s) {
        if (s.value !== '') mapping[s.getAttribute('data-field')] = parseInt(s.value, 10);
      });
      if (!Object.keys(mapping).length) { alert('מפה לפחות עמודה אחת.'); return; }
      var btn = document.getElementById('imp-run'); var st = document.getElementById('imp-run-status');
      btn.disabled = true; st.textContent = 'מייבא…';
      api('/import/equipment', {
        method: 'POST',
        body: {
          headers: data.headers,
          rows: data.rows,
          mapping: mapping,
          keyField: document.getElementById('imp-key').value,
          createMissing: document.getElementById('imp-create').checked
        }
      }).then(function (r) {
        btn.disabled = false; st.textContent = '✓ הסתיים';
        var errs = (r.errors || []);
        var errHtml = errs.length ? ('<div class="gs-card" style="margin-top:12px"><div class="gs-card__title">שגיאות (' + errs.length + ')</div>' +
          '<table class="gs-table"><thead><tr><th>שורה</th><th>סיבה</th></tr></thead><tbody>' +
          errs.map(function (e) { return '<tr><td>' + esc(e.row) + '</td><td>' + esc(e.message) + '</td></tr>'; }).join('') +
          '</tbody></table></div>') : '';
        document.getElementById('imp-result').innerHTML =
          '<div class="gs-card" style="margin-top:12px"><div class="gs-card__title">✓ תוצאות ייבוא</div>' +
          '<div class="gs-grid gs-grid--stats">' +
          '<div class="gs-stat gs-stat--ok"><div class="gs-stat__num">' + (r.created || 0) + '</div><div class="gs-stat__label">נוצרו</div></div>' +
          '<div class="gs-stat"><div class="gs-stat__num">' + (r.updated || 0) + '</div><div class="gs-stat__label">עודכנו</div></div>' +
          '<div class="gs-stat' + (r.skipped ? ' gs-stat--danger' : '') + '"><div class="gs-stat__num">' + (r.skipped || 0) + '</div><div class="gs-stat__label">דולגו</div></div>' +
          '</div></div>' + errHtml;
      }).catch(function (e) { btn.disabled = false; st.textContent = '✗ ' + (e.message || e); });
    }

    screen1();
  });

  // ---- BI / ANALYTICS ------------------------------------------------------

  GSM.registerView('bi', function (mount) {
    if (!GSM.can('manage')) { GSM.navigate('/dashboard'); return; }
    mount.innerHTML = chrome('bi', loading());
    var main = mount.querySelector('.gs-main');

    api('/bi/advanced').then(function (d) {
      var hasChart = !!window.Chart;

      function stat(num, label, mod) {
        return '<div class="gs-stat' + (mod ? ' gs-stat--' + mod : '') + '">' +
          '<div class="gs-stat__num">' + esc(num) + '</div>' +
          '<div class="gs-stat__label">' + esc(label) + '</div></div>';
      }

      // A breakdown card: optional chart on top, full data table below
      // (the table is the accessible, always-present source of truth).
      function card(title, pairs, canvasId) {
        pairs = pairs || [];
        var total = pairs.reduce(function (a, p) { return a + p[1]; }, 0);
        var rows = pairs.map(function (p) {
          var pct = total ? Math.round((p[1] / total) * 100) : 0;
          return '<tr><td>' + esc(p[0]) + '</td><td style="text-align:center">' + esc(p[1]) + '</td><td style="text-align:center">' + pct + '%</td></tr>';
        }).join('');
        var tbl = '<table class="gs-table"><thead><tr><th>' + esc(title) + '</th><th>כמות</th><th>%</th></tr></thead><tbody>' +
          (rows || '<tr><td colspan="3"><div class="gs-empty">אין נתונים</div></td></tr>') + '</tbody></table>';
        var chartHtml = (hasChart && pairs.length) ? '<div class="gs-chartbox"><canvas id="' + canvasId + '" height="240"></canvas></div>' : '';
        return '<div class="gs-card">' + chartHtml + tbl + '</div>';
      }

      main.innerHTML =
        '<div class="gs-toolbar"><h2 class="gs-h2">BI וניתוח</h2>' +
        '<div class="gs-toolbar__actions"><a class="gs-btn gs-btn--ghost gs-btn--sm" href="#/integrations">קישורי ייצוא ↗</a></div></div>' +
        '<div class="gs-grid gs-grid--stats">' +
        stat(d.total || 0, 'סה״כ פריטי ציוד') +
        stat(d.overdueQuarterly || 0, 'רבעוניות באיחור', (d.overdueQuarterly ? 'danger' : 'ok')) +
        '</div>' +
        '<div class="gs-grid gs-grid--2" style="margin-top:16px">' +
        card('לפי סטטוס', d.statusBreakdown, 'bi-status') +
        card('לפי סוג גז', d.gasBreakdown, 'bi-gas') +
        card('לפי בניין', d.buildingBreakdown, 'bi-bld') +
        card('10 חוקרים ראשיים מובילים (לפי כמות ציוד)', d.topPIs, 'bi-pi') +
        '</div>' +
        (hasChart ? '' : '<p style="color:var(--gs-muted);font-size:13px">* ספריית הגרפים לא נטענה (ככל הנראה חסימת רשת חיצונית) — הנתונים מוצגים כטבלאות.</p>');

      if (hasChart) {
        var palette = ['#002147', '#e0a526', '#3a6ea5', '#8a9bb3', '#c0392b', '#27ae60', '#8e44ad', '#16a085', '#d35400', '#7f8c8d'];
        var legendRtl = { rtl: true, position: 'bottom' };
        function mkDoughnut(id, pairs) {
          var el = document.getElementById(id); if (!el) return;
          new Chart(el, {
            type: 'doughnut',
            data: { labels: pairs.map(function (p) { return p[0]; }), datasets: [{ data: pairs.map(function (p) { return p[1]; }), backgroundColor: palette }] },
            options: { responsive: true, plugins: { legend: legendRtl } }
          });
        }
        function mkBar(id, pairs) {
          var el = document.getElementById(id); if (!el) return;
          new Chart(el, {
            type: 'bar',
            data: { labels: pairs.map(function (p) { return p[0]; }), datasets: [{ data: pairs.map(function (p) { return p[1]; }), backgroundColor: '#002147' }] },
            options: { responsive: true, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } }
          });
        }
        mkDoughnut('bi-status', d.statusBreakdown || []);
        mkBar('bi-gas', d.gasBreakdown || []);
        mkBar('bi-bld', d.buildingBreakdown || []);
        mkBar('bi-pi', d.topPIs || []);
      }
    }).catch(function (e) { main.innerHTML = '<div class="gs-error" role="alert">' + esc(e.message) + '</div>'; });
  });

  // ---- INTEGRATIONS (outgoing webhook + BI export links) ------------------

  GSM.registerView('integrations', function (mount) {
    if (!GSM.can('manage')) { GSM.navigate('/dashboard'); return; }
    mount.innerHTML = chrome('integrations', loading());
    var main = mount.querySelector('.gs-main');

    Promise.all([
      api('/integrations'),
      api('/bi/token')
    ]).then(function (res) {
      var cfg = res[0] || {};
      var token = (res[1] && res[1].token) || '';
      var labels = cfg.eventLabels || {};
      var selected = cfg.webhookEvents || [];

      // Export links. Recommended: URL without the token + the token in the
      // X-GSM-Token header (not written to server / proxy access logs).
      // Calendar subscriptions cannot send headers, so iCal keeps ?token=.
      function exportLinks(tok) {
        function u(set, fmt) { return GSM.restUrl('/bi/data/' + set + '?format=' + fmt); }
        function uTok(set, fmt) { return GSM.restUrl('/bi/data/' + set + '?token=' + encodeURIComponent(tok) + '&format=' + fmt); }
        function row(lbl, url) {
          return '<div class="gs-field"><label class="gs-label">' + esc(lbl) + '</label>' +
            '<input class="gs-input" readonly value="' + esc(url) + '" data-gsm-action="select" style="font-size:12px;direction:ltr"></div>';
        }
        function pq(set) {
          // Power Query (Excel: Data > Get Data > Blank Query > Advanced Editor; Power BI: same editor).
          return 'let\n  Source = Csv.Document(\n    Web.Contents("' + u(set, 'csv') + '",\n      [Headers = [#"X-GSM-Token" = "' + tok + '"]]),\n    [Encoding = 65001]),\n' +
            '  Data = Table.PromoteHeaders(Source, [PromoteAllScalars = true])\nin\n  Data';
        }
        return '<p style="font-size:13px;margin:0 0 10px"><b>מומלץ:</b> כתובת בלי טוקן, והטוקן נשלח בכותרת <code>X-GSM-Token</code>. ' +
            'כותרת לא נרשמת ביומני השרת וה-proxy, בניגוד לטוקן בכתובת.</p>' +
          row('ציוד — CSV (Excel / Power BI)', u('equipment', 'csv')) +
          row('ציוד — JSON', u('equipment', 'json')) +
          row('תקלות — CSV', u('faults', 'csv')) +
          row('ביקורות — CSV', u('inspections', 'csv')) +
          '<details style="margin:0 0 10px"><summary style="cursor:pointer;font-size:13px">SAP / ERP — CSV מופרד בנקודה-פסיק (UTF-8 BOM, CRLF), לטעינה ב-SAP GUI / LSMW / CPI</summary>' +
          row('ציוד — SAP', u('equipment', 'sap')) + row('תקלות — SAP', u('faults', 'sap')) + row('ביקורות — SAP', u('inspections', 'sap')) + '</details>' +
          '<div class="gs-field"><label class="gs-label" for="pq-sample">דוגמת Power Query (Excel / Power BI) — ציוד</label>' +
            '<textarea class="gs-textarea" id="pq-sample" readonly rows="7" data-gsm-action="select" style="direction:ltr;font-family:monospace;font-size:12px">' + esc(pq('equipment')) + '</textarea></div>' +
          row('יומן ביקורות רבעוניות — iCal (Outlook / Google Calendar)', GSM.restUrl('/bi/ical?token=' + encodeURIComponent(tok))) +
          '<p style="color:var(--gs-muted);font-size:12px;margin-top:-6px">ביומן הטוקן חייב להופיע בכתובת, כי מנויי יומן לא יכולים לשלוח כותרות. מומלץ לשתף את קישור היומן רק עם מי שצריך אותו.</p>' +
          '<details style="margin-top:10px"><summary style="cursor:pointer;font-size:13px">קישורים עם הטוקן בכתובת (תאימות לאחור, פחות מומלץ)</summary>' +
            row('ציוד — CSV', uTok('equipment', 'csv')) +
            row('ציוד — JSON', uTok('equipment', 'json')) +
            row('תקלות — CSV', uTok('faults', 'csv')) +
            row('ביקורות — CSV', uTok('inspections', 'csv')) +
          '</details>';
      }

      var evRows = Object.keys(labels).map(function (k) {
        var on = selected.indexOf(k) > -1;
        return '<label style="display:flex;gap:8px;align-items:center;margin:6px 0"><input type="checkbox" class="gsm-ev" data-ev="' + esc(k) + '"' + (on ? ' checked' : '') + '><span>' + esc(labels[k]) + '</span></label>';
      }).join('');

      main.innerHTML =
        '<div class="gs-toolbar"><h2 class="gs-h2">אינטגרציות</h2></div>' +

        '<div class="gs-card"><div class="gs-card__title">🔗 Webhook יוצא</div>' +
        '<p style="color:var(--gs-muted);font-size:13px">שליחת הודעה אוטומטית (HTTP POST) למערכת חיצונית (Make / Zapier / n8n) כשמתרחש אירוע. ' +
        'כתובות ברשת פנימית (localhost, 10.x, 192.168.x וכו׳) חסומות כהגנה. מאזין פנימי מחייב הגדרה ב-wp-config.php (GSM_ALLOW_INTERNAL_WEBHOOKS). ' +
        'אם מוגדר "סוד", כל בקשה נחתמת ב‑HMAC‑SHA256 בכותרת <code>X-Gas-Signature</code>.</p>' +
        '<div class="gs-field"><label class="gs-label">כתובת Webhook (https)</label>' +
        '<input class="gs-input" id="int-url" type="url" placeholder="https://…" value="' + esc(cfg.webhookUrl || '') + '" style="direction:ltr"></div>' +
        '<div class="gs-field"><label class="gs-label">סוד לחתימה' + (cfg.hasSecret ? ' <span class="gs-pill gs-pill--ok">מוגדר</span>' : '') + '</label>' +
        '<input class="gs-input" id="int-secret" type="password" placeholder="' + (cfg.hasSecret ? '••••••• (השאר ריק כדי לא לשנות)' : 'אופציונלי') + '" style="direction:ltr" autocomplete="new-password"></div>' +
        (cfg.hasSecret ? '<label style="display:inline-flex;gap:6px;align-items:center;margin:0 0 10px"><input type="checkbox" id="int-clear"><span>מחק את הסוד הקיים</span></label>' : '') +
        '<div class="gs-field"><label class="gs-label">אילו אירועים לשלוח</label>' + evRows + '</div>' +
        '<button class="gs-btn" id="int-save">שמור</button> <span id="int-status" role="status" aria-live="polite" style="margin-inline-start:8px;color:var(--gs-muted)"></span>' +
        '</div>' +

        '<div class="gs-card" style="margin-top:16px"><div class="gs-card__title">📤 קישורי ייצוא (Power BI / Excel / יומן)</div>' +
        '<p style="color:var(--gs-muted);font-size:13px">הקישורים מוגנים בטוקן סודי (כמו "כתובת סודית" של יומן Google). ' +
        '<b>כל מי שמחזיק בקישור יכול לקרוא את כל הנתונים, כולל פרטי אנשי קשר</b> — שתף רק עם מערכות ואנשים מורשים. החלפת הטוקן מבטלת מיד את כל הקישורים הישנים. (ללוח המצב הציבורי יש טוקן נפרד, במסך "תקשורת והתראות".)</p>' +
        '<div class="gs-field"><label class="gs-label">טוקן BI</label>' +
        '<input class="gs-input" id="tok-val" readonly value="' + esc(token) + '" data-gsm-action="select" style="direction:ltr;font-family:monospace"></div>' +
        '<button class="gs-btn gs-btn--danger gs-btn--sm" id="tok-rotate">החלף טוקן</button> <span id="tok-status" role="status" aria-live="polite" style="margin-inline-start:8px;color:var(--gs-muted)"></span>' +
        '<div id="int-links" style="margin-top:14px">' + exportLinks(token) + '</div>' +
        '</div>';

      document.getElementById('int-save').addEventListener('click', function () {
        var btn = this, st = document.getElementById('int-status');
        btn.disabled = true; st.textContent = 'שומר…';
        var events = [];
        document.querySelectorAll('.gsm-ev:checked').forEach(function (c) { events.push(c.getAttribute('data-ev')); });
        var body = { webhookUrl: document.getElementById('int-url').value, webhookEvents: events };
        var sec = document.getElementById('int-secret').value;
        if (sec) { body.webhookSecret = sec; }
        var clr = document.getElementById('int-clear');
        if (clr && clr.checked) { body.clearSecret = true; }
        api('/integrations', { method: 'POST', body: body }).then(function () {
          st.textContent = '✓ נשמר'; btn.disabled = false;
          document.getElementById('int-secret').value = '';
        }).catch(function (e) { st.textContent = '✗ ' + (e.message || e); btn.disabled = false; });
      });

      document.getElementById('tok-rotate').addEventListener('click', function () {
        if (!confirm('להחליף את טוקן ה‑BI? כל הקישורים הישנים יפסיקו לעבוד מיד.')) return;
        var btn = this, st = document.getElementById('tok-status');
        btn.disabled = true; st.textContent = 'מחליף…';
        api('/bi/token/rotate', { method: 'POST' }).then(function (r) {
          var nt = (r && r.token) || '';
          document.getElementById('tok-val').value = nt;
          document.getElementById('int-links').innerHTML = exportLinks(nt);
          st.textContent = '✓ הוחלף'; btn.disabled = false;
        }).catch(function (e) { st.textContent = '✗ ' + (e.message || e); btn.disabled = false; });
      });
    }).catch(function (e) { main.innerHTML = '<div class="gs-error" role="alert">' + esc(e.message) + '</div>'; });
  });

  // ---- COMMUNICATION & ALERTS (reminders, urgent alerts, broadcast, log) --

  GSM.registerView('comm', function (mount) {
    if (!GSM.can('manage')) { GSM.navigate('/dashboard'); return; }
    mount.innerHTML = chrome('comm', loading());
    var main = mount.querySelector('.gs-main');

    function renderLog(rows) {
      if (!rows || !rows.length) return '<div style="color:var(--gs-muted);font-size:13px">אין רשומות עדיין</div>';
      var KIND = { reminder: 'תזכורת', alert: 'התראה', broadcast: 'שידור' };
      var CH = { email: '✉️', whatsapp: '📱' };
      return '<table class="gs-table"><thead><tr><th>תאריך</th><th>סוג</th><th>ערוץ</th><th>נמען</th><th>נושא</th><th>סטטוס</th></tr></thead><tbody>' +
        rows.map(function (r) {
          return '<tr><td style="white-space:nowrap">' + esc(r.created_at) + '</td>' +
            '<td>' + esc(KIND[r.kind] || r.kind) + '</td>' +
            '<td>' + (CH[r.channel] || esc(r.channel)) + '</td>' +
            '<td>' + esc(r.recipient) + '</td>' +
            '<td>' + esc(r.subject) + '</td>' +
            '<td><span class="gs-pill ' + (r.status === 'sent' ? 'gs-pill--ok' : 'gs-pill--warn') + '">' + esc(r.status) + (r.ref ? ' ' + esc(r.ref) : '') + '</span></td></tr>';
        }).join('') +
        '</tbody></table>';
    }

    Promise.all([
      api('/comm'),
      api('/bi/public-token').catch(function () { return {}; })
    ]).then(function (res) {
      var data = res[0] || {};
      var c = data.config || {};
      var log = data.log || [];
      var token = (res[1] && res[1].token) || '';

      var cfgRoot = (window.GSM_CONFIG || {});
      var assetsUrl = cfgRoot.assetsUrl || '';
      var restRoot = cfgRoot.restRoot || '';
      var pubBase = assetsUrl.replace(/assets\/?$/, '') + 'gas-public-dashboard.html';
      function shareUrlFor(tok) {
        // Token in the fragment (#t=): never sent to the server or in a Referer.
        return tok ? (pubBase + '?api=' + encodeURIComponent(restRoot) + '#t=' + encodeURIComponent(tok)) : '';
      }
      var shareUrl = shareUrlFor(token);

      function chk(id, label, on, hint) {
        return '<label style="display:flex;gap:8px;align-items:flex-start;margin:8px 0;cursor:pointer"><input type="checkbox" id="' + id + '"' + (on ? ' checked' : '') + ' style="margin-top:3px">' +
          '<span><b>' + esc(label) + '</b>' + (hint ? '<br><span style="color:var(--gs-muted);font-size:12px">' + esc(hint) + '</span>' : '') + '</span></label>';
      }

      main.innerHTML =
        '<div class="gs-toolbar"><h2 class="gs-h2">תקשורת והתראות</h2>' +
        '<div class="gs-toolbar__actions"><button class="gs-btn gs-btn--ghost gs-btn--sm" id="cm-test">שלח דוא״ל בדיקה</button></div></div>' +

        '<div class="gs-card"><div class="gs-card__title">⚙️ הגדרות כלליות</div>' +
        '<div class="gs-field"><label class="gs-label">שם השולח (From)</label>' +
        '<input class="gs-input" id="cm-from" value="' + esc(c.from_name || '') + '"></div>' +
        chk('cm-reminder', 'תזכורות ביקורת רבעונית (דוא״ל יומי למנהלים)', !!c.reminder_enabled, 'נשלח פעם ביום דרך WP-Cron לציוד שמועד הביקורת הרבעונית שלו מתקרב או עבר. מרווח ההתראה נקבע בהגדרות ביקורת.') +
        chk('cm-urgent', 'התראה מיידית על תקלה דחופה', !!c.alert_urgent, 'כשנפתחת תקלה בעדיפות "דחוף" — נשלח דוא״ל מיידי לכל המנהלים.') +
        '</div>' +

        '<div class="gs-card" style="margin-top:16px"><div class="gs-card__title">📱 WhatsApp / SMS (Webhook אופציונלי)</div>' +
        '<p style="color:var(--gs-muted);font-size:13px">שליחה דרך ספק חיצוני (Webhook שמקבל POST עם <code>phone</code> ו‑<code>message</code>). ' +
        'הטוקן נשמר בצד שרת בלבד ואינו נחשף ללקוח. מספרי הטלפון של המשתמשים נשמרים בפרופיל שלהם.</p>' +
        chk('cm-wa', 'הפעל שליחת WhatsApp / SMS', !!c.whatsapp_enabled, '') +
        '<div class="gs-field"><label class="gs-label">כתובת Webhook (https)</label>' +
        '<input class="gs-input" id="cm-wa-url" type="url" placeholder="https://…" value="' + esc(c.whatsapp_url || '') + '" style="direction:ltr"></div>' +
        '<div class="gs-field"><label class="gs-label">טוקן / Bearer' + (c.whatsapp_has_token ? ' <span class="gs-pill gs-pill--ok">מוגדר</span>' : '') + '</label>' +
        '<input class="gs-input" id="cm-wa-token" type="password" placeholder="' + (c.whatsapp_has_token ? '••••••• (השאר ריק כדי לא לשנות)' : 'אופציונלי') + '" style="direction:ltr" autocomplete="new-password"></div>' +
        (c.whatsapp_has_token ? '<label style="display:inline-flex;gap:6px;align-items:center;margin:0 0 10px"><input type="checkbox" id="cm-wa-clear"><span>מחק את הטוקן הקיים</span></label>' : '') +
        '<div class="gs-field"><label class="gs-label">מספר לקבלת התראות דחופות (אופציונלי)</label>' +
        '<input class="gs-input" id="cm-wa-alert" placeholder="05XXXXXXXX" value="' + esc(c.whatsapp_alert_to || '') + '" style="direction:ltr"></div>' +
        '</div>' +

        '<div style="margin-top:14px"><button class="gs-btn" id="cm-save">שמור הגדרות</button> <span id="cm-status" role="status" aria-live="polite" style="margin-inline-start:8px;color:var(--gs-muted)"></span></div>' +

        '<div class="gs-card" style="margin-top:20px"><div class="gs-card__title">📣 שליחת הודעה (Broadcast)</div>' +
        '<div class="gs-field"><label class="gs-label">נמענים</label>' +
        '<select class="gs-select" id="cm-aud">' +
        '<option value="managers">מנהלים</option>' +
        '<option value="lab_managers">מנהלי מעבדה</option>' +
        '<option value="pi">חוקרים ראשיים</option>' +
        '<option value="ahmat">אחמ"ת</option>' +
        '<option value="all">כולם</option>' +
        '</select></div>' +
        '<div class="gs-field"><label class="gs-label">נושא</label><input class="gs-input" id="cm-sub"></div>' +
        '<div class="gs-field"><label class="gs-label">תוכן</label><textarea class="gs-textarea" id="cm-msg" rows="4"></textarea></div>' +
        '<button class="gs-btn" id="cm-send">שלח הודעה</button> <span id="cm-send-status" role="status" aria-live="polite" style="margin-inline-start:8px;color:var(--gs-muted)"></span>' +
        '</div>' +

        (shareUrl ? ('<div class="gs-card" style="margin-top:16px"><div class="gs-card__title">🌐 לוח מצב ציבורי</div>' +
          '<p style="color:var(--gs-muted);font-size:13px">קישור לצפייה בנתונים מצטברים בלבד (ללא פרטים אישיים). הקישור משתמש בטוקן ציבורי נפרד, שאינו מאפשר גישה לקישורי הייצוא המלאים. החלפת הטוקן מבטלת מיד את הקישור הקודם.</p>' +
          '<div class="gs-field"><label class="gs-label" for="pub-url">קישור לשיתוף</label><input class="gs-input" id="pub-url" readonly value="' + esc(shareUrl) + '" data-gsm-action="select" style="direction:ltr;font-size:12px"></div>' +
          '<a class="gs-btn gs-btn--ghost gs-btn--sm" id="pub-open" href="' + esc(shareUrl) + '" target="_blank" rel="noopener noreferrer">פתח לוח מצב ↗</a> ' +
          '<button type="button" class="gs-btn gs-btn--danger gs-btn--sm" id="pub-rotate">החלף טוקן ציבורי</button> ' +
          '<span id="pub-status" role="status" aria-live="polite" style="margin-inline-start:8px;color:var(--gs-muted)"></span>' +
          '<div id="pub-links" style="margin-top:16px;padding-top:12px;border-top:1px solid var(--gs-border)"></div></div>') : '') +

        '<div class="gs-card" style="margin-top:20px"><div class="gs-card__title">📜 יומן שליחות אחרונות</div>' +
        '<div id="cm-log">' + renderLog(log) + '</div></div>';

      // Named links: one per recipient, each can be revoked on its own.
      function renderPubLinks(focusId) {
        var host = document.getElementById('pub-links');
        if (!host) return;
        api('/bi/public-links').then(function (d) {
          var links = d.links || [];
          var rows = links.map(function (l) {
            var url = shareUrlFor(l.token);
            return '<tr><td><b>' + esc(l.label) + '</b><div style="color:var(--gs-muted);font-size:12px">נוצר <bdi dir="ltr">' + esc((l.created_at || '').slice(0, 10)) + '</bdi>' + (l.created_by ? ' על ידי ' + esc(l.created_by) : '') + '</div></td>' +
              '<td style="text-align:center">' + esc(l.views || 0) + '</td>' +
              '<td><bdi dir="ltr">' + esc(l.last_view ? l.last_view.slice(0, 16) : '—') + '</bdi></td>' +
              '<td style="white-space:nowrap"><button type="button" class="gs-btn gs-btn--sm gs-btn--ghost" data-pl-copy="' + esc(url) + '">העתק קישור<span class="gs-visually-hidden">: ' + esc(l.label) + '</span></button> ' +
              '<button type="button" class="gs-btn gs-btn--sm gs-btn--danger" data-pl-del="' + esc(l.id) + '" data-label="' + esc(l.label) + '">בטל<span class="gs-visually-hidden">: ' + esc(l.label) + '</span></button></td></tr>';
          }).join('');
          host.innerHTML =
            '<h3 class="gs-card__title" id="pl-title" tabindex="-1" style="font-size:15px">קישורים נוספים עם שם</h3>' +
            '<p style="color:var(--gs-muted);font-size:13px;margin-top:0">קישור נפרד לכל גורם שמקבל את לוח המצב. אפשר לבטל קישור אחד בלי לפגוע באחרים, ולראות כמה פעמים נטען. לא נשמר מידע על הצופים.</p>' +
            (rows ? '<div style="overflow-x:auto"><table class="gs-table"><thead><tr><th>שם</th><th>טעינות</th><th>טעינה אחרונה</th><th>פעולות</th></tr></thead><tbody>' + rows + '</tbody></table></div>' : '') +
            (links.length < (d.max || 20) ? '<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;margin-top:10px"><div class="gs-field" style="flex:1;min-width:200px;margin:0"><label class="gs-label" for="pl-label">שם לקישור חדש (למשל: הנהלת הפקולטה)</label><input class="gs-input" id="pl-label" maxlength="80"></div>' +
              '<button type="button" class="gs-btn gs-btn--sm" id="pl-add">צור קישור</button></div>' : '<p style="font-size:13px">הגעתם למספר הקישורים המרבי.</p>') +
            '<div id="pl-st" role="status" aria-live="polite" style="margin-top:6px;color:var(--gs-muted)"></div>';
          if (focusId) { var f = document.getElementById(focusId); if (f) f.focus(); }
          var st = document.getElementById('pl-st');
          var add = document.getElementById('pl-add');
          if (add) add.addEventListener('click', function () {
            var label = document.getElementById('pl-label').value.trim();
            if (!label) { st.textContent = 'תנו שם לקישור.'; document.getElementById('pl-label').focus(); return; }
            add.disabled = true;
            api('/bi/public-links', { method: 'POST', body: { label: label } })
              .then(function () { renderPubLinks('pl-title'); setTimeout(function () { var s2 = document.getElementById('pl-st'); if (s2) s2.textContent = '✓ הקישור נוצר.'; }, 300); })
              .catch(function (e) { st.textContent = '✗ ' + (e.message || e); add.disabled = false; });
          });
          host.querySelectorAll('[data-pl-copy]').forEach(function (b) {
            b.addEventListener('click', function () {
              var url = b.getAttribute('data-pl-copy');
              if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(url).then(function () { st.textContent = '✓ הקישור הועתק.'; }, function () { window.prompt('העתיקו את הקישור:', url); });
              } else { window.prompt('העתיקו את הקישור:', url); }
            });
          });
          host.querySelectorAll('[data-pl-del]').forEach(function (b) {
            b.addEventListener('click', function () {
              if (!confirm('לבטל את הקישור "' + (b.getAttribute('data-label') || '') + '"? הוא יפסיק לעבוד מיד.')) return;
              api('/bi/public-links/' + b.getAttribute('data-pl-del'), { method: 'DELETE' })
                .then(function () { renderPubLinks('pl-title'); })
                .catch(function (e) { st.textContent = '✗ ' + (e.message || e); });
            });
          });
        }).catch(function (e) { host.innerHTML = '<div class="gs-error" role="alert">' + esc(e.message || e) + '</div>'; });
      }
      renderPubLinks();

      var pubRotate = document.getElementById('pub-rotate');
      if (pubRotate) {
        pubRotate.addEventListener('click', function () {
          if (!confirm('להחליף את הטוקן של לוח המצב הציבורי? הקישור הקודם יפסיק לעבוד מיד.')) return;
          var btn = this, st = document.getElementById('pub-status');
          btn.disabled = true; st.textContent = 'מחליף…';
          api('/bi/public-token/rotate', { method: 'POST' }).then(function (r) {
            var nu = shareUrlFor((r && r.token) || '');
            document.getElementById('pub-url').value = nu;
            document.getElementById('pub-open').setAttribute('href', nu);
            st.textContent = '✓ הוחלף'; btn.disabled = false;
          }).catch(function (e) { st.textContent = '✗ ' + (e.message || e); btn.disabled = false; });
        });
      }

      document.getElementById('cm-save').addEventListener('click', function () {
        var btn = this, st = document.getElementById('cm-status');
        btn.disabled = true; st.textContent = 'שומר…';
        var body = {
          from_name: document.getElementById('cm-from').value,
          reminder_enabled: document.getElementById('cm-reminder').checked,
          alert_urgent: document.getElementById('cm-urgent').checked,
          whatsapp_enabled: document.getElementById('cm-wa').checked,
          whatsapp_url: document.getElementById('cm-wa-url').value,
          whatsapp_alert_to: document.getElementById('cm-wa-alert').value
        };
        var tok = document.getElementById('cm-wa-token').value;
        if (tok) { body.whatsapp_token = tok; }
        var clr = document.getElementById('cm-wa-clear');
        if (clr && clr.checked) { body.whatsapp_clear = true; }
        api('/comm', { method: 'POST', body: body }).then(function () {
          st.textContent = '✓ נשמר'; btn.disabled = false;
          document.getElementById('cm-wa-token').value = '';
        }).catch(function (e) { st.textContent = '✗ ' + (e.message || e); btn.disabled = false; });
      });

      document.getElementById('cm-send').addEventListener('click', function () {
        var btn = this, st = document.getElementById('cm-send-status');
        var sub = document.getElementById('cm-sub').value.trim();
        var msg = document.getElementById('cm-msg').value.trim();
        if (!sub || !msg) { st.textContent = 'יש למלא נושא ותוכן'; return; }
        if (!confirm('לשלוח את ההודעה לנמענים שנבחרו?')) return;
        btn.disabled = true; st.textContent = 'שולח…';
        api('/comm/broadcast', { method: 'POST', body: {
          audience: document.getElementById('cm-aud').value, subject: sub, message: msg
        } }).then(function (r) {
          st.textContent = '✓ נשלח (' + ((r && r.email) || 0) + ' דוא״ל' + (r && r.whatsapp ? ', ' + r.whatsapp + ' WhatsApp' : '') + ')';
          btn.disabled = false;
          document.getElementById('cm-sub').value = ''; document.getElementById('cm-msg').value = '';
          api('/comm').then(function (d) { document.getElementById('cm-log').innerHTML = renderLog((d && d.log) || []); }).catch(function () {});
        }).catch(function (e) { st.textContent = '✗ ' + (e.message || e); btn.disabled = false; });
      });

      document.getElementById('cm-test').addEventListener('click', function () {
        var btn = this, st = document.getElementById('cm-status');
        btn.disabled = true; if (st) st.textContent = 'שולח בדיקה…';
        api('/comm/test', { method: 'POST' }).then(function (r) {
          if (st) st.textContent = (r && r.sent) ? ('✓ נשלח ל‑' + (r.to || '')) : '✗ שליחה נכשלה (בדוק הגדרות דוא״ל בשרת)';
          btn.disabled = false;
        }).catch(function (e) { if (st) st.textContent = '✗ ' + (e.message || e); btn.disabled = false; });
      });
    }).catch(function (e) { main.innerHTML = '<div class="gs-error" role="alert">' + esc(e.message) + '</div>'; });
  });

  // ---- INSPECTION CONFIG (role-based checklists + rules) ------------------

  GSM.registerView('inspcfg', function (mount) {
    if (!GSM.can('manage')) { GSM.navigate('/dashboard'); return; }
    mount.innerHTML = chrome('inspcfg', loading());
    var main = mount.querySelector('.gs-main');

    api('/inspection-config').then(function (cfg) {
      var r = cfg.rules || {};
      var cl = cfg.checklists || { quarterly: [], monthly: [] };

      function itemRow(text) {
        return '<div class="gs-ci-row" style="display:flex;gap:8px;margin-bottom:6px">' +
          '<input class="gs-input gsm-ci" value="' + esc(text || '') + '" placeholder="תיאור סעיף" style="flex:1">' +
          '<button class="gs-btn gs-btn--sm gs-btn--danger gsm-ci-del" type="button">✕</button></div>';
      }
      function editor(key, title, items) {
        return '<div class="gs-card" style="margin-bottom:16px"><div class="gs-card__title">' + title + '</div><div id="cl-' + key + '">' +
          (items || []).map(function (it) { return itemRow(it && it.text ? it.text : (typeof it === 'string' ? it : '')); }).join('') +
          '</div><button class="gs-btn gs-btn--ghost gs-btn--sm" type="button" data-add="' + key + '">+ הוסף סעיף</button></div>';
      }
      function chk(id, label, v) {
        return '<label style="display:flex;gap:8px;align-items:center;margin:6px 0"><input type="checkbox" id="' + id + '"' + (v ? ' checked' : '') + '><span>' + esc(label) + '</span></label>';
      }

      main.innerHTML =
        '<div class="gs-toolbar"><h2 class="gs-h2">הגדרות ביקורת</h2><div class="gs-toolbar__actions"><button class="gs-btn gs-btn--export" type="button" data-gsm-action="export" data-type="checklists">⬇️ ייצוא צ׳ק-ליסטים</button><button class="gs-btn" id="cfg-save">שמור</button><span id="cfg-status" role="status" aria-live="polite" style="margin-inline-start:10px;color:var(--gs-muted)"></span></div></div>' +
        editor('quarterly', 'צ׳קליסט רבעוני — אחמ"ת (ארוך)', cl.quarterly) +
        editor('monthly', 'צ׳קליסט חודשי — מנהל מעבדה / חוקר ראשי (קצר)', cl.monthly) +
        '<div class="gs-card"><div class="gs-card__title">כללי תזכורת</div>' +
        '<div class="gs-field"><label class="gs-label">תזכורת כמה ימים לפני דד-ליין</label><input class="gs-input" id="rule-rb" type="number" value="' + esc(r.reminderBeforeDays != null ? r.reminderBeforeDays : 7) + '" style="max-width:140px"></div>' +
        chk('rule-na', 'תזכורת לאחמ"ת (רבעוני)', r.notifyAhmat) +
        chk('rule-nl', 'תזכורת למנהל מעבדה (חודשי)', r.notifyLabManager) +
        chk('rule-np', 'תזכורת לחוקר ראשי (חודשי)', r.notifyPI) +
        chk('rule-nc', 'התראה בסיום ביקורת', r.notifyOnComplete) +
        '</div>';

      main.addEventListener('click', function (e) {
        if (e.target && e.target.getAttribute && e.target.getAttribute('data-add')) {
          var box = document.getElementById('cl-' + e.target.getAttribute('data-add'));
          if (box) { var div = document.createElement('div'); div.innerHTML = itemRow(''); box.appendChild(div.firstChild); }
        } else if (e.target && e.target.classList && e.target.classList.contains('gsm-ci-del')) {
          var row = e.target.closest('.gs-ci-row'); if (row) row.remove();
        }
      });

      function collect(key) {
        var out = [];
        document.querySelectorAll('#cl-' + key + ' .gsm-ci').forEach(function (inp) {
          var t = inp.value.trim(); if (t) out.push({ text: t });
        });
        return out;
      }

      document.getElementById('cfg-save').addEventListener('click', function () {
        var btn = this; var status = document.getElementById('cfg-status');
        btn.disabled = true; status.textContent = 'שומר…';
        api('/inspection-config', {
          method: 'POST',
          body: {
            rules: {
              reminderBeforeDays: parseInt(document.getElementById('rule-rb').value, 10) || 7,
              notifyAhmat: document.getElementById('rule-na').checked,
              notifyLabManager: document.getElementById('rule-nl').checked,
              notifyPI: document.getElementById('rule-np').checked,
              notifyOnComplete: document.getElementById('rule-nc').checked
            },
            checklists: { quarterly: collect('quarterly'), monthly: collect('monthly') }
          }
        }).then(function () { status.textContent = '✓ נשמר'; btn.disabled = false; })
          .catch(function (e) { status.textContent = '✗ ' + (e.message || e); btn.disabled = false; });
      });
    }).catch(function (e) { main.innerHTML = '<div class="gs-error" role="alert">' + esc(e.message) + '</div>'; });
  });

  // ---- REPORTS (Excel export center) --------------------------------------

  GSM.registerView('reports', function (mount) {
    mount.innerHTML = chrome('reports', '');
    var main = mount.querySelector('.gs-main');
    function card(type, title, desc) {
      return '<div class="gs-card" style="max-width:320px;display:inline-block;vertical-align:top;margin:0 0 16px 16px">' +
        '<div class="gs-card__title">' + esc(title) + '</div>' +
        '<p style="color:var(--gs-muted);min-height:38px">' + esc(desc) + '</p>' +
        '<button class="gs-btn gs-btn--export" type="button" data-gsm-action="export" data-type="' + esc(type) + '">⬇️ הורדה</button></div>';
    }
    main.innerHTML =
      '<div class="gs-toolbar"><h2 class="gs-h2">הנפקת דוחות אקסל</h2></div>' +
      '<p style="color:var(--gs-muted);margin-top:-8px">לחיצה על דוח תוריד קובץ .xlsx מוכן לשימוש ב-Excel.</p>' +
      '<div style="margin-top:12px">' +
      card('equipment', 'דוח ציוד מלא', 'כל הציוד: שם, גז, מיקום, חוקר ראשי, מספר פאנל, תאריך ביקורת ועוד.') +
      card('inspections', 'דוח ביקורות', 'כל הביקורות — סוג, תאריך, מבצע ותוצאה.') +
      card('faults', 'דוח תקלות', 'כל התקלות הפתוחות והסגורות: עדיפות, סטטוס, תיאור.') +
      card('scans', 'דוח סריקות', 'כל סריקות ה-QR של הציוד.') +
      card('checklists', 'צ׳ק-ליסטים', 'שני הצ׳ק-ליסטים — חודשי ורבעוני — בגיליון אחד.') +
      '</div>';
  });

  // ---- DATA QUALITY -------------------------------------------------------

  GSM.registerView('quality', function (mount) {
    mount.innerHTML = chrome('quality', loading());
    var main = mount.querySelector('.gs-main');
    api('/quality').then(function (d) {
      var sum = d.summary || {};
      var tiles = Object.keys(sum).map(function (k) {
        var s = sum[k];
        return '<div class="gs-stat gs-stat--' + (s.sev === 'danger' ? 'danger' : 'warn') + '">' +
          '<div class="gs-stat__num">' + esc(s.count) + '</div><div class="gs-stat__label">' + esc(s.label) + '</div></div>';
      }).join('');
      var lists = Object.keys(sum).map(function (k) {
        var s = sum[k]; var items = (d.issues && d.issues[k]) || [];
        if (!items.length) return '';
        var rows = items.map(function (it) {
          return '<a href="#/equipment/' + it.id + '" style="display:inline-block;margin:0 0 6px 6px;padding:4px 10px;background:var(--gs-surface-2);border:1px solid var(--gs-border);border-radius:8px;color:var(--gs-navy)">' + esc(it.name) + '</a>';
        }).join('');
        return '<div class="gs-card" style="margin-bottom:16px"><div class="gs-card__title">' + esc(s.label) + ' (' + items.length + ')</div>' + rows + '</div>';
      }).join('');
      main.innerHTML =
        '<div class="gs-toolbar"><h2 class="gs-h2">טיוב מידע</h2><div class="gs-toolbar__actions" style="color:var(--gs-muted)">סה"כ ציוד: ' + esc(d.total) + '</div></div>' +
        '<div class="gs-grid gs-grid--stats">' + tiles + '</div>' +
        '<div style="margin-top:20px">' + (lists || '<div class="gs-empty">אין חוסרים — המידע תקין 🎉</div>') + '</div>';
    }).catch(function (e) { main.innerHTML = '<div class="gs-error" role="alert">' + esc(e.message) + '</div>'; });
  });

  // ---- MY PROFILE ---------------------------------------------------------

  var AVATARS = ['🧑‍🔬', '👩‍🔬', '👨‍🔬', '🧑‍🏭', '👷', '🧑‍💼', '🛡️', '⭐', '😎', '🙂'];

  GSM.registerView('profile', function (mount) {
    mount.innerHTML = chrome('profile', loading());
    var main = mount.querySelector('.gs-main');
    api('/profile').then(function (p) {
      var cur = p.avatar || '';
      var avHtml = AVATARS.map(function (a) {
        return '<button type="button" class="gsm-av' + (a === cur ? ' is-sel' : '') + '" data-av="' + a + '">' + a + '</button>';
      }).join('');
      main.innerHTML =
        '<div class="gs-toolbar"><h2 class="gs-h2">הפרופיל שלי</h2></div>' +
        '<div class="gs-card" style="max-width:480px">' +
        '<div class="gs-field"><label class="gs-label">אווטאר</label><div class="gsm-av-row">' + avHtml + '</div>' +
        '<input type="hidden" id="pf-avatar" value="' + esc(cur) + '"></div>' +
        '<div class="gs-field"><label class="gs-label">שם מלא</label><input class="gs-input" id="pf-name" value="' + esc(p.name || '') + '"></div>' +
        '<div class="gs-field"><label class="gs-label">אימייל (מזהה כניסה)</label><input class="gs-input" value="' + esc(p.email || '') + '" readonly style="background:#f1f4f8"></div>' +
        '<div class="gs-field"><label class="gs-label">טלפון נייד (לוואטסאפ/תזכורות)</label><input class="gs-input" id="pf-phone" value="' + esc(p.phone || '') + '"></div>' +
        '<button class="gs-btn" id="pf-save">שמור פרופיל</button><span id="pf-status" role="status" aria-live="polite" style="margin-inline-start:10px;color:var(--gs-muted)"></span>' +
        '</div>' +
        '<div class="gs-card" style="max-width:480px;margin-top:16px;border-inline-start:4px solid var(--gs-danger)">' +
        '<div class="gs-card__title">מחיקת פרטים — חוק הגנת הפרטיות (תיקון 13)</div>' +
        '<p style="color:var(--gs-muted);font-size:13px">בקשה למחיקת הפרטים האישיים שלך. הטלפון והאווטאר יימחקו מיד, והבקשה תועבר למנהל.</p>' +
        '<textarea class="gs-textarea" id="pf-del-reason" rows="2" placeholder="סיבת הבקשה (אופציונלי)"></textarea>' +
        '<button class="gs-btn gs-btn--danger" id="pf-del" style="margin-top:8px">בקש מחיקת פרטים</button><span id="pf-del-status" role="status" aria-live="polite" style="margin-inline-start:10px;color:var(--gs-muted)"></span>' +
        '</div>';

      main.querySelectorAll('.gsm-av').forEach(function (b) {
        b.addEventListener('click', function () {
          main.querySelectorAll('.gsm-av').forEach(function (x) { x.classList.remove('is-sel'); });
          b.classList.add('is-sel');
          document.getElementById('pf-avatar').value = b.getAttribute('data-av');
        });
      });
      document.getElementById('pf-save').addEventListener('click', function () {
        var btn = this, st = document.getElementById('pf-status');
        btn.disabled = true; st.textContent = 'שומר…';
        api('/profile', { method: 'POST', body: { name: document.getElementById('pf-name').value, phone: document.getElementById('pf-phone').value, avatar: document.getElementById('pf-avatar').value } })
          .then(function () { st.textContent = '✓ נשמר'; btn.disabled = false; })
          .catch(function (e) { st.textContent = '✗ ' + e.message; btn.disabled = false; });
      });
      document.getElementById('pf-del').addEventListener('click', function () {
        if (!confirm('לשלוח בקשת מחיקת פרטים? הטלפון והאווטאר יימחקו מיד.')) return;
        var btn = this, st = document.getElementById('pf-del-status');
        btn.disabled = true; st.textContent = 'שולח…';
        api('/profile/deletion-request', { method: 'POST', body: { reason: document.getElementById('pf-del-reason').value } })
          .then(function () { st.textContent = '✓ הבקשה נשלחה'; })
          .catch(function (e) { st.textContent = '✗ ' + e.message; btn.disabled = false; });
      });
    }).catch(function (e) { main.innerHTML = '<div class="gs-error" role="alert">' + esc(e.message) + '</div>'; });
  });

  // ---- SECURITY (guidance) ------------------------------------------------

  function renderPasskeys(host, focusId, msg) {
    if (!host) return;
    api('/passkeys').then(function (d) {
      var items = d.items || [];
      var supported = GSM.passkeysSupported();
      var rows = items.map(function (p) {
        return '<tr><td><b>' + esc(p.name) + '</b></td><td><bdi dir="ltr">' + esc((p.created || '').slice(0, 10)) + '</bdi></td><td><bdi dir="ltr">' + esc(p.last_used ? p.last_used.slice(0, 16) : '—') + '</bdi></td>' +
          '<td><button type="button" class="gs-btn gs-btn--sm gs-btn--danger" data-pk-del="' + esc(p.key) + '" data-name="' + esc(p.name) + '">הסר<span class="gs-visually-hidden">: ' + esc(p.name) + '</span></button></td></tr>';
      }).join('');
      var body;
      if (!d.available) {
        body = '<p style="color:var(--gs-muted)">Passkeys זמינים רק כשהאתר פועל ב-https עם שם מתחם.</p>';
      } else if (!supported) {
        body = '<p style="color:var(--gs-muted)">הדפדפן הזה אינו תומך ב-Passkeys.</p>';
      } else {
        body = (items.length < (d.max || 10)
          ? '<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end"><div class="gs-field" style="flex:1;min-width:200px;margin:0"><label class="gs-label" for="pk-name">שם למכשיר (למשל: הטלפון שלי)</label><input class="gs-input" id="pk-name" maxlength="60"></div>' +
            '<button type="button" class="gs-btn" id="pk-add">הוסף Passkey</button></div>'
          : '<p style="font-size:13px">הגעתם למספר ה-Passkeys המרבי.</p>');
      }
      host.innerHTML = '<div class="gs-card" style="max-width:720px;margin-top:16px"><h3 class="gs-card__title" id="pk-title" tabindex="-1">Passkeys — כניסה בטביעת אצבע, זיהוי פנים או קוד המכשיר</h3>' +
        '<p style="font-size:13.5px;margin-top:0">כניסה בלי סיסמה: המכשיר מאמת אתכם (טביעת אצבע, פנים או קוד), ולכן אין צורך גם בקוד האימות. במערכת נשמר רק מפתח ציבורי.</p>' +
        (rows ? '<div style="overflow-x:auto"><table class="gs-table"><thead><tr><th>מכשיר</th><th>נוסף</th><th>שימוש אחרון</th><th>פעולות</th></tr></thead><tbody>' + rows + '</tbody></table></div>' : '<p style="color:var(--gs-muted);font-size:13px">עדיין לא הוגדר Passkey.</p>') +
        body + '<div id="pk-st" role="status" aria-live="polite" style="margin-top:8px;color:var(--gs-muted)">' + esc(msg || '') + '</div></div>';
      if (focusId) { var f = document.getElementById(focusId); if (f) f.focus(); }
      var st = document.getElementById('pk-st');
      var add = document.getElementById('pk-add');
      if (add) add.addEventListener('click', function () {
        add.disabled = true; st.textContent = 'ממתין לאישור במכשיר…';
        GSM.passkeyRegister(document.getElementById('pk-name').value.trim()).then(function () {
          renderPasskeys(host, 'pk-title', '✓ ה-Passkey נוסף.');
        }).catch(function (e) {
          add.disabled = false;
          st.textContent = (e && (e.name === 'NotAllowedError' || e.name === 'AbortError')) ? 'הרישום בוטל.' : (e && e.name === 'InvalidStateError' ? 'המכשיר הזה כבר רשום.' : '✗ ' + ((e && e.message) || e));
        });
      });
      host.querySelectorAll('[data-pk-del]').forEach(function (b) {
        b.addEventListener('click', function () {
          if (!confirm('להסיר את ה-Passkey "' + (b.getAttribute('data-name') || '') + '"? לא יהיה אפשר להיכנס איתו יותר.')) return;
          api('/passkeys/' + b.getAttribute('data-pk-del'), { method: 'DELETE' })
            .then(function () { renderPasskeys(host, 'pk-title', '✓ ה-Passkey הוסר.'); })
            .catch(function (e) { st.textContent = '✗ ' + (e.message || e); });
        });
      });
    }).catch(function (e) { host.innerHTML = '<div class="gs-error" role="alert" style="max-width:720px;margin-top:16px">' + esc(e.message || e) + '</div>'; });
  }

  GSM.registerView('security', function (mount) {
    mount.innerHTML = chrome('security', loading());
    var main = mount.querySelector('.gs-main');

    function codesCard(codes) {
      return '<div class="gs-card" style="max-width:720px;margin-top:16px;border-inline-start:4px solid var(--gs-gold)"><h3 class="gs-card__title" id="tf-codes-title" tabindex="-1">קודי גיבוי — שמרו אותם עכשיו</h3>' +
        '<p style="font-size:13.5px;margin-top:0">כל קוד פותח כניסה אחת כשהטלפון לא זמין. <b>הקודים מוצגים פעם אחת בלבד.</b> שמרו אותם במקום בטוח (מנהל סיסמאות או הדפסה).</p>' +
        '<ul id="tf-codes" dir="ltr" style="columns:2;font-family:monospace;font-size:15px;list-style:none;padding:0;margin:0 0 10px;text-align:left">' +
        codes.map(function (c) { return '<li style="padding:3px 0">' + esc(c) + '</li>'; }).join('') + '</ul>' +
        '<button type="button" class="gs-btn gs-btn--sm gs-btn--ghost" id="tf-codes-copy">העתק את הקודים</button> ' +
        '<span id="tf-codes-st" role="status" aria-live="polite" style="color:var(--gs-muted)"></span></div>';
    }
    function wireCodes(codes) {
      var t = document.getElementById('tf-codes-title'); if (t) t.focus();
      var b = document.getElementById('tf-codes-copy');
      if (b) b.addEventListener('click', function () {
        var txt = codes.join('\n'), st = document.getElementById('tf-codes-st');
        if (navigator.clipboard && navigator.clipboard.writeText) {
          navigator.clipboard.writeText(txt).then(function () { st.textContent = '✓ הועתק'; }, function () { window.prompt('העתיקו:', txt); });
        } else { window.prompt('העתיקו:', txt); }
      });
    }

    function draw(s, extraHtml, after) {
      var u = GSM.session.user || {};
      var head = '<div class="gs-toolbar"><h2 class="gs-h2">אבטחה</h2></div>';
      if (s.setup_required) {
        head += '<div class="gs-error" role="alert" style="max-width:720px;margin-bottom:12px">אימות דו-שלבי הוא חובה למנהלים. הפעילו אותו כדי להמשיך לעבוד במערכת.</div>';
      }
      if (s.bypassed) {
        head += '<div class="gs-error" role="alert" style="max-width:720px;margin-bottom:12px">האימות הדו-שלבי מנוטרל כרגע בהגדרות השרת (GSM_2FA_DISABLE). הסירו את ההגדרה מ-wp-config.php כדי להחזיר אותו.</div>';
      }
      var card = '<div class="gs-card" style="max-width:720px"><h3 class="gs-card__title">אימות דו-שלבי (2FA)</h3>';
      if (!s.available) {
        card += '<p>השרת אינו תומך בהצפנה הנדרשת (OpenSSL עם AES-256-GCM), ולכן אי אפשר להפעיל אימות דו-שלבי. פנו למנהל השרת.</p>';
      } else if (s.enabled) {
        card += '<p role="status"><span class="gs-pill gs-pill--ok">פעיל</span> ' + (s.since ? 'מאז <bdi dir="ltr">' + esc(s.since) + '</bdi>. ' : '') +
          'נותרו <b>' + esc(s.backup_left) + '</b> קודי גיבוי.</p>' +
          '<p style="color:var(--gs-muted);font-size:13px">בכל כניסה תתבקשו להזין, אחרי הסיסמה, קוד בן 6 ספרות מאפליקציית האימות.</p>' +
          '<div class="gs-field"><label class="gs-label" for="tf-code">קוד אימות נוכחי (נדרש לפעולות שלמטה)</label>' +
          '<input class="gs-input" id="tf-code" inputmode="numeric" autocomplete="one-time-code" dir="ltr" maxlength="12" style="max-width:200px"></div>' +
          '<button type="button" class="gs-btn gs-btn--ghost" id="tf-newcodes">הפק קודי גיבוי חדשים</button> ' +
          (s.require_managers && s.is_manager ? '' :
            '<div style="margin-top:14px;padding-top:12px;border-top:1px solid var(--gs-border)"><div class="gs-field"><label class="gs-label" for="tf-pass">סיסמה (לביטול האימות הדו-שלבי)</label>' +
            '<input class="gs-input" id="tf-pass" type="password" autocomplete="current-password" style="max-width:260px"></div>' +
            '<button type="button" class="gs-btn gs-btn--danger" id="tf-disable">בטל אימות דו-שלבי</button></div>') +
          '<div id="tf-st" role="status" aria-live="polite" style="margin-top:8px;color:var(--gs-muted)"></div>';
      } else {
        card += '<p>שכבת הגנה נוספת לכניסה: אחרי הסיסמה מזינים קוד בן 6 ספרות מאפליקציית אימות בטלפון (Google Authenticator, Microsoft Authenticator, Authy וכדומה). ' +
          'גם מי שגילה את הסיסמה לא יוכל להיכנס בלי הטלפון.</p>' +
          '<button type="button" class="gs-btn" id="tf-start">הפעל אימות דו-שלבי</button>' +
          '<div id="tf-setup" style="margin-top:14px"></div>' +
          '<div id="tf-st" role="status" aria-live="polite" style="margin-top:8px;color:var(--gs-muted)"></div>';
      }
      card += '</div>';

      var policy = '';
      if (s.is_manager && !s.setup_required) {
        policy = '<div class="gs-card" style="max-width:720px;margin-top:16px"><h3 class="gs-card__title">מדיניות למנהלים</h3>' +
          '<label style="display:flex;gap:8px;align-items:flex-start"><input type="checkbox" id="tf-policy"' + (s.require_managers ? ' checked' : '') + ' aria-describedby="tf-policy-help"><span><b>חובת אימות דו-שלבי לכל המנהלים</b>' +
          '<span id="tf-policy-help" style="display:block;color:var(--gs-muted);font-size:12.5px">מנהל שעוד לא הפעיל יופנה למסך הזה אחרי הכניסה, ופעולות ניהול ייחסמו עד שיסיים. אפשר להפעיל את החובה רק אחרי שהפעלתם בעצמכם.</span></span></label>' +
          '<div id="tf-policy-st" role="status" aria-live="polite" style="margin-top:6px;color:var(--gs-muted)"></div></div>';
      }

      var more = '<div class="gs-card" style="max-width:720px;margin-top:16px"><h3 class="gs-card__title">שכבות נוספות</h3>' +
        '<ul style="margin:0;padding-inline-start:18px;font-size:13.5px;line-height:1.8">' +
        '<li>הכניסה מוגבלת ל-5 ניסיונות שגויים ב-10 דקות, והאימות הדו-שלבי ל-5 קודים שגויים ב-15 דקות.</li>' +
        '<li>אבד הטלפון? משתמשים בקוד גיבוי, או מבקשים ממנהל לאפס את האימות במסך "ניהול אנשי אחמ"ת".</li>' +
        '<li>הגנת reCAPTCHA מבוטים היא אופציונלית, ומוגדרת במסך "הגדרות אתר".</li>' +
        '</ul></div>';

      main.innerHTML = head + card + (extraHtml || '') + '<div id="pk-host"></div>' + policy + more;
      renderPasskeys(document.getElementById('pk-host'));
      if (after) after();
      var st = document.getElementById('tf-st');
      function fail(e, btn) { st.textContent = '✗ ' + (e.message || e); if (btn) btn.disabled = false; }

      var start = document.getElementById('tf-start');
      if (start) start.addEventListener('click', function () {
        start.disabled = true; st.textContent = 'מכין…';
        api('/2fa/setup', { method: 'POST' }).then(function (d) {
          st.textContent = '';
          var host = document.getElementById('tf-setup');
          var qr = GSM.qrPng ? '<img src="' + GSM.qrPng(d.uri, 200) + '" width="200" height="200" alt="קוד QR להוספת החשבון לאפליקציית האימות. אפשר גם להקליד את המפתח שמופיע לצד הקוד.">' : '';
          host.innerHTML =
            '<ol style="padding-inline-start:18px;font-size:14px;line-height:1.8;margin:0 0 10px"><li>פתחו את אפליקציית האימות בטלפון ובחרו "הוספת חשבון".</li><li>סרקו את הקוד, או הקלידו את המפתח ידנית.</li><li>הזינו כאן את הקוד בן 6 הספרות שמופיע באפליקציה.</li></ol>' +
            '<div style="display:flex;flex-wrap:wrap;gap:16px;align-items:flex-start">' + qr +
            '<div style="flex:1;min-width:220px"><div class="gs-label">מפתח להקלדה ידנית</div>' +
            '<code id="tf-secret" dir="ltr" style="display:block;font-size:15px;overflow-wrap:anywhere;background:var(--gs-bg);padding:8px 10px;border-radius:8px;margin-bottom:10px">' + esc(d.secret.replace(/(.{4})/g, '$1 ').trim()) + '</code>' +
            '<div class="gs-field"><label class="gs-label" for="tf-code">קוד מהאפליקציה</label>' +
            '<input class="gs-input" id="tf-code" inputmode="numeric" autocomplete="one-time-code" dir="ltr" maxlength="6" style="max-width:160px"></div>' +
            '<button type="button" class="gs-btn" id="tf-confirm">אשר והפעל</button></div></div>';
          var codeEl = document.getElementById('tf-code'); codeEl.focus();
          function confirmIt() {
            var b = document.getElementById('tf-confirm'); b.disabled = true; st.textContent = 'בודק…';
            api('/2fa/enable', { method: 'POST', body: { code: codeEl.value.trim() } }).then(function (out) {
              if (GSM.session.user) { GSM.session.user.twofa = true; GSM.session.user.must2fa = false; }
              return api('/2fa').then(function (ns) { draw(ns, codesCard(out.backup_codes), function () { wireCodes(out.backup_codes); }); });
            }).catch(function (e) { fail(e, b); codeEl.value = ''; codeEl.focus(); });
          }
          document.getElementById('tf-confirm').addEventListener('click', confirmIt);
          codeEl.addEventListener('keydown', function (e) { if (e.key === 'Enter') confirmIt(); });
        }).catch(function (e) { fail(e, start); });
      });

      var nc = document.getElementById('tf-newcodes');
      if (nc) nc.addEventListener('click', function () {
        if (!confirm('להפיק קודי גיבוי חדשים? הקודים הישנים יפסיקו לעבוד.')) return;
        nc.disabled = true;
        api('/2fa/backup-codes', { method: 'POST', body: { code: document.getElementById('tf-code').value.trim() } }).then(function (out) {
          return api('/2fa').then(function (ns) { draw(ns, codesCard(out.backup_codes), function () { wireCodes(out.backup_codes); }); });
        }).catch(function (e) { fail(e, nc); });
      });
      var dis = document.getElementById('tf-disable');
      if (dis) dis.addEventListener('click', function () {
        if (!confirm('לבטל את האימות הדו-שלבי? הכניסה תהיה מוגנת בסיסמה בלבד.')) return;
        dis.disabled = true;
        api('/2fa/disable', { method: 'POST', body: { code: document.getElementById('tf-code').value.trim(), password: document.getElementById('tf-pass').value } }).then(function (ns) {
          if (GSM.session.user) GSM.session.user.twofa = false;
          draw(ns); document.getElementById('tf-st').textContent = '✓ האימות הדו-שלבי בוטל.';
        }).catch(function (e) { fail(e, dis); });
      });
      var pol = document.getElementById('tf-policy');
      if (pol) pol.addEventListener('change', function () {
        var pst = document.getElementById('tf-policy-st'); pol.disabled = true;
        api('/2fa/policy', { method: 'POST', body: { require_managers: pol.checked } }).then(function (ns) {
          draw(ns); document.getElementById('tf-policy-st').textContent = '✓ נשמר.';
        }).catch(function (e) { pol.checked = !pol.checked; pol.disabled = false; pst.textContent = '✗ ' + (e.message || e); });
      });
    }

    api('/2fa').then(function (s) { draw(s); }).catch(function (e) { main.innerHTML = '<div class="gs-error" role="alert">' + esc(e.message || e) + '</div>'; });
  });

  // ---- BRIDGE (scan page) DESIGN -----------------------------------------

  GSM.registerView('brsetup', function (mount) {
    if (!GSM.can('manage')) { GSM.navigate('/dashboard'); return; }
    mount.innerHTML = chrome('brsetup', loading());
    var main = mount.querySelector('.gs-main');
    api('/bridge-config').then(function (c) {
      function chk(id, label) {
        return '<label style="display:flex;gap:8px;align-items:center;margin:6px 0"><input type="checkbox" id="' + id + '"' + (c[id.replace('bc-', '')] !== false ? ' checked' : '') + '><span>' + esc(label) + '</span></label>';
      }
      main.innerHTML =
        '<div class="gs-toolbar"><h2 class="gs-h2">עיצוב עמוד סריקה</h2><div class="gs-toolbar__actions"><button class="gs-btn" id="bc-save">שמור</button><span id="bc-status" role="status" aria-live="polite" style="margin-inline-start:10px;color:var(--gs-muted)"></span></div></div>' +
        '<div class="gs-card" style="max-width:520px"><div class="gs-card__title">שדות וכפתורים מוצגים בדף הגשר</div>' +
        chk('bc-showStatus', 'הצג סטטוס') +
        chk('bc-showPI', 'הצג חוקר ראשי') +
        chk('bc-showLabManager', 'הצג מנהלי מעבדה') +
        chk('bc-showMaintenance', 'הצג אנשי תחזוקה') +
        chk('bc-showPhone', 'הצג טלפון') +
        chk('bc-showFaultButton', 'הצג כפתור "דווח תקלה"') +
        chk('bc-compactMode', 'מצב מצומצם (פחות שדות)') +
        '<div class="gs-field" style="margin-top:12px"><label class="gs-label">צבע ראשי</label><input class="gs-input" id="bc-primaryColor" type="color" value="' + esc(c.primaryColor || '#002147') + '" style="max-width:80px;height:40px;padding:2px"></div>' +
        '<div class="gs-field"><label class="gs-label">צבע משני</label><input class="gs-input" id="bc-accentColor" type="color" value="' + esc(c.accentColor || '#e0a526') + '" style="max-width:80px;height:40px;padding:2px"></div>' +
        '</div>';
      document.getElementById('bc-save').addEventListener('click', function () {
        var btn = this, st = document.getElementById('bc-status');
        btn.disabled = true; st.textContent = 'שומר…';
        api('/bridge-config', { method: 'POST', body: {
          showStatus: document.getElementById('bc-showStatus').checked,
          showPI: document.getElementById('bc-showPI').checked,
          showLabManager: document.getElementById('bc-showLabManager').checked,
          showMaintenance: document.getElementById('bc-showMaintenance').checked,
          showPhone: document.getElementById('bc-showPhone').checked,
          showFaultButton: document.getElementById('bc-showFaultButton').checked,
          compactMode: document.getElementById('bc-compactMode').checked,
          primaryColor: document.getElementById('bc-primaryColor').value,
          accentColor: document.getElementById('bc-accentColor').value
        } }).then(function () { st.textContent = '✓ נשמר'; btn.disabled = false; })
          .catch(function (e) { st.textContent = '✗ ' + e.message; btn.disabled = false; });
      });
    }).catch(function (e) { main.innerHTML = '<div class="gs-error" role="alert">' + esc(e.message) + '</div>'; });
  });

  // ---- AUDIT LOG ----------------------------------------------------------

  GSM._auditUndo = function (id, btn) {
    if (!confirm('לשחזר את הפריט שנמחק?')) { return; }
    if (btn) { btn.disabled = true; btn.textContent = 'משחזר…'; }
    GSM.api('/audit/' + id + '/undo', { method: 'POST' }).then(function () {
      if (btn && btn.parentNode) { btn.parentNode.innerHTML = '<span class="gs-pill gs-pill--ok">שוחזר</span>'; }
    }).catch(function (e) {
      if (btn) { btn.disabled = false; btn.textContent = '↩ שחזר'; }
      alert('שחזור נכשל: ' + (e.message || e));
    });
  };

  // ---- RESTORE POINTS ------------------------------------------------------

  GSM._rpRestore = function (id, btn) {
    if (!confirm('לשחזר מנקודה זו?\nרשומות שנמחקו/שונו יחזרו למצב שבצילום. רשומות חדשות לא יימחקו. נשמר גיבוי אוטומטי לפני.')) { return; }
    btn.disabled = true; btn.textContent = 'משחזר…';
    GSM.api('/restore-points/' + id + '/restore', { method: 'POST' }).then(function () {
      alert('שוחזר בהצלחה.'); if (GSM._rpReload) { GSM._rpReload(); }
    }).catch(function (e) { btn.disabled = false; btn.textContent = '↩ שחזר'; alert('שחזור נכשל: ' + (e.message || e)); });
  };
  GSM._rpDelete = function (id, btn) {
    if (!confirm('למחוק נקודת שחזור זו?')) { return; }
    btn.disabled = true;
    GSM.api('/restore-points/' + id, { method: 'DELETE' }).then(function () { if (GSM._rpReload) { GSM._rpReload(); } })
      .catch(function (e) { btn.disabled = false; alert('שגיאה: ' + (e.message || e)); });
  };
  GSM._rpDownload = function (id) {
    GSM.api('/restore-points/' + id + '/download').then(function (r) {
      var bin = atob(r.b64 || ''); var bytes = new Uint8Array(bin.length);
      for (var i = 0; i < bin.length; i++) { bytes[i] = bin.charCodeAt(i); }
      var blob = new Blob([bytes], { type: r.mime || 'application/json' });
      var url = URL.createObjectURL(blob); var a = document.createElement('a');
      a.href = url; a.download = r.filename || ('restore-point-' + id + '.json');
      document.body.appendChild(a); a.click();
      setTimeout(function () { URL.revokeObjectURL(url); a.remove(); }, 1200);
    }).catch(function (e) { alert('הורדה נכשלה: ' + (e.message || e)); });
  };

  GSM.registerView('restorepts', function (mount) {
    if (!GSM.can('manage')) { GSM.navigate('/dashboard'); return; }
    mount.innerHTML = chrome('restorepts', loading());
    var main = mount.querySelector('.gs-main');
    function load() {
      api('/restore-points').then(function (pts) {
        pts = pts || [];
        var rows = pts.map(function (p) {
          var c = p.counts || {};
          var cnt = 'ציוד ' + (c.equipment || 0) + ' · ביקורות ' + (c.inspections || 0) + ' · תקלות ' + (c.faults || 0);
          var kind = p.kind === 'auto' ? '<span class="gs-pill gs-pill--warn">אוטומטי</span>' : '<span class="gs-pill gs-pill--ok">ידני</span>';
          return '<tr><td>' + esc(p.created_at) + '</td><td>' + esc(p.label) + ' ' + kind + '</td>' +
            '<td style="font-size:12px;color:var(--gs-muted)">' + esc(cnt) + '</td>' +
            '<td style="white-space:nowrap">' +
              '<button class="gs-btn gs-btn--sm" type="button" data-gsm-action="rpRestore" data-id="' + esc(p.id) + '">↩ שחזר</button> ' +
              '<button class="gs-btn gs-btn--sm gs-btn--ghost" type="button" data-gsm-action="rpDownload" data-id="' + esc(p.id) + '" aria-label="הורדת נקודת שחזור">⬇️</button> ' +
              '<button class="gs-btn gs-btn--sm gs-btn--danger" type="button" data-gsm-action="rpDelete" data-id="' + esc(p.id) + '" aria-label="מחיקת נקודת שחזור">✕</button>' +
            '</td></tr>';
        }).join('');
        main.innerHTML =
          '<div class="gs-toolbar"><h2 class="gs-h2">נקודות שחזור</h2><div class="gs-toolbar__actions"><button class="gs-btn" id="rp-create">+ צור נקודת שחזור</button></div></div>' +
          '<p style="color:var(--gs-muted);font-size:13px;margin-top:-6px">צילום מלא של הנתונים (ציוד, ביקורות, תקלות, הגדרות). השחזור בטוח: הוא מחזיר רשומות שנמחקו/שונו ואינו מוחק רשומות חדשות שנוצרו אחרי הצילום. לפני כל שחזור נשמר אוטומטית גיבוי של המצב הנוכחי.</p>' +
          (pts.length
            ? '<table class="gs-table"><thead><tr><th>תאריך</th><th>תיאור</th><th>תוכן</th><th>פעולות</th></tr></thead><tbody>' + rows + '</tbody></table>'
            : '<div class="gs-empty">אין נקודות שחזור. לחצו "צור נקודת שחזור" כדי לשמור צילום של המצב הנוכחי.</div>');
        var cb = document.getElementById('rp-create');
        if (cb) {
          cb.addEventListener('click', function () {
            var label = prompt('תיאור לנקודת השחזור (אופציונלי):', '');
            if (label === null) { return; }
            cb.disabled = true; cb.textContent = 'שומר…';
            api('/restore-points', { method: 'POST', body: { label: label } }).then(load).catch(function (e) {
              cb.disabled = false; cb.textContent = '+ צור נקודת שחזור'; alert('שגיאה: ' + (e.message || e));
            });
          });
        }
      }).catch(function (e) { main.innerHTML = '<div class="gs-error" role="alert">' + esc(e.message) + '</div>'; });
    }
    GSM._rpReload = load;
    load();
  });

  // ---- PRIVACY REQUESTS (Amendment 13 / GDPR erasure) ---------------------
  // ---- PULL FROM THE OLD SYSTEM ---------------------------------------------
  GSM.registerView('legacy', function (mount) {
    if (!GSM.can('manage')) { GSM.navigate('/dashboard'); return; }
    mount.innerHTML = chrome('legacy', loading());
    var main = mount.querySelector('.gs-main');
    var SETS = [['equipment', 'ציוד'], ['faults', 'תקלות'], ['inspections', 'ביקורות'], ['audit', 'יומן בקרה (ארכיון)']];
    var checkedOk = false;

    function lastLine(l) {
      if (!l) return 'עדיין לא בוצעה משיכה.';
      var mode = l.mode === 'auto' ? 'אוטומטית' : 'ידנית';
      if (!l.ok) return 'משיכה ' + mode + ' אחרונה (' + l.at + ') <b>נכשלה</b>: ' + esc(l.error);
      var c = l.counts || {}, parts = [];
      ['equipment', 'faults', 'inspections'].forEach(function (k) {
        if (c[k]) parts.push(SETS.filter(function (s) { return s[0] === k; })[0][1] + ': ' + c[k].created + ' חדשים, ' + c[k].updated + ' עודכנו');
      });
      if (c.audit) parts.push('יומן: ' + c.audit['new'] + ' רשומות');
      return 'משיכה ' + mode + ' אחרונה: <bdi dir="ltr">' + esc(l.at) + '</bdi> · ' + esc(parts.join(' · ') || 'אין שינויים');
    }

    function selected() {
      return Array.prototype.slice.call(main.querySelectorAll('.lg-set')).filter(function (c) { return c.checked; }).map(function (c) { return c.value; });
    }

    function report(d) {
      var html = '';
      SETS.forEach(function (s) {
        var r = d.sets && d.sets[s[0]];
        if (!r) return;
        html += '<div class="gs-card" style="margin-top:12px"><h3 class="gs-card__title">' + esc(r.label) + ' — התקבלו ' + esc(r.fetched) + ' רשומות</h3>';
        if (r.audit) {
          var a = r.audit;
          html += '<ul style="margin:0 0 8px;padding-inline-start:18px;font-size:13.5px"><li>' + esc(a['new']) + ' רשומות חדשות ייכנסו לארכיון</li>' +
            (a.duplicates ? '<li>' + esc(a.duplicates) + ' כבר קיימות (ידולגו)</li>' : '') +
            (a.too_old ? '<li>' + esc(a.too_old) + ' ישנות מתקופת השמירה של יומן הבקרה (לא ייובאו)</li>' : '') +
            (a.invalid ? '<li>' + esc(a.invalid) + ' בלי תאריך תקין (לא ייובאו)</li>' : '') + '</ul>';
        } else {
          html += '<ul style="margin:0 0 8px;padding-inline-start:18px;font-size:13.5px">' +
            '<li><b>' + esc(r.would_create) + '</b> חדשות ייווצרו, <b>' + esc(r.would_update) + '</b> קיימות יעודכנו</li>' +
            (r.no_id ? '<li>' + esc(r.no_id) + ' בלי מזהה Monday (ידולגו, כדי שמשיכה חוזרת לא תיצור כפילויות)</li>' : '') +
            (r.unlinked ? '<li style="color:var(--gs-warn-text)">' + esc(r.unlinked) + ' לא מקושרות לציוד מוכר (ידולגו)</li>' : '') + '</ul>';
        }
        if (r.unknown && r.unknown.length) {
          html += '<p style="font-size:13px;margin:6px 0"><span class="gs-pill gs-pill--warn">לא זוהו</span> עמודות שלא ייובאו: ' +
            r.unknown.map(function (u) { return '<bdi>' + esc(u) + '</bdi>'; }).join(', ') + '</p>';
        }
        if (r.columns && r.columns.length) {
          html += '<details style="font-size:13px"><summary>מיפוי העמודות (' + r.columns.length + ')</summary><table class="gs-table" style="margin-top:6px"><thead><tr><th>עמודה באתר הישן</th><th>שדה במערכת</th></tr></thead><tbody>' +
            r.columns.map(function (c) { return '<tr><td><bdi>' + esc(c.column) + '</bdi></td><td>' + esc(c.field) + '</td></tr>'; }).join('') + '</tbody></table></details>';
        }
        if (r.sample && r.sample.length) {
          var keys = [];
          r.sample.forEach(function (row) { Object.keys(row).forEach(function (k) { if (keys.indexOf(k) < 0) keys.push(k); }); });
          keys = keys.slice(0, 8);
          html += '<details style="font-size:13px;margin-top:6px"><summary>דוגמה (שורות: ' + r.sample.length + ')</summary><div style="overflow-x:auto"><table class="gs-table" style="margin-top:6px"><thead><tr>' +
            keys.map(function (k) { return '<th scope="col">' + esc(k) + '</th>'; }).join('') + '</tr></thead><tbody>' +
            r.sample.map(function (row) { return '<tr>' + keys.map(function (k) { return '<td>' + esc(row[k] == null ? '' : row[k]) + '</td>'; }).join('') + '</tr>'; }).join('') +
            '</tbody></table></div></details>';
        }
        html += '</div>';
      });
      return html;
    }

    function pulled(d) {
      var c = d.counts || {}, rows = '';
      ['equipment', 'faults', 'inspections'].forEach(function (k) {
        if (!c[k]) return;
        rows += '<tr><td>' + esc(SETS.filter(function (s) { return s[0] === k; })[0][1]) + '</td><td>' + esc(c[k].created) + '</td><td>' + esc(c[k].updated) + '</td><td>' + esc(c[k].skipped) + '</td><td>' + esc(c[k].failed) + '</td></tr>';
      });
      var notes = [];
      ['equipment', 'faults', 'inspections'].forEach(function (k) { if (c[k] && c[k].notes) notes = notes.concat(c[k].notes); });
      return '<div class="gs-card" style="margin-top:12px" role="status"><h3 class="gs-card__title" id="lg-done" tabindex="-1">✓ המשיכה הסתיימה</h3>' +
        (rows ? '<table class="gs-table"><thead><tr><th>סוג</th><th>נוצרו</th><th>עודכנו</th><th>דולגו</th><th>שגיאות</th></tr></thead><tbody>' + rows + '</tbody></table>' : '') +
        (c.audit ? '<p style="font-size:13.5px">יומן בקרה: נוספו ' + esc(c.audit['new']) + ' רשומות ארכיון.</p>' : '') +
        (notes.length ? '<details style="font-size:13px"><summary>פירוט דילוגים ושגיאות (' + notes.length + ')</summary><ul>' + notes.map(function (n) { return '<li>' + esc(n) + '</li>'; }).join('') + '</ul></details>' : '') +
        '<p style="font-size:13px;color:var(--gs-muted)">לפני המשיכה נוצרה נקודת שחזור #' + esc(d.restore_point) + '. אפשר לחזור אליה במסך <a href="#/restorepts">נקודות שחזור</a>.</p></div>';
    }

    function draw(cfg) {
      main.innerHTML =
        '<div class="gs-toolbar"><h2 class="gs-h2">משיכה מהאתר הישן</h2></div>' +
        '<p style="color:var(--gs-muted);font-size:13px;margin-top:-6px">משיכת ציוד, תקלות, ביקורות ויומן בקרה מהמערכת הקודמת (<bdi dir="ltr">safety-qr-app</bdi>) דרך ממשק ה-BI שלה, לקריאה בלבד. ' +
        'הנתונים מתעדכנים לפי מזהה Monday, כך שמשיכה חוזרת מעדכנת ולא יוצרת כפילויות, והיא לעולם לא מוחקת. מדבקות QR ישנות ממשיכות לעבוד.</p>' +
        '<div class="gs-card"><h3 class="gs-card__title">חיבור לאתר הישן</h3>' +
        '<p style="font-size:13px;margin-top:0" role="status">' + (cfg.configured
          ? 'מחובר: <bdi dir="ltr">' + esc(cfg.base) + '</bdi> · טוקן <bdi dir="ltr">' + esc(cfg.token_masked) + '</bdi>'
          : 'לא מחובר עדיין.') + '</p>' +
        '<div class="gs-field"><label class="gs-label" for="lg-base">כתובת השרת הישן</label>' +
        '<input class="gs-input" id="lg-base" dir="ltr" inputmode="url" autocomplete="off" value="' + esc(cfg.base) + '" style="max-width:420px"></div>' +
        '<div class="gs-field"><label class="gs-label" for="lg-token">טוקן BI של האתר הישן, או קישור BI שלם</label>' +
        '<input class="gs-input" id="lg-token" type="password" dir="ltr" autocomplete="off" spellcheck="false" aria-describedby="lg-token-help" placeholder="' + (cfg.configured ? 'השאירו ריק כדי לא לשנות' : '') + '" style="max-width:420px">' +
        '<div id="lg-token-help" style="color:var(--gs-muted);font-size:12px;margin-top:4px">באתר הישן: לשונית "BI וניתוח", ולחיצה על "העתק קישור" באחד הכרטיסים. אפשר להדביק כאן את הקישור כולו. ' +
        'הטוקן נשמר בשרת בלבד, לא מוצג שוב, ונשלח רק לכתובת שלמעלה. שינוי הכתובת מוחק אותו.</div></div>' +
        '<label style="display:flex;gap:8px;align-items:flex-start;margin:8px 0"><input type="checkbox" id="lg-auto"' + (cfg.auto ? ' checked' : '') + ' aria-describedby="lg-auto-help"><span><b>סנכרון אוטומטי יומי</b>' +
        '<span id="lg-auto-help" style="display:block;color:var(--gs-muted);font-size:12px">כל לילה המערכת מושכת את כל הנתונים מחדש, עם נקודת שחזור לפני כל משיכה. מתאים לתקופה שבה שתי המערכות פועלות במקביל. ' +
        'שימו לב: בשדות שמגיעים מהאתר הישן, הערך הישן גובר על עריכה שנעשתה כאן. כבו את הסנכרון אחרי המעבר הסופי.' + (cfg.next_run ? ' הריצה הבאה: <bdi dir="ltr">' + esc(cfg.next_run) + '</bdi>.' : '') + '</span></span></label>' +
        '<button type="button" class="gs-btn" id="lg-save">שמור</button> ' +
        (cfg.configured ? '<button type="button" class="gs-btn gs-btn--ghost" id="lg-clear">נתק (מחק טוקן)</button> ' : '') +
        '<span id="lg-save-st" role="status" aria-live="polite" style="margin-inline-start:8px;color:var(--gs-muted)"></span></div>' +

        '<div class="gs-card" style="margin-top:16px"><h3 class="gs-card__title">בדיקה ומשיכה</h3>' +
        '<p style="font-size:13px;margin-top:0">' + lastLine(cfg.last) + '</p>' +
        '<fieldset style="border:0;padding:0;margin:0 0 10px"><legend class="gs-label">מה למשוך</legend>' +
        SETS.map(function (s) { return '<label style="display:inline-flex;gap:6px;align-items:center;margin-inline-end:16px"><input type="checkbox" class="lg-set" value="' + s[0] + '" checked><span>' + esc(s[1]) + '</span></label>'; }).join('') +
        '</fieldset>' +
        '<button type="button" class="gs-btn" id="lg-check"' + (cfg.configured ? '' : ' disabled') + '>בדוק (בלי לשנות דבר)</button> ' +
        '<button type="button" class="gs-btn gs-btn--danger" id="lg-pull" disabled aria-describedby="lg-pull-help">משוך עכשיו</button> ' +
        '<span id="lg-st" role="status" aria-live="polite" style="margin-inline-start:8px;color:var(--gs-muted)"></span>' +
        '<div id="lg-pull-help" style="color:var(--gs-muted);font-size:12px;margin-top:6px">"משוך עכשיו" נפתח אחרי בדיקה שהצליחה. המשיכה יוצרת קודם נקודת שחזור.</div>' +
        '<div id="lg-result"></div></div>';

      function save(body, btn) {
        var st = document.getElementById('lg-save-st'); btn.disabled = true; st.textContent = 'שומר…';
        api('/legacy', { method: 'POST', body: body })
          .then(function (nc) { draw(nc); document.getElementById('lg-save-st').textContent = '✓ נשמר.'; })
          .catch(function (e) { st.textContent = '✗ ' + (e.message || e); btn.disabled = false; });
      }
      document.getElementById('lg-save').addEventListener('click', function () {
        var body = { base: document.getElementById('lg-base').value.trim(), auto: document.getElementById('lg-auto').checked };
        var tk = document.getElementById('lg-token').value.trim();
        if (tk) body.token = tk;
        save(body, this);
      });
      var clr = document.getElementById('lg-clear');
      if (clr) clr.addEventListener('click', function () {
        if (!confirm('לנתק את החיבור לאתר הישן? הטוקן יימחק והסנכרון האוטומטי ייעצר.')) return;
        save({ clear_token: true, auto: false }, this);
      });
      function setsChanged() { checkedOk = false; document.getElementById('lg-pull').disabled = true; }
      main.querySelectorAll('.lg-set').forEach(function (c) { c.addEventListener('change', setsChanged); });

      document.getElementById('lg-check').addEventListener('click', function () {
        var btn = this, st = document.getElementById('lg-st'), sets = selected();
        if (!sets.length) { st.textContent = 'בחרו לפחות סוג נתונים אחד.'; return; }
        btn.disabled = true; st.textContent = 'בודק מול האתר הישן… (עד דקה)';
        api('/legacy/check', { method: 'POST', body: { sets: sets } }).then(function (d) {
          btn.disabled = false; checkedOk = true;
          document.getElementById('lg-pull').disabled = false;
          st.textContent = '✓ הבדיקה הסתיימה. שום דבר לא השתנה.';
          document.getElementById('lg-result').innerHTML = report(d);
        }).catch(function (e) {
          btn.disabled = false; st.textContent = '';
          document.getElementById('lg-result').innerHTML = '<div class="gs-error" role="alert" style="margin-top:12px">' + esc(e.message || e) + '</div>';
        });
      });
      document.getElementById('lg-pull').addEventListener('click', function () {
        if (!checkedOk) return;
        if (!confirm('למשוך עכשיו את הנתונים מהאתר הישן? רשומות קיימות יעודכנו לפי האתר הישן. לפני כן תיווצר נקודת שחזור.')) return;
        var btn = this, st = document.getElementById('lg-st'); btn.disabled = true; st.textContent = 'מושך… (עשוי לקחת כמה דקות)';
        document.getElementById('lg-check').disabled = true;
        api('/legacy/pull', { method: 'POST', body: { sets: selected() } }).then(function (d) {
          st.textContent = '';
          return api('/legacy').then(function (nc) {
            draw(nc);
            document.getElementById('lg-result').innerHTML = pulled(d);
            document.getElementById('lg-done').focus();
          });
        }).catch(function (e) {
          st.textContent = ''; document.getElementById('lg-check').disabled = false;
          document.getElementById('lg-result').innerHTML = '<div class="gs-error" role="alert" style="margin-top:12px">' + esc(e.message || e) + '</div>';
        });
      });
    }

    api('/legacy').then(draw).catch(function (e) { main.innerHTML = '<div class="gs-error" role="alert">' + esc(e.message || e) + '</div>'; });
  });

  GSM.registerView('privacy', function (mount) {
    if (!GSM.can('manage')) { GSM.navigate('/dashboard'); return; }
    mount.innerHTML = chrome('privacy', loading());
    var main = mount.querySelector('.gs-main');
    var STATUS = { open: ['פתוחה', 'warn'], done: ['טופלה', 'ok'], rejected: ['נדחתה', 'danger'] };

    function load() {
      api('/privacy-requests').then(function (rows) {
        rows = rows || [];
        var body = rows.map(function (r) {
          var st = STATUS[r.status] || [r.status, 'warn'];
          return '<tr><td style="white-space:nowrap"><bdi dir="ltr">' + esc(r.created_at) + '</bdi></td><td><bdi dir="ltr">' + esc(r.email) + '</bdi></td>' +
            '<td>' + (r.user_gone ? '<span style="color:var(--gs-muted)">(החשבון נמחק)</span>' : esc(r.user_name)) + '</td>' +
            '<td>' + esc(r.reason || '—') + '</td>' +
            '<td><span class="gs-pill gs-pill--' + st[1] + '">' + esc(st[0]) + '</span></td>' +
            '<td>' + (r.status === 'open' ? '<button type="button" class="gs-btn gs-btn--sm" data-pr="' + esc(r.id) + '">טפל</button>' : '') + '</td></tr>';
        }).join('');
        main.innerHTML =
          '<div class="gs-toolbar"><h2 class="gs-h2">בקשות פרטיות</h2></div>' +
          '<p style="color:var(--gs-muted);font-size:13px;margin-top:-6px">בקשות למחיקת מידע אישי (חוק הגנת הפרטיות, תיקון 13). ' +
          'בטיפול בבקשה מאתרים כל הופעה של השם והאימייל במערכת ומחליפים אותה בכינוי "משתמש נמחק #N". הרשומות המקצועיות (ביקורות, תקלות) נשמרות, בלי הפרטים המזהים.</p>' +
          (rows.length
            ? '<table class="gs-table"><thead><tr><th>תאריך</th><th>אימייל</th><th>משתמש</th><th>סיבה</th><th>סטטוס</th><th></th></tr></thead><tbody>' + body + '</tbody></table>'
            : '<div class="gs-empty">אין בקשות.</div>') +
          '<div id="pr-panel"></div>';
        main.querySelectorAll('[data-pr]').forEach(function (b) {
          b.addEventListener('click', function () {
            var id = parseInt(b.getAttribute('data-pr'), 10);
            var r = rows.filter(function (x) { return x.id === id; })[0];
            if (r) panel(r);
          });
        });
      }).catch(function (e) { main.innerHTML = '<div class="gs-error" role="alert">' + esc(e.message) + '</div>'; });
    }

    function panel(r) {
      var host = document.getElementById('pr-panel');
      host.innerHTML =
        '<div class="gs-card" style="margin-top:16px"><h3 class="gs-card__title" id="pr-title" tabindex="-1">טיפול בבקשה #' + esc(r.id) + '</h3>' +
        '<div class="gs-field"><label class="gs-label" for="pr-ids">מזהים לחיפוש (שורה לכל אחד: שם מלא, אימייל, טלפון, איות נוסף)</label>' +
        '<textarea class="gs-textarea" id="pr-ids" rows="4" aria-describedby="pr-ids-help">' + esc((r.identifiers || []).join('\n')) + '</textarea>' +
        '<div id="pr-ids-help" style="color:var(--gs-muted);font-size:12px;margin-top:4px">ההתאמה היא למילים שלמות בלבד ("דנה לוי" לא יתפוס את "דנה לויה"). מזהה צריך להכיל 3 תווים לפחות.</div></div>' +
        '<button type="button" class="gs-btn" id="pr-scan">סרוק</button> <span id="pr-status" role="status" aria-live="polite" style="margin-inline-start:8px;color:var(--gs-muted)"></span>' +
        '<div id="pr-result" style="margin-top:12px"></div></div>';
      document.getElementById('pr-title').focus();
      function ids() { return document.getElementById('pr-ids').value.split('\n').map(function (s) { return s.trim(); }).filter(Boolean); }
      document.getElementById('pr-scan').addEventListener('click', function () {
        var btn = this, st = document.getElementById('pr-status'); btn.disabled = true; st.textContent = 'סורק…';
        api('/privacy-requests/' + r.id + '/scan', { method: 'POST', body: { identifiers: ids() } }).then(function (res) {
          btn.disabled = false; st.textContent = '✓ נמצאו ' + res.total_matches + ' הופעות ב-' + res.total_rows + ' רשומות';
          var rows = (res.locations || []).map(function (l) {
            return '<tr><td>' + esc(l.label) + '</td><td style="text-align:center">' + esc(l.rows) + '</td><td style="text-align:center">' + esc(l.matches) + '</td></tr>';
          }).join('');
          document.getElementById('pr-result').innerHTML =
            (rows ? '<table class="gs-table"><thead><tr><th>מיקום</th><th>רשומות</th><th>הופעות</th></tr></thead><tbody>' + rows + '</tbody></table>'
                  : '<div class="gs-empty">לא נמצאו הופעות של המזהים.</div>') +
            (r.user_gone ? '' : '<label style="display:flex;gap:8px;align-items:center;margin:12px 0"><input type="checkbox" id="pr-del-account"><span>מחק גם את חשבון המשתמש ב-WordPress (משתמשי מערכת הגזים בלבד)</span></label>') +
            '<div style="margin-top:10px"><button type="button" class="gs-btn gs-btn--danger" id="pr-apply">החלף בכינוי וסגור את הבקשה</button> ' +
            '<button type="button" class="gs-btn gs-btn--ghost" id="pr-reject">דחה את הבקשה</button></div>' +
            '<p style="color:var(--gs-muted);font-size:12px">הפעולה בלתי הפיכה. היא כוללת גם את נקודות השחזור ואת העותקים ביומן הבקרה. מומלץ לבדוק מול ממונה הפרטיות אילו רשומות חובה לשמור.</p>';
          document.getElementById('pr-apply').addEventListener('click', function () {
            if (!confirm('להחליף ' + res.total_matches + ' הופעות בכינוי "משתמש נמחק #' + r.id + '"? הפעולה בלתי הפיכה.')) return;
            var b2 = this; b2.disabled = true; st.textContent = 'מחליף…';
            var del = document.getElementById('pr-del-account');
            api('/privacy-requests/' + r.id + '/anonymize', { method: 'POST', body: { identifiers: ids(), delete_account: !!(del && del.checked) } })
              .then(function (out) {
                load();
                setTimeout(function () {
                  var s2 = document.querySelector('.gs-main p');
                  if (s2) s2.insertAdjacentHTML('afterend', '<div class="gs-card" role="status" style="margin-bottom:12px">✓ בקשה #' + esc(r.id) + ' טופלה: הוחלפו ' + esc(out.total_matches) + ' הופעות ב-' + esc(out.total_rows) + ' רשומות' + (out.account_deleted ? ', וחשבון המשתמש נמחק' : '') + '.</div>');
                }, 400);
              })
              .catch(function (e) { st.textContent = '✗ ' + (e.message || e); b2.disabled = false; });
          });
          document.getElementById('pr-reject').addEventListener('click', function () {
            if (!confirm('לדחות את בקשה #' + r.id + '? לא יימחק דבר.')) return;
            api('/privacy-requests/' + r.id + '/reject', { method: 'POST' }).then(load).catch(function (e) { st.textContent = '✗ ' + (e.message || e); });
          });
        }).catch(function (e) { btn.disabled = false; st.textContent = '✗ ' + (e.message || e); });
      });
    }
    load();
  });

  GSM.registerView('audit', function (mount) {
    if (!GSM.can('manage')) { GSM.navigate('/dashboard'); return; }
    mount.innerHTML = chrome('audit', loading());
    var main = mount.querySelector('.gs-main');
    api('/audit').then(function (rows) {
      rows = rows || [];
      var actCls = { create: 'ok', update: 'warn', delete: 'danger', import: 'ok', undo: 'warn' };
      var body = rows.map(function (r) {
        var cls = actCls[r.action] || 'ok';
        var undo = r.undoable
          ? '<button class="gs-btn gs-btn--sm" type="button" data-gsm-action="auditUndo" data-id="' + esc(r.id) + '">↩ שחזר</button>'
          : (r.undone ? '<span class="gs-pill gs-pill--ok">שוחזר</span>' : '');
        return '<tr><td>' + esc(r.created_at) + '</td><td>' + esc(r.actor) + '</td>' +
          '<td><span class="gs-pill gs-pill--' + cls + '">' + esc(r.action_label) + '</span></td>' +
          '<td>' + esc(r.entity_label) + '</td><td>' + esc(r.item_name || '') + '</td><td>' + esc(r.summary || '') + '</td>' +
          '<td>' + undo + '</td></tr>';
      }).join('');
      main.innerHTML =
        '<div class="gs-toolbar"><h2 class="gs-h2">יומן בקרה</h2><div class="gs-toolbar__actions" style="color:var(--gs-muted)">' + rows.length + ' רשומות אחרונות</div></div>' +
        '<table class="gs-table"><thead><tr><th>תאריך</th><th>מבצע</th><th>פעולה</th><th>ישות</th><th>פריט</th><th>פירוט</th><th></th></tr></thead>' +
        '<tbody>' + (body || '<tr><td colspan="7"><div class="gs-empty">אין רשומות עדיין</div></td></tr>') + '</tbody></table>';
    }).catch(function (e) { main.innerHTML = '<div class="gs-error" role="alert">' + esc(e.message) + '</div>'; });
  });

  // ---- HELP ---------------------------------------------------------------

  GSM.registerView('help', function (mount) {
    mount.innerHTML = chrome('help', '');
    var main = mount.querySelector('.gs-main');
    var topics = [
      ['זרימת עבודה — ביקורות', '<b>אחמ"ת</b> מבצע <b>ביקורת רבעונית</b> (צ׳קליסט ארוך) — מאפסת את דד-ליין 90 הימים. <b>מנהל מעבדה / חוקר ראשי</b> מבצע <b>ביקורת חודשית</b> (צ׳קליסט קצר). סוג הביקורת והצ׳קליסט נטענים אוטומטית לפי התפקיד.'],
      ['דשבורד', 'מציג סיכום: כמות ציוד, ביקורות, ליקויים ותקלות פתוחות, ודד-ליין ביקורות רבעוניות. "הנפקת דוחות" מאפשר ייצוא Excel.'],
      ['ניהול ציוד', 'הוספה/עריכה של ציוד עם כל השדות. כל ציוד מקבל מספר (מ-Monday אם הוגַר, או G-#### חדש) וקוד QR.'],
      ['QR ודיווח תקלות', 'כל ציוד מקבל מדבקת QR. סריקה פותחת את דף הפרטים — וכל אחד יכול לדווח תקלה (גם בלי התחברות). "מדבקות QR" מפיק גיליון מדבקות להדפסה.'],
      ['הגדרות ביקורת', 'עריכת שני הצ׳קליסטים (רבעוני/חודשי) וכללי תזכורת. התוכן שנערך כאן נטען אוטומטית בטופס הביקורת לפי התפקיד.'],
      ['טיוב מידע', 'סריקה של חוסרים בציוד (ללא חוקר ראשי / פאנל / סוג גז / מיקום / תאריך ביקורת) עם קישור מהיר לעריכה.'],
      ['יומן בקרה', 'תיעוד כל יצירה/עדכון/מחיקה של ציוד/תקלות/ביקורות — מי, מתי ומה.'],
      ['ניהול אנשי אחמ"ת', 'יצירת משתמשים, שיוך תפקיד (מנהל/מנהל מעבדה/חוקר ראשי/אחמ"ת), הפעלה/השבתה ואיפוס סיסמה.'],
      ['הגדרות אתר', 'ספי איחור, הרשאות לפי תפקיד (מה כל תפקיד רואה) והצגת/הסתרת פריטי תפריט.'],
      ['עיצוב עמוד סריקה', 'בחירה אילו שדות/כפתורים מוצגים בדף ה-QR הציבורי, וצבעים.'],
      ['דוחות וגיבוי', 'ייצוא Excel ב"הנפקת דוחות". גיבוי/ייבוא נתונים נמצאים בלוח הבקרה של WordPress → "Gas Migration".']
    ];
    var acc = topics.map(function (t) {
      return '<details class="gs-acc"><summary>' + esc(t[0]) + '</summary><div class="gs-acc__body">' + t[1] + '</div></details>';
    }).join('');
    main.innerHTML =
      '<div class="gs-toolbar"><h2 class="gs-h2">עזרה ומדריך</h2></div>' +
      '<div style="max-width:760px">' + acc +
      '<p style="color:var(--gs-muted);margin-top:16px">לשאלות ותקלות במערכת — פנה למנהל המערכת (יחידת הבטיחות).</p></div>';
  });
})();
