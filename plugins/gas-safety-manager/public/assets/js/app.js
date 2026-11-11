/**
 * Gas Safety Manager — app bootstrap.
 *
 * Loads after router.js, auth.js, forms.js, and the view files. On boot it
 * checks the session, installs an auth guard (private screens redirect to
 * login when logged out), and starts the router.
 */
(function () {
  'use strict';
  if (!window.GSM || !window.GSM_CONFIG) return;

  var PRIVATE = { dashboard: 1, myequip: 1, equipment: 1, faults: 1, inspections: 1, qr: 1, users: 1, inspcfg: 1, admin: 1, reports: 1, quality: 1, profile: 1, help: 1, brsetup: 1, audit: 1, security: 1, bi: 1, integrations: 1, qrissue: 1, database: 1, import: 1, restorepts: 1, legacy: 1, privacy: 1, panels: 1, contacts: 1, comm: 1 };

  // Auth guard: block private screens when logged out.
  GSM.addGuard(function (route) {
    if (PRIVATE[route.name] && !GSM.session.loggedIn) {
      return '/login';
    }
    // Mandatory 2FA not set up yet: only the security screen is reachable.
    if (PRIVATE[route.name] && route.name !== 'security' && GSM.session.user && GSM.session.user.must2fa) {
      return '/security';
    }
    return null;
  });

  // Boot: resolve session first, then render whatever route is in the URL.
  GSM.checkSession().then(function () {
    if (!location.hash || location.hash === '#') {
      location.hash = GSM.session.loggedIn ? '/dashboard' : '/';
    }
    GSM.render();
  });
})();
