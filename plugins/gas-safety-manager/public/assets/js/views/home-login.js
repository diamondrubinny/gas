/**
 * Gas Safety — Home + Login views.
 */
(function () {
  'use strict';
  var esc = GSM.esc;

  // ---- HOME (public) -------------------------------------------------------

  GSM.registerView('home', function (mount) {
    var loggedIn = GSM.session.loggedIn;
    var brand = (window.GSM_CONFIG && window.GSM_CONFIG.branding) || {};
    mount.innerHTML =
      '<div class="gs-home">' +
      '<div class="gs-home__card">' +
      '<img class="gs-home__logo" src="' + esc(GSM.asset('img/brand/logo-badge.png')) + '" alt="יחידת הבטיחות — הטכניון">' +
      '<h1 class="gs-home__title">' + esc(brand.title || 'מערכת ניהול תחזוקה — מערכות גזים') + '</h1>' +
      '<p class="gs-home__sub">' + esc(brand.subtitle || 'יחידת הבטיחות · הטכניון') + '</p>' +
      '<p class="gs-home__desc">ניהול ציוד גזים, ביקורות תקופתיות, תקלות ומעקב — במקום אחד.</p>' +
      '<div class="gs-home__actions">' +
      (loggedIn
        ? '<button class="gs-btn gs-btn--lg" type="button" data-gsm-nav="/dashboard">כניסה לדשבורד ←</button>'
        : '<button class="gs-btn gs-btn--lg" type="button" data-gsm-nav="/login">כניסה למערכת ←</button>') +
      '</div>' +
      '<p class="gs-home__note">סריקת קוד QR על ציוד פותחת את דף הציוד ישירות — ללא צורך בהתחברות.</p>' +
      '</div></div>';
  });

  // ---- LOGIN ---------------------------------------------------------------

  GSM.registerView('login', function (mount) {
    // Already logged in? go to dashboard.
    if (GSM.session.loggedIn) {
      GSM.navigate('/dashboard');
      return;
    }

    mount.innerHTML =
      '<div class="gs-login">' +
      '<div class="gs-login__card">' +
      '<img class="gs-login__logo" src="' + esc(GSM.asset('img/brand/logo-badge.png')) + '" alt="יחידת הבטיחות — הטכניון">' +
      '<h2 class="gs-login__title">כניסה למערכת</h2>' +
      '<div class="gs-field">' +
      '<label class="gs-label" for="gsm-lg-user">שם משתמש או אימייל</label>' +
      '<input class="gs-input" id="gsm-lg-user" autocomplete="username" autofocus>' +
      '</div>' +
      '<div class="gs-field">' +
      '<label class="gs-label" for="gsm-lg-pass">סיסמה</label>' +
      '<input class="gs-input" id="gsm-lg-pass" type="password" autocomplete="current-password">' +
      '</div>' +
      '<label class="gs-check"><input type="checkbox" id="gsm-lg-remember"> זכור אותי</label>' +
      '<div id="gsm-lg-2fa" hidden><label class="gs-label" for="gsm-lg-code">קוד אימות</label>' +
      '<input class="gs-input" id="gsm-lg-code" inputmode="numeric" autocomplete="one-time-code" dir="ltr" maxlength="12" aria-describedby="gsm-lg-code-help">' +
      '<div id="gsm-lg-code-help" style="color:var(--gs-muted);font-size:12.5px;margin:4px 0 12px">6 ספרות מאפליקציית האימות, או אחד מקודי הגיבוי.</div></div>' +
      '<div id="gsm-lg-err"></div>' +
      '<button class="gs-btn gs-btn--lg gs-btn--block" id="gsm-lg-submit">התחבר</button>' +
      (GSM.passkeysSupported && GSM.passkeysSupported() ? '<button type="button" class="gs-btn gs-btn--lg gs-btn--block gs-btn--ghost" id="gsm-lg-passkey" style="margin-top:10px">כניסה עם Passkey</button>' : '') +
      '<button class="gs-btn gs-btn--ghost gs-btn--block" type="button" data-gsm-nav="/">חזרה לדף הבית</button>' +
      '</div></div>';

    var submit = document.getElementById('gsm-lg-submit');
    var errBox = document.getElementById('gsm-lg-err');

    function doLogin() {
      var user = document.getElementById('gsm-lg-user').value.trim();
      var pass = document.getElementById('gsm-lg-pass').value;
      var remember = document.getElementById('gsm-lg-remember').checked;
      var box2 = document.getElementById('gsm-lg-2fa');
      var codeEl = document.getElementById('gsm-lg-code');
      var code = box2.hidden ? '' : codeEl.value.trim();
      errBox.innerHTML = '';
      if (!box2.hidden && !code) {
        errBox.innerHTML = '<div class="gs-error" role="alert">יש להזין את קוד האימות</div>';
        codeEl.focus();
        return;
      }
      if (!user || !pass) {
        errBox.innerHTML = '<div class="gs-error" role="alert">יש להזין שם משתמש וסיסמה</div>';
        return;
      }
      submit.disabled = true;
      submit.textContent = 'מתחבר…';
      GSM.login(user, pass, remember, code).then(function (me) {
        if (me && me.requires_2fa) {
          box2.hidden = false;
          submit.disabled = false;
          submit.textContent = 'אמת והתחבר';
          errBox.innerHTML = '<div class="gs-card" role="status" style="margin-bottom:10px;padding:10px 12px">הסיסמה נכונה. הזינו עכשיו את קוד האימות.</div>';
          codeEl.focus();
          return;
        }
        GSM.navigate('/dashboard');
      }).catch(function (e) {
        errBox.innerHTML = '<div class="gs-error" role="alert">' + esc(e.message) + '</div>';
        submit.disabled = false;
        submit.textContent = box2.hidden ? 'התחבר' : 'אמת והתחבר';
        if (!box2.hidden) { codeEl.value = ''; codeEl.focus(); }
      });
    }

    submit.addEventListener('click', doLogin);
    var pkBtn = document.getElementById('gsm-lg-passkey');
    if (pkBtn) pkBtn.addEventListener('click', function () {
      errBox.innerHTML = '';
      pkBtn.disabled = true;
      GSM.passkeyLogin().then(function () {
        GSM.navigate('/dashboard');
      }).catch(function (e) {
        pkBtn.disabled = false;
        // The user closed the browser's prompt: not an error worth showing.
        if (e && (e.name === 'NotAllowedError' || e.name === 'AbortError')) return;
        errBox.innerHTML = '<div class="gs-error" role="alert">' + esc((e && e.message) || 'הכניסה עם Passkey נכשלה') + '</div>';
      });
    });
    ['gsm-lg-pass', 'gsm-lg-code'].forEach(function (id) {
      document.getElementById(id).addEventListener('keydown', function (e) {
        if (e.key === 'Enter') doLogin();
      });
    });
  });
})();
