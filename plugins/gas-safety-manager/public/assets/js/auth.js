/**
 * Gas Safety — client auth + shared helpers.
 *
 * Holds session state (who's logged in + caps + nonce), the api() fetch
 * wrapper every view uses, and login/logout. On boot it calls /auth/me to
 * decide whether to show the app or the public home/login.
 */
(function () {
  'use strict';

  window.GSM = window.GSM || {};

  var CFG = window.GSM_CONFIG || {};
  var session = { loggedIn: false, nonce: CFG.nonce || '', user: null };

  GSM.session = session;

  // ---- shared helpers ------------------------------------------------------

  GSM.esc = function (s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  };

  // Permalink-safe absolute REST URL. When restRoot already carries a query
  // (?rest_route=…, i.e. "Plain" permalinks), a path's own "?" must join with
  // "&". Exposed so views can build shareable links (e.g. BI export URLs).
  GSM.restUrl = function (path) {
    return CFG.restRoot.indexOf('?') > -1 ? (CFG.restRoot + path.replace('?', '&')) : (CFG.restRoot + path);
  };

  // Absolute URL to a bundled plugin asset (served same-origin; no CDN).
  GSM.asset = function (rel) {
    return (CFG.assetsUrl || '') + String(rel || '').replace(/^\//, '');
  };

  // Low-level fetch -> JSON. Throws an Error carrying .status and .code.
  function rawApi(path, opts) {
    opts = opts || {};
    var headers = { 'Content-Type': 'application/json' };
    if (session.nonce) headers['X-WP-Nonce'] = session.nonce;
    return fetch(GSM.restUrl(path), {
      method: opts.method || 'GET',
      headers: headers,
      body: opts.body ? JSON.stringify(opts.body) : undefined,
      credentials: 'same-origin'
    }).then(function (r) {
      return r.json().then(function (j) {
        if (!r.ok) {
          var err = new Error((j && j.message) || ('HTTP ' + r.status));
          err.status = r.status;
          err.code = j && j.code;
          throw err;
        }
        return j;
      });
    });
  }

  // The WordPress "expired/rotated nonce" error: HTTP 403
  // rest_cookie_invalid_nonce ("Cookie check failed" / "בדיקת עוגיות נכשלה"),
  // seen when the page's baked-in nonce has aged out, rotated after login, or
  // came from a cached page.
  function isNonceError(err) {
    if (!err || err.status !== 403) return false;
    if (err.code === 'rest_cookie_invalid_nonce') return true;
    var m = err.message || '';
    return /cookie check failed|invalid nonce/i.test(m) || m.indexOf('עוגיות') > -1;
  }

  // Fetch a fresh REST nonce for the CURRENT login cookie via admin-ajax,
  // which authenticates by cookie and needs no REST nonce — so it still works
  // after the REST nonce expired. Resolves to { logged_in, nonce }.
  // Fallback: pull a fresh nonce out of a fresh copy of the app page itself
  // (used only if admin-ajax is unreachable, e.g. /wp-admin blocked for the
  // role). The page always renders a valid nonce for the current login cookie.
  function refreshNonceFromPage() {
    return fetch(window.location.href, { credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) { return r.text(); })
      .then(function (html) {
        var m = html.match(/window\.GSM_CONFIG\s*=\s*(\{[\s\S]*?\})\s*;/);
        if (m) {
          try {
            var c = JSON.parse(m[1]);
            if (c && c.nonce) { session.nonce = c.nonce; return { logged_in: true, nonce: c.nonce }; }
          } catch (e) { /* ignore parse errors */ }
        }
        return {};
      }).catch(function () { return {}; });
  }

  GSM.refreshNonce = function () {
    var url = CFG.ajaxUrl || '/wp-admin/admin-ajax.php';
    return fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: 'action=gsm_nonce',
      credentials: 'same-origin'
    }).then(function (r) { return r.ok ? r.json() : null; }).then(function (j) {
      if (j && j.nonce) { session.nonce = j.nonce; return j; }
      return refreshNonceFromPage();
    }).catch(function () { return refreshNonceFromPage(); });
  };

  // Public API wrapper every view uses. On an expired/rotated nonce it fetches
  // a fresh nonce and retries ONCE, transparently — the nonce check runs
  // before the route handler, so the rejected call never ran and the retry
  // can't double-submit. The user never has to refresh the page manually.
  GSM.api = function (path, opts) {
    opts = opts || {};
    return rawApi(path, opts).catch(function (err) {
      if (opts._retried || !isNonceError(err)) throw err;
      opts._retried = true;
      return GSM.refreshNonce().then(function (info) {
        return rawApi(path, opts).catch(function (err2) {
          // Fresh nonce still failed: if the session is really gone, fall back
          // to the login screen (but never bounce an /auth/ call itself).
          // Only when the app still believed it was logged in: after a
          // deliberate logout, late answers to requests that were still in
          // flight must not pull the user from the home page to the login screen.
          if (info && info.logged_in === false && path.indexOf('/auth/') !== 0 && session.loggedIn) {
            session.loggedIn = false;
            session.user = null;
            if (GSM.navigate) { GSM.navigate('/login'); }
          }
          throw err2;
        });
      });
    });
  };

  // Keep the REST nonce fresh so an idle tab doesn't hit "Cookie check failed"
  // on the user's first action. Refresh on a timer and whenever the tab
  // regains focus (the classic "left it open overnight" case).
  var _nonceHeartbeat = null;
  function startNonceHeartbeat() {
    if (_nonceHeartbeat) { return; }
    _nonceHeartbeat = setInterval(function () {
      if (session.loggedIn && !document.hidden) { GSM.refreshNonce(); }
    }, 12 * 60 * 1000);
    if (!window.__gsmNonceVis) {
      window.__gsmNonceVis = true;
      document.addEventListener('visibilitychange', function () {
        if (!document.hidden && session.loggedIn) { GSM.refreshNonce(); }
      });
    }
  }

  GSM.can = function (which) {
    return !!(session.user && session.user.caps && session.user.caps[which]);
  };

  // Current REST nonce (wp_rest). Lets views build authenticated download
  // links via the _wpnonce query param (for endpoints that stream a file).
  GSM.restNonce = function () { return session.nonce || ''; };

  // ---- auth actions --------------------------------------------------------

  GSM.checkSession = function () {
    return GSM.api('/auth/me').then(function (me) {
      applyMe(me);
      return me;
    }).catch(function () {
      session.loggedIn = false;
      session.user = null;
      return { logged_in: false };
    });
  };

  GSM.login = function (user, password, remember, code) {
    var body = { user: user, password: password, remember: !!remember };
    if (code) body.code = code;
    // Optional reCAPTCHA: a fresh token per attempt ('' when not in use).
    var tokenP = window.GSM_captchaToken ? window.GSM_captchaToken('login') : Promise.resolve('');
    return tokenP.then(function (t) {
      if (t) body.captcha = t;
      return GSM.api('/auth/login', { method: 'POST', body: body });
    }).then(function (me) {
      // Second step needed: keep the session logged out and let the view ask.
      if (me && me.requires_2fa) { if (me.nonce) session.nonce = me.nonce; return me; }
      applyMe(me);
      return me;
    });
  };

  // ---- Passkeys (WebAuthn) ---------------------------------------------------
  function b64uToBuf(s) {
    s = String(s).replace(/-/g, '+').replace(/_/g, '/');
    while (s.length % 4) s += '=';
    var bin = atob(s), u = new Uint8Array(bin.length);
    for (var i = 0; i < bin.length; i++) u[i] = bin.charCodeAt(i);
    return u.buffer;
  }
  function bufToB64u(buf) {
    var u = new Uint8Array(buf), bin = '';
    for (var i = 0; i < u.length; i++) bin += String.fromCharCode(u[i]);
    return btoa(bin).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
  }
  GSM.passkeysSupported = function () {
    return !!(window.PublicKeyCredential && navigator.credentials && window.isSecureContext);
  };

  // Sign in with a passkey: the browser shows the passkeys saved for this site.
  GSM.passkeyLogin = function () {
    return GSM.api('/auth/passkey/options', { method: 'POST' }).then(function (o) {
      var pk = o.publicKey;
      pk.challenge = b64uToBuf(pk.challenge);
      pk.allowCredentials = [];
      return navigator.credentials.get({ publicKey: pk }).then(function (cred) {
        if (!cred) throw new Error('לא נבחר Passkey');
        return GSM.api('/auth/passkey', { method: 'POST', body: {
          token: o.token,
          id: bufToB64u(cred.rawId),
          clientDataJSON: bufToB64u(cred.response.clientDataJSON),
          authenticatorData: bufToB64u(cred.response.authenticatorData),
          signature: bufToB64u(cred.response.signature)
        } });
      });
    }).then(function (me) { applyMe(me); return me; });
  };

  // Add a passkey to the signed-in account.
  GSM.passkeyRegister = function (name) {
    return GSM.api('/passkeys/register/options', { method: 'POST' }).then(function (o) {
      var pk = o.publicKey;
      pk.challenge = b64uToBuf(pk.challenge);
      pk.user.id = b64uToBuf(pk.user.id);
      pk.excludeCredentials = (pk.excludeCredentials || []).map(function (c) { return { type: c.type, id: b64uToBuf(c.id) }; });
      return navigator.credentials.create({ publicKey: pk }).then(function (cred) {
        if (!cred) throw new Error('הרישום בוטל');
        return GSM.api('/passkeys/register', { method: 'POST', body: {
          token: o.token,
          name: name || '',
          clientDataJSON: bufToB64u(cred.response.clientDataJSON),
          attestationObject: bufToB64u(cred.response.attestationObject)
        } });
      });
    });
  };

  GSM.logout = function () {
    return GSM.api('/auth/logout', { method: 'POST' }).then(function (r) {
      session.loggedIn = false;
      session.user = null;
      if (r && r.nonce) session.nonce = r.nonce;
      return r;
    });
  };

  function applyMe(me) {
    session.loggedIn = !!(me && me.logged_in);
    if (me && me.nonce) session.nonce = me.nonce;
    session.user = session.loggedIn ? {
      id: me.id, name: me.name, email: me.email,
      caps: me.caps || {}, roleLabel: me.role_label || '',
      roleKey: me.role_key || '', inspectTypes: me.inspect_types || [],
      navHidden: me.nav_hidden || [], navAllowed: me.nav_allowed || null,
      twofa: !!me.twofa, must2fa: !!me.must_2fa
    } : null;
    if (session.loggedIn) { startNonceHeartbeat(); }
  }
})();
