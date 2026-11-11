/**
 * Gas Safety — accessibility adjustments menu.
 *
 * A floating button that opens a small menu: larger text, higher contrast,
 * highlighted links, a plain readable font, and stopping animations.
 * The choices are kept in this browser only (localStorage) and are never
 * sent anywhere. This is a convenience; the pages themselves are built to be
 * accessible without it.
 */
(function () {
  'use strict';
  if (window.__gsmA11yWidget) return;
  window.__gsmA11yWidget = true;

  var KEY = 'gsm_a11y';
  var root = document.documentElement;
  var state = { text: 0, contrast: false, links: false, font: false, motion: false };
  try {
    var saved = JSON.parse(window.localStorage.getItem(KEY) || '{}');
    if (saved && typeof saved === 'object') {
      state.text = saved.text === 1 || saved.text === 2 ? saved.text : 0;
      ['contrast', 'links', 'font', 'motion'].forEach(function (k) { state[k] = saved[k] === true; });
    }
  } catch (e) { /* storage blocked: defaults */ }

  function apply() {
    root.classList.toggle('gsm-a11y-text-1', state.text === 1);
    root.classList.toggle('gsm-a11y-text-2', state.text === 2);
    ['contrast', 'links', 'font', 'motion'].forEach(function (k) { root.classList.toggle('gsm-a11y-' + k, !!state[k]); });
  }
  function save() {
    try { window.localStorage.setItem(KEY, JSON.stringify(state)); } catch (e) { /* ignore */ }
  }
  apply();

  var TEXT_LABEL = ['רגיל', 'גדול', 'גדול מאוד'];
  var OPTS = [['contrast', 'ניגודיות גבוהה'], ['links', 'הדגשת קישורים'], ['font', 'גופן קריא'], ['motion', 'עצירת אנימציות']];

  function el(tag, attrs, text) {
    var n = document.createElement(tag);
    Object.keys(attrs || {}).forEach(function (k) { n.setAttribute(k, attrs[k]); });
    if (text != null) n.textContent = text;
    return n;
  }

  function build() {
    if (document.getElementById('gsm-a11y-btn')) return;
    var btn = el('button', { type: 'button', id: 'gsm-a11y-btn', 'class': 'gsm-a11y-btn', 'aria-label': 'תפריט נגישות', 'aria-expanded': 'false', 'aria-controls': 'gsm-a11y-panel' });
    var NS = 'http://www.w3.org/2000/svg';
    var svg = document.createElementNS(NS, 'svg');
    svg.setAttribute('viewBox', '0 0 24 24'); svg.setAttribute('width', '26'); svg.setAttribute('height', '26');
    svg.setAttribute('fill', 'none'); svg.setAttribute('stroke', 'currentColor'); svg.setAttribute('stroke-width', '2');
    svg.setAttribute('stroke-linecap', 'round'); svg.setAttribute('stroke-linejoin', 'round');
    svg.setAttribute('aria-hidden', 'true'); svg.setAttribute('focusable', 'false');
    var c = document.createElementNS(NS, 'circle'); c.setAttribute('cx', '12'); c.setAttribute('cy', '4.5'); c.setAttribute('r', '1.8');
    var p = document.createElementNS(NS, 'path'); p.setAttribute('d', 'M4 9h16M12 9v6M12 15l-4 6M12 15l4 6');
    svg.appendChild(c); svg.appendChild(p); btn.appendChild(svg);

    var panel = el('div', { id: 'gsm-a11y-panel', 'class': 'gsm-a11y-panel', role: 'dialog', 'aria-label': 'התאמות נגישות', lang: 'he', dir: 'rtl' });
    panel.hidden = true;
    panel.appendChild(el('h2', {}, 'התאמות נגישות'));

    var textBtn = el('button', { type: 'button', 'class': 'gsm-a11y-opt', 'data-a11y': 'text' });
    var textName = el('span', {}, 'גודל טקסט');
    var textState = el('span', { 'class': 'gsm-a11y-state' });
    textBtn.appendChild(textName); textBtn.appendChild(textState);
    panel.appendChild(textBtn);

    var toggles = {};
    OPTS.forEach(function (o) {
      var b = el('button', { type: 'button', 'class': 'gsm-a11y-opt', 'data-a11y': o[0], 'aria-pressed': 'false' });
      b.appendChild(el('span', {}, o[1]));
      var s = el('span', { 'class': 'gsm-a11y-state' });
      b.appendChild(s);
      toggles[o[0]] = [b, s];
      b.addEventListener('click', function () { state[o[0]] = !state[o[0]]; apply(); save(); sync(); });
      panel.appendChild(b);
    });
    var reset = el('button', { type: 'button', 'class': 'gsm-a11y-opt gsm-a11y-reset', 'data-a11y': 'reset' }, 'איפוס ההתאמות');
    panel.appendChild(reset);
    var live = el('div', { role: 'status', 'aria-live': 'polite', style: 'position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0)' });
    panel.appendChild(live);

    function sync() {
      textState.textContent = TEXT_LABEL[state.text];
      OPTS.forEach(function (o) {
        toggles[o[0]][0].setAttribute('aria-pressed', state[o[0]] ? 'true' : 'false');
        toggles[o[0]][1].textContent = state[o[0]] ? 'פעיל' : 'כבוי';
      });
    }
    textBtn.addEventListener('click', function () {
      state.text = (state.text + 1) % 3; apply(); save(); sync();
      live.textContent = 'גודל טקסט: ' + TEXT_LABEL[state.text];
    });
    reset.addEventListener('click', function () {
      state = { text: 0, contrast: false, links: false, font: false, motion: false };
      apply(); save(); sync(); live.textContent = 'ההתאמות אופסו';
    });

    function open() { panel.hidden = false; btn.setAttribute('aria-expanded', 'true'); textBtn.focus(); }
    function close(refocus) { panel.hidden = true; btn.setAttribute('aria-expanded', 'false'); if (refocus) btn.focus(); }
    btn.addEventListener('click', function () { if (panel.hidden) open(); else close(true); });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && !panel.hidden) { e.stopPropagation(); close(true); }
    });
    document.addEventListener('click', function (e) {
      if (!panel.hidden && !panel.contains(e.target) && !btn.contains(e.target)) close(false);
    });
    sync();
    document.body.appendChild(btn);
    document.body.appendChild(panel);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', build);
  else build();
})();
