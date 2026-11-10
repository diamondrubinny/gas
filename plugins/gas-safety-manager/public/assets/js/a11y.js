/**
 * Gas Safety — accessibility glue (no dependencies).
 *
 * The views build forms as  <div class="gs-field"><label class="gs-label">…</label><input …></div>.
 * The label is visible but was not programmatically tied to its control, so
 * screen readers announced an unnamed field (WCAG 1.3.1 / 4.1.2). This module
 * runs on every DOM change (screens, modals, async content) and:
 *   1. links a .gs-field's label to the single control in that field (for/id);
 *   2. gives a control that still has no accessible name the text of its
 *      placeholder as aria-label (search boxes, checklist rows).
 * It never overrides an existing label, aria-label or aria-labelledby.
 */
(function () {
  'use strict';

  var seq = 0;
  var CONTROLS = 'input:not([type="hidden"]):not([type="button"]):not([type="submit"]), select, textarea';

  function hasName(el) {
    if (el.getAttribute('aria-label') || el.getAttribute('aria-labelledby')) return true;
    if (el.labels && el.labels.length) return true;
    return !!el.closest('label');
  }

  function ensureId(el) {
    if (!el.id) {
      seq += 1;
      el.id = 'gsm-f-' + seq;
    }
    return el.id;
  }

  function enhance(root) {
    if (!root || !root.querySelectorAll) return;

    // 1. Label -> control inside the same .gs-field.
    var fields = root.querySelectorAll('.gs-field');
    for (var i = 0; i < fields.length; i++) {
      var f = fields[i];
      var label = f.querySelector('label.gs-label:not([for])');
      if (!label) continue;
      var ctrls = f.querySelectorAll(CONTROLS);
      if (ctrls.length !== 1 || hasName(ctrls[0])) continue;
      label.htmlFor = ensureId(ctrls[0]);
    }

    // 1b. A label ending in "*" marks a required field.
    var labels = root.querySelectorAll('label.gs-label[for]');
    for (var k = 0; k < labels.length; k++) {
      if (!/\*\s*$/.test(labels[k].textContent)) continue;
      var t = document.getElementById(labels[k].htmlFor);
      if (t && !t.hasAttribute('aria-required')) t.setAttribute('aria-required', 'true');
    }

    // 2. Remaining unnamed controls: fall back to the placeholder text.
    var all = root.querySelectorAll(CONTROLS);
    for (var j = 0; j < all.length; j++) {
      var c = all[j];
      if (hasName(c)) continue;
      var ph = c.getAttribute('placeholder');
      if (ph) c.setAttribute('aria-label', ph);
    }
  }

  var pending = false;
  function schedule() {
    if (pending) return;
    pending = true;
    // Microtask: runs after the current render finished, before paint.
    Promise.resolve().then(function () { pending = false; enhance(document.body); });
  }

  function start() {
    enhance(document.body);
    if (window.MutationObserver) {
      new MutationObserver(schedule).observe(document.body, { childList: true, subtree: true });
    }
  }

  // ---- modal dialogs (WCAG 2.1.2, 2.4.3, 4.1.2) ---------------------------
  // dialog(box, opts) makes `box` an accessible modal: role/aria, moves focus
  // in, keeps Tab inside, Escape closes, and focus returns to the element that
  // opened it. Returns release(), to call when the dialog is removed.
  var FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

  function visible(el) {
    if (el.getAttribute('tabindex') === '-1' || el.closest('[aria-hidden="true"]')) return false; // e.g. honeypot
    return !!(el.offsetWidth || el.offsetHeight || el.getClientRects().length);
  }

  function dialog(box, opts) {
    opts = opts || {};
    var opener = document.activeElement;
    box.setAttribute('role', 'dialog');
    box.setAttribute('aria-modal', 'true');
    var title = opts.title || box.querySelector('.gs-modal__title');
    if (title) box.setAttribute('aria-labelledby', ensureId(title));

    function focusables() {
      return Array.prototype.filter.call(box.querySelectorAll(FOCUSABLE), visible);
    }
    function onKey(e) {
      if (e.key === 'Escape') {
        e.preventDefault();
        if (opts.onEscape) opts.onEscape();
        return;
      }
      if (e.key !== 'Tab') return;
      var f = focusables();
      if (!f.length) { e.preventDefault(); return; }
      var first = f[0], last = f[f.length - 1];
      if (e.shiftKey && (document.activeElement === first || !box.contains(document.activeElement))) {
        e.preventDefault(); last.focus();
      } else if (!e.shiftKey && (document.activeElement === last || !box.contains(document.activeElement))) {
        e.preventDefault(); first.focus();
      }
    }
    document.addEventListener('keydown', onKey, true);

    var initial = (opts.initialFocus && box.querySelector(opts.initialFocus)) || focusables()[0];
    if (initial) initial.focus(); else { box.setAttribute('tabindex', '-1'); box.focus(); }

    var released = false;
    return function release() {
      if (released) return;
      released = true;
      document.removeEventListener('keydown', onKey, true);
      if (opener && opener.focus && document.documentElement.contains(opener)) opener.focus();
    };
  }

  window.GSM_A11Y = { enhance: enhance, dialog: dialog };
  if (document.body) start(); else document.addEventListener('DOMContentLoaded', start);
})();
