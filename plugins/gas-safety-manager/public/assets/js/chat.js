/**
 * Gas Safety — chat launcher (click-to-load).
 *
 * Shows our own "chat" button. Only when it is clicked do we load the
 * (locally served, reviewed) Botwa loader, which then fetches its config and
 * opens the chat in a cross-origin iframe from the chat server. Until then the
 * page makes no request to the chat provider.
 * Config: window.GSM_CHAT = { origin, key, label, loader }.
 */
(function () {
  'use strict';
  var C = window.GSM_CHAT;
  if (!C || !C.origin || !C.key || !C.loader) return;

  var btn, loading = false, timer = null;

  function setState(text, busy) {
    btn.querySelector('.gsm-chat-btn__text').textContent = text;
    btn.setAttribute('aria-busy', busy ? 'true' : 'false');
    btn.disabled = !!busy;
  }

  function vendorReady() {
    clearTimeout(timer);
    btn.hidden = true; // the provider's own launcher takes over
    if (window.BotwaChat) window.BotwaChat.open();
  }

  function load() {
    if (window.BotwaChat && window.BotwaChat.__loaded) { window.BotwaChat.open(); return; }
    if (loading) return;
    loading = true;
    setState('טוען…', true);
    var s = document.createElement('script');
    s.src = C.loader;
    s.async = true;
    s.setAttribute('data-key', C.key);
    s.setAttribute('data-origin', C.origin);
    s.onload = function () {
      if (window.BotwaChat && window.BotwaChat.on) window.BotwaChat.on('ready', vendorReady);
    };
    s.onerror = fail;
    document.body.appendChild(s);
    // The loader stays silent if the chat server is unreachable; give up after a while.
    timer = setTimeout(fail, 10000);
  }

  function fail() {
    clearTimeout(timer);
    if (btn.hidden) return;
    loading = false;
    setState('הצ׳אט לא זמין כרגע', false);
    setTimeout(function () { setState(C.label || 'צ׳אט', false); }, 4000);
  }

  function build() {
    btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'gsm-chat-btn';
    btn.setAttribute('aria-haspopup', 'dialog');
    btn.title = 'פתיחת צ׳אט — שירות חיצוני. ההודעות שתכתבו יישלחו לספק הצ׳אט.';
    btn.innerHTML = '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" ' +
      'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M21 12a8 8 0 0 1-11.7 7.1L4 20l1-4.2A8 8 0 1 1 21 12z"/></svg>' +
      '<span class="gsm-chat-btn__text"></span>';
    btn.querySelector('.gsm-chat-btn__text').textContent = C.label || 'צ׳אט';
    btn.addEventListener('click', load);
    document.body.appendChild(btn);
  }

  if (document.body) build(); else document.addEventListener('DOMContentLoaded', build);
})();
