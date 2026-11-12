/**
 * Gas Safety — reCAPTCHA v3 loader.
 *
 * Google's script is fetched only when a token is first requested (the
 * visitor pressed "sign in" / "send report"), never with the page.
 * GSM_captchaToken(action) always resolves — '' when the script could not be
 * loaded — so a blocked or slow Google never breaks the form.
 */
(function () {
  'use strict';
  var CFG = window.GSM_CAPTCHA || {};
  var ready = null;

  function load(siteKey) {
    if (ready) return ready;
    ready = new Promise(function (resolve) {
      var done = false;
      function finish(ok) { if (!done) { done = true; resolve(ok); } }
      var s = document.createElement('script');
      s.src = 'https://www.google.com/recaptcha/api.js?render=' + encodeURIComponent(siteKey);
      s.async = true;
      s.onload = function () {
        if (window.grecaptcha && window.grecaptcha.ready) window.grecaptcha.ready(function () { finish(true); });
        else finish(false);
      };
      s.onerror = function () { finish(false); };
      setTimeout(function () { finish(false); }, 8000);
      document.head.appendChild(s);
    });
    return ready;
  }

  // siteKey is optional: the settings screen passes the key being tested.
  window.GSM_captchaToken = function (action, siteKey) {
    var key = siteKey || CFG.siteKey;
    if (!key) return Promise.resolve('');
    return load(key).then(function (ok) {
      if (!ok || !window.grecaptcha || !window.grecaptcha.execute) return '';
      return window.grecaptcha.execute(key, { action: action }).then(function (t) { return t || ''; }, function () { return ''; });
    }).catch(function () { return ''; });
  };
  window.GSM_captchaActive = function () { return !!CFG.siteKey; };

  // Required by Google when its badge is not shown; also tells the visitor
  // that pressing the button contacts Google.
  window.GSM_captchaNotice = function () {
    return '<p class="gs-captcha-note" style="color:var(--gs-muted);font-size:12px;margin:10px 0 0">הטופס מוגן על ידי reCAPTCHA: בלחיצה על הכפתור נשלח מידע טכני ל-Google. חלים ' +
      '<a href="https://policies.google.com/privacy" target="_blank" rel="noopener noreferrer">מדיניות הפרטיות</a> ו' +
      '<a href="https://policies.google.com/terms" target="_blank" rel="noopener noreferrer">תנאי השימוש</a> של Google.</p>';
  };
})();
