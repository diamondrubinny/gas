/**
 * Gas Safety Manager — forms module.
 *
 * Modal-based create/edit forms for equipment, faults, and inspections.
 * Exposes window.GSM_Forms with open* methods that app.js calls. Talks to
 * the same REST API via the shared api() helper (re-declared minimally here
 * to stay independent).
 */
(function () {
  'use strict';

  var CFG = window.GSM_CONFIG || {};
  if (!CFG.restRoot) return;

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

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  // ---- modal shell ---------------------------------------------------------

  function openModal(title, bodyHtml, onSave) {
    closeModal();
    var backdrop = document.createElement('div');
    backdrop.className = 'gs-modal-backdrop';
    backdrop.id = 'gsm-modal';
    backdrop.innerHTML =
      '<div class="gs-modal">' +
      '<div class="gs-modal__title">' + esc(title) + '</div>' +
      '<div id="gsm-modal-body">' + bodyHtml + '</div>' +
      '<div id="gsm-modal-err"></div>' +
      '<div class="gs-modal__actions">' +
      '<button class="gs-btn" id="gsm-modal-save">שמור</button>' +
      '<button class="gs-btn gs-btn--ghost" id="gsm-modal-cancel">ביטול</button>' +
      '</div></div>';
    document.body.appendChild(backdrop);

    backdrop.addEventListener('click', function (e) {
      if (e.target === backdrop) closeModal();
    });
    document.getElementById('gsm-modal-cancel').addEventListener('click', closeModal);
    document.getElementById('gsm-modal-save').addEventListener('click', function () {
      var btn = this;
      btn.disabled = true;
      Promise.resolve(onSave()).then(function (ok) {
        if (ok !== false) closeModal();
      }).catch(function (e) {
        showModalErr(e.message);
        btn.disabled = false;
      });
    });
  }

  function closeModal() {
    var m = document.getElementById('gsm-modal');
    if (m) m.remove();
  }

  function showModalErr(msg) {
    var e = document.getElementById('gsm-modal-err');
    if (e) e.innerHTML = '<div class="gs-error" style="margin-top:12px">' + esc(msg) + '</div>';
  }

  function val(id) {
    var f = document.getElementById(id);
    return f ? f.value.trim() : '';
  }

  // ---- field helpers -------------------------------------------------------

  function field(label, id, value, type) {
    return '<div class="gs-field"><label class="gs-label">' + esc(label) + '</label>' +
      '<input class="gs-input" id="' + id + '" type="' + (type || 'text') + '" value="' + esc(value || '') + '"></div>';
  }

  function textarea(label, id, value) {
    return '<div class="gs-field"><label class="gs-label">' + esc(label) + '</label>' +
      '<textarea class="gs-textarea" id="' + id + '" rows="3">' + esc(value || '') + '</textarea></div>';
  }

  function select(label, id, value, options) {
    var opts = options.map(function (o) {
      return '<option value="' + esc(o[0]) + '"' + (o[0] === value ? ' selected' : '') + '>' + esc(o[1]) + '</option>';
    }).join('');
    return '<div class="gs-field"><label class="gs-label">' + esc(label) + '</label>' +
      '<select class="gs-select" id="' + id + '">' + opts + '</select></div>';
  }

  // ---- attachments (secure upload / list / delete) -------------------------
  // Files are validated + stored server-side (private dir, MIME/size/count
  // limits, optional AV scan). The client only lists, uploads, and deletes.

  function attUpload(entity, entityId, file) {
    var fd = new FormData();
    fd.append('file', file);
    fd.append('entity', entity);
    fd.append('entity_id', entityId);
    var headers = {};
    if (CFG.nonce) headers['X-WP-Nonce'] = CFG.nonce;
    return fetch(CFG.restRoot + '/attachments', {
      method: 'POST', headers: headers, body: fd, credentials: 'same-origin'
    }).then(function (r) {
      return r.json().then(function (j) {
        if (!r.ok) throw new Error((j && j.message) || ('HTTP ' + r.status));
        return j;
      });
    });
  }

  function attFileUrl(id) {
    var base = CFG.restRoot + '/attachments/' + id;
    return base + (base.indexOf('?') > -1 ? '&' : '?') + '_wpnonce=' + encodeURIComponent(CFG.nonce || '');
  }

  function attRenderList(entity, entityId, host) {
    var listEl = host.querySelector('.gsm-att-list');
    if (!listEl) return;
    listEl.innerHTML = '<div style="color:var(--gs-muted);font-size:13px;padding:6px 0">טוען…</div>';
    api('/attachments/list/' + entity + '/' + entityId).then(function (rows) {
      rows = rows || [];
      if (!rows.length) {
        listEl.innerHTML = '<div style="color:var(--gs-muted);font-size:13px;padding:6px 0">אין צרופות עדיין</div>';
        return;
      }
      listEl.innerHTML = rows.map(function (a) {
        var isImg = a.mime && a.mime.indexOf('image/') === 0;
        var src = attFileUrl(a.id);
        var thumb = isImg
          ? '<a href="' + src + '" target="_blank" rel="noopener"><img src="' + src + '" alt="" style="width:48px;height:48px;object-fit:cover;border-radius:8px;border:1px solid var(--gs-border)"></a>'
          : '<a href="' + src + '" target="_blank" rel="noopener" style="font-size:22px;text-decoration:none" title="הורדה">📄</a>';
        return '<div style="display:flex;align-items:center;gap:10px;padding:6px 0;border-bottom:1px solid var(--gs-border)">' +
          thumb +
          '<span style="flex:1;font-size:13px;word-break:break-all">' + esc(a.orig_name) +
          ' <span style="color:var(--gs-muted)">(' + Math.round((a.size || 0) / 1024) + 'KB)</span></span>' +
          '<button type="button" class="gs-btn gs-btn--ghost gs-btn--sm" data-attdel="' + a.id + '">מחק</button>' +
          '</div>';
      }).join('');
      listEl.querySelectorAll('[data-attdel]').forEach(function (b) {
        b.addEventListener('click', function () {
          if (!window.confirm('למחוק את הצרופה?')) return;
          api('/attachments/' + b.getAttribute('data-attdel'), { method: 'DELETE' })
            .then(function () { attRenderList(entity, entityId, host); })
            .catch(function (e) { window.alert('מחיקה נכשלה: ' + (e.message || e)); });
        });
      });
    }).catch(function (e) {
      listEl.innerHTML = '<div class="gs-error">' + esc(e.message || e) + '</div>';
    });
  }

  function attachBlock(entity, entityId, host) {
    if (!host) return;
    host.innerHTML =
      '<div class="gsm-att-list" style="margin-bottom:8px"></div>' +
      '<label class="gs-btn gs-btn--ghost gs-btn--sm" style="cursor:pointer;display:inline-block">+ העלה קובץ' +
      '<input type="file" class="gsm-att-input" accept=".jpg,.jpeg,.png,.webp,.pdf,image/jpeg,image/png,image/webp,application/pdf" style="display:none"></label>' +
      '<span class="gsm-att-status" style="margin-inline-start:10px;font-size:12px;color:var(--gs-muted)"></span>' +
      '<div style="font-size:11px;color:var(--gs-muted);margin-top:4px">JPG · PNG · WEBP · PDF · עד 8MB · עד 10 קבצים</div>';
    var input = host.querySelector('.gsm-att-input');
    var status = host.querySelector('.gsm-att-status');
    input.addEventListener('change', function () {
      if (!input.files || !input.files[0]) return;
      status.textContent = 'מעלה…';
      attUpload(entity, entityId, input.files[0]).then(function () {
        status.textContent = '✓ הועלה';
        input.value = '';
        attRenderList(entity, entityId, host);
        setTimeout(function () { status.textContent = ''; }, 2500);
      }).catch(function (e) {
        status.textContent = '✗ ' + (e.message || e);
        input.value = '';
      });
    });
    attRenderList(entity, entityId, host);
  }

  // Expose for app-views and other callers.
  window.GSM = window.GSM || {};
  window.GSM.attachBlock = attachBlock;

  // ---- equipment form ------------------------------------------------------

  function openEquipment(existing, onDone) {
    existing = existing || {};
    var isEdit = !!existing.id;
    var codeInfo = (isEdit && existing.asset_code)
      ? '<div class="gs-field"><label class="gs-label">מספר ציוד</label>' +
        '<input class="gs-input" value="' + esc(existing.asset_code) + '" readonly style="background:#f1f4f8;font-weight:600"></div>'
      : (!isEdit
          ? '<p style="color:var(--gs-muted);font-size:13px;margin:0 0 10px">מספר ציוד יוקצה אוטומטית — מספר חדש (G-####). מספרי Monday הקיימים נשמרים בהגירה.</p>'
          : '');
    function section(t) { return '<div class="gs-form-section">' + esc(t) + '</div>'; }
    var body =
      codeInfo +
      section('זיהוי') +
      field('שם הציוד *', 'eq-name', existing.name) +
      field('סוג הגז', 'eq-gas_type', existing.gas_type) +
      field('קטגוריה', 'eq-category', existing.category) +
      select('סטטוס', 'eq-status', existing.status || 'active', [
        ['active', 'פעיל'], ['maintenance', 'בתחזוקה'], ['faulty', 'תקול'], ['inactive', 'לא פעיל']
      ]) +
      section('מיקום') +
      field('פקולטה / יחידה', 'eq-faculty', existing.faculty) +
      field('בניין', 'eq-building', existing.building) +
      field('קומה', 'eq-floor', existing.floor) +
      field('מס\' חדר', 'eq-room', existing.room) +
      field('מיקום', 'eq-location', existing.location) +
      field('מיקום פונקציונלי', 'eq-functional_loc', existing.functional_loc) +
      field('GIS', 'eq-gis', existing.gis) +
      section('טכני') +
      field('מספר פאנל', 'eq-panel_number', existing.panel_number) +
      field('לחץ עבודה', 'eq-work_pressure', existing.work_pressure) +
      field('מספר צילינדרים', 'eq-cylinders_count', existing.cylinders_count) +
      field('מלאי צילינדרים', 'eq-cylinders_stock', existing.cylinders_stock) +
      section('אנשים') +
      field('חוקר ראשי', 'eq-pi_name', existing.pi_name) +
      textarea('מנהלי מעבדה', 'eq-lab_managers', existing.lab_managers) +
      textarea('אנשי תחזוקה', 'eq-maint_staff', existing.maint_staff) +
      field('טלפון', 'eq-phone', existing.phone) +
      textarea('אנשי קשר', 'eq-contacts', existing.contacts) +
      section('ביקורת') +
      field('ביקורת רבעונית אחרונה', 'eq-last_quarterly', existing.last_quarterly, 'date') +
      field('ביקורת רבעונית הבאה', 'eq-next_quarterly', existing.next_quarterly, 'date') +
      textarea('הערות', 'eq-notes', existing.notes);

    openModal(isEdit ? 'עריכת ציוד' : 'הוספת ציוד', body, function () {
      var data = {
        name: val('eq-name'), gas_type: val('eq-gas_type'), category: val('eq-category'),
        status: val('eq-status'),
        faculty: val('eq-faculty'), building: val('eq-building'), floor: val('eq-floor'),
        room: val('eq-room'), location: val('eq-location'), functional_loc: val('eq-functional_loc'),
        gis: val('eq-gis'),
        panel_number: val('eq-panel_number'), work_pressure: val('eq-work_pressure'),
        cylinders_count: val('eq-cylinders_count'), cylinders_stock: val('eq-cylinders_stock'),
        pi_name: val('eq-pi_name'), lab_managers: val('eq-lab_managers'), maint_staff: val('eq-maint_staff'),
        phone: val('eq-phone'), contacts: val('eq-contacts'),
        last_quarterly: val('eq-last_quarterly'), next_quarterly: val('eq-next_quarterly'),
        notes: val('eq-notes')
      };
      if (!data.name) { showModalErr('שם הציוד חובה'); return false; }
      var p = isEdit
        ? api('/equipment/' + existing.id, { method: 'PUT', body: data })
        : api('/equipment', { method: 'POST', body: data });
      return p.then(function () { if (onDone) onDone(); });
    });
  }

  // ---- fault form ----------------------------------------------------------

  function openFault(existing, onDone) {
    existing = existing || {};
    var isEdit = !!existing.id;
    var body =
      field('מזהה ציוד', 'ft-equipment_id', existing.equipment_id, 'number') +
      textarea('תיאור התקלה *', 'ft-description', existing.description) +
      field('מדווח', 'ft-reporter', existing.reporter) +
      select('עדיפות', 'ft-priority', existing.priority || 'normal', [
        ['low', 'נמוך'], ['normal', 'רגיל'], ['high', 'גבוה'], ['urgent', 'דחוף']
      ]) +
      select('סטטוס', 'ft-status', existing.status || 'open', [
        ['open', 'פתוח'], ['in_progress', 'בטיפול'], ['resolved', 'טופל'], ['closed', 'סגור']
      ]) +
      (isEdit ? '<div class="gs-label" style="margin-top:12px">📎 צרופות (תמונות / PDF)</div><div id="ft-att"></div>' : '');

    openModal(isEdit ? 'עריכת תקלה' : 'דיווח תקלה', body, function () {
      var data = {
        equipment_id: parseInt(val('ft-equipment_id'), 10) || 0,
        description: val('ft-description'), reporter: val('ft-reporter'),
        priority: val('ft-priority'), status: val('ft-status')
      };
      if (!data.description) { showModalErr('תיאור התקלה חובה'); return false; }
      var p = isEdit
        ? api('/faults/' + existing.id, { method: 'PUT', body: data })
        : api('/faults', { method: 'POST', body: data });
      return p.then(function () { if (onDone) onDone(); });
    });

    if (isEdit) { attachBlock('fault', existing.id, document.getElementById('ft-att')); }
  }

  // ---- inspection form (with checklist) ------------------------------------

  var TYPE_LABELS = { quarterly: 'רבעונית', monthly: 'חודשית', adhoc: 'אד-הוק' };

  function openInspection(existing, onDone) {
    existing = existing || {};
    // Load the inspection config first so we can prefill the role's checklist.
    api('/inspection-config').then(function (cfg) {
      renderInspectionForm(existing, onDone, cfg || {});
    }).catch(function () {
      renderInspectionForm(existing, onDone, {});
    });
  }

  function renderInspectionForm(existing, onDone, cfg) {
    var isEdit = !!existing.id;
    var checklists = (cfg && cfg.checklists) || {};
    var user = (window.GSM && GSM.session && GSM.session.user) || {};
    // Types this user may file (server enforces too). Manager → all.
    var allowed = (user.inspectTypes && user.inspectTypes.length) ? user.inspectTypes : ['quarterly', 'monthly', 'adhoc'];

    var curType = existing.type || allowed[0] || 'adhoc';
    if (allowed.indexOf(curType) === -1) curType = allowed[0] || 'adhoc';

    var typeOpts = allowed.map(function (t) { return [t, TYPE_LABELS[t] || t]; });

    function configItems(type) {
      return (checklists[type] || []).map(function (c) {
        return { item_text: (c && c.text) ? c.text : (typeof c === 'string' ? c : ''), item_result: 'ok' };
      }).filter(function (x) { return x.item_text; });
    }
    var items = (isEdit && existing.items && existing.items.length) ? existing.items : configItems(curType);

    var checklistHtml = '<div id="gsm-checklist">' +
      items.map(function (it, i) { return checklistRow(i, it); }).join('') +
      '</div>' +
      '<button class="gs-btn gs-btn--ghost gs-btn--sm" id="gsm-add-item" type="button">+ הוסף סעיף</button>';

    var typeField = (typeOpts.length > 1)
      ? select('סוג ביקורת', 'in-type', curType, typeOpts)
      : '<div class="gs-field"><label class="gs-label">סוג ביקורת</label>' +
        '<input class="gs-input" value="' + esc(TYPE_LABELS[curType] || curType) + '" readonly style="background:#f1f4f8">' +
        '<input type="hidden" id="in-type" value="' + esc(curType) + '"></div>';

    var body =
      field('מזהה ציוד *', 'in-equipment_id', existing.equipment_id, 'number') +
      typeField +
      field('תאריך', 'in-inspection_date', existing.inspection_date, 'date') +
      field('מבצע', 'in-inspector', existing.inspector) +
      field('חוקר ראשי', 'in-pi_name', existing.pi_name) +
      '<div class="gs-label" style="margin-top:12px" id="gsm-checklist-label">צ׳קליסט (' + esc(TYPE_LABELS[curType] || curType) + ')</div>' +
      checklistHtml +
      textarea('הערות', 'in-notes', existing.notes) +
      (isEdit ? '<div class="gs-label" style="margin-top:12px">📎 צרופות (תמונות / PDF)</div><div id="in-att"></div>' : '');

    openModal(isEdit ? 'עריכת ביקורת' : 'ביקורת חדשה', body, function () {
      var data = {
        equipment_id: parseInt(val('in-equipment_id'), 10) || 0,
        type: val('in-type'), inspection_date: val('in-inspection_date'),
        inspector: val('in-inspector'), pi_name: val('in-pi_name'),
        notes: val('in-notes'), items: collectChecklist()
      };
      if (!data.equipment_id) { showModalErr('מזהה ציוד חובה'); return false; }
      var p = isEdit
        ? api('/inspections/' + existing.id, { method: 'PUT', body: data })
        : api('/inspections', { method: 'POST', body: data });
      return p.then(function () { if (onDone) onDone(); });
    });

    setTimeout(function () {
      var addBtn = document.getElementById('gsm-add-item');
      if (addBtn) {
        addBtn.addEventListener('click', function () {
          var list = document.getElementById('gsm-checklist');
          var idx = list.children.length;
          var div = document.createElement('div');
          div.innerHTML = checklistRow(idx, {});
          list.appendChild(div.firstChild);
        });
      }
      var typeSel = document.getElementById('in-type');
      if (typeSel && typeSel.tagName === 'SELECT' && !isEdit) {
        typeSel.addEventListener('change', function () {
          var list = document.getElementById('gsm-checklist');
          if (list) {
            var ci = configItems(this.value);
            list.innerHTML = ci.map(function (it, i) { return checklistRow(i, it); }).join('');
          }
          var lbl = document.getElementById('gsm-checklist-label');
          if (lbl) lbl.textContent = 'צ׳קליסט (' + (TYPE_LABELS[this.value] || this.value) + ')';
        });
      }
      if (isEdit) { attachBlock('inspection', existing.id, document.getElementById('in-att')); }
    }, 0);
  }

  function checklistRow(idx, item) {
    return '<div class="gs-checklist-row" data-idx="' + idx + '" style="display:flex;gap:8px;margin-bottom:6px;align-items:center">' +
      '<input class="gs-input gsm-ci-text" placeholder="תיאור סעיף" value="' + esc(item.item_text || '') + '" style="flex:1">' +
      '<select class="gs-select gsm-ci-result" style="max-width:120px">' +
      '<option value="ok"' + (item.item_result === 'ok' ? ' selected' : '') + '>תקין</option>' +
      '<option value="defect"' + (item.item_result === 'defect' ? ' selected' : '') + '>ליקוי</option>' +
      '<option value="na"' + (item.item_result === 'na' ? ' selected' : '') + '>לא רלוונטי</option>' +
      '</select></div>';
  }

  function collectChecklist() {
    var rows = document.querySelectorAll('.gs-checklist-row');
    var items = [];
    rows.forEach(function (row, i) {
      var text = row.querySelector('.gsm-ci-text').value.trim();
      if (!text) return;
      items.push({
        item_index: i + 1,
        item_text: text,
        item_result: row.querySelector('.gsm-ci-result').value
      });
    });
    return items;
  }

  // ---- delete confirm ------------------------------------------------------

  function confirmDelete(label, doDelete, onDone) {
    openModal('אישור מחיקה',
      '<p>למחוק את ' + esc(label) + '? פעולה זו אינה הפיכה.</p>',
      function () {
        return doDelete().then(function () { if (onDone) onDone(); });
      });
    // Recolor save button to danger.
    setTimeout(function () {
      var b = document.getElementById('gsm-modal-save');
      if (b) { b.className = 'gs-btn gs-btn--danger'; b.textContent = 'מחק'; }
    }, 0);
  }

  // ---- export --------------------------------------------------------------

  // ---- user form -----------------------------------------------------------

  function openUser(existing, onDone) {
    existing = existing || {};
    var isEdit = !!existing.id;
    var roleOpts = [['manager', 'מנהל'], ['lab_manager', 'מנהל מעבדה'], ['pi', 'חוקר ראשי'], ['ahmat', 'אחמ"ת']];
    var body =
      field('שם מלא *', 'u-name', existing.name) +
      (isEdit
        ? '<div class="gs-field"><label class="gs-label">אימייל</label><input class="gs-input" value="' + esc(existing.email) + '" readonly style="background:#f1f4f8"></div>'
        : field('אימייל *', 'u-email', existing.email, 'email')) +
      select('תפקיד', 'u-role', existing.role_key || 'ahmat', roleOpts) +
      (isEdit ? select('סטטוס', 'u-active', existing.active ? '1' : '0', [['1', 'פעיל'], ['0', 'מושבת']]) : '') +
      (!isEdit ? '<p style="color:var(--gs-muted);font-size:13px;margin:4px 0">יישלח למשתמש מייל להגדרת סיסמה (דורש שרת דוא"ל מוגדר).</p>' : '');

    openModal(isEdit ? 'עריכת משתמש' : 'משתמש חדש', body, function () {
      var name = val('u-name');
      if (!name) { showModalErr('שם חובה'); return false; }
      var p;
      if (isEdit) {
        p = api('/users/' + existing.id, { method: 'PUT', body: { name: name, role: val('u-role'), active: val('u-active') === '1' } });
      } else {
        var email = val('u-email');
        if (!email) { showModalErr('אימייל חובה'); return false; }
        p = api('/users', { method: 'POST', body: { name: name, email: email, role: val('u-role') } });
      }
      return p.then(function () { if (onDone) onDone(); });
    });
  }

  window.GSM_Forms = {
    openEquipment: openEquipment,
    openFault: openFault,
    openInspection: openInspection,
    openUser: openUser,
    confirmDelete: confirmDelete
  };
})();
