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
        ? '<button class="gs-btn gs-btn--lg" onclick="GSM.navigate(\'/dashboard\')">כניסה לדשבורד ←</button>'
        : '<button class="gs-btn gs-btn--lg" onclick="GSM.navigate(\'/login\')">כניסה למערכת ←</button>') +
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
      '<div id="gsm-lg-err"></div>' +
      '<button class="gs-btn gs-btn--lg gs-btn--block" id="gsm-lg-submit">התחבר</button>' +
      '<button class="gs-btn gs-btn--ghost gs-btn--block" onclick="GSM.navigate(\'/\')">חזרה לדף הבית</button>' +
      '</div></div>';

    var submit = document.getElementById('gsm-lg-submit');
    var errBox = document.getElementById('gsm-lg-err');

    function doLogin() {
      var user = document.getElementById('gsm-lg-user').value.trim();
      var pass = document.getElementById('gsm-lg-pass').value;
      var remember = document.getElementById('gsm-lg-remember').checked;
      errBox.innerHTML = '';
      if (!user || !pass) {
        errBox.innerHTML = '<div class="gs-error">יש להזין שם משתמש וסיסמה</div>';
        return;
      }
      submit.disabled = true;
      submit.textContent = 'מתחבר…';
      GSM.login(user, pass, remember).then(function () {
        GSM.navigate('/dashboard');
      }).catch(function (e) {
        errBox.innerHTML = '<div class="gs-error">' + esc(e.message) + '</div>';
        submit.disabled = false;
        submit.textContent = 'התחבר';
      });
    }

    submit.addEventListener('click', doLogin);
    document.getElementById('gsm-lg-pass').addEventListener('keydown', function (e) {
      if (e.key === 'Enter') doLogin();
    });
  });
})();
