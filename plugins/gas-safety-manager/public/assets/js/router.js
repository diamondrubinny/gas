/**
 * Gas Safety — hash router.
 *
 * Tiny dependency-free router. Maps #/path to a view function and swaps the
 * WHOLE screen (not a panel underneath). Supports one param (#/equipment/5).
 * Views register themselves via GSM.registerView(name, fn).
 */
(function () {
  'use strict';

  window.GSM = window.GSM || {};
  var views = {};
  var guards = [];

  GSM.registerView = function (name, fn) {
    views[name] = fn;
  };

  // A guard returns a redirect route string to block, or null to allow.
  GSM.addGuard = function (fn) {
    guards.push(fn);
  };

  function parse() {
    var h = (location.hash || '#/').replace(/^#/, '');
    var parts = h.split('/').filter(Boolean); // ['equipment','5']
    return { name: parts[0] || 'home', param: parts[1] || null, raw: h };
  }

  GSM.navigate = function (route) {
    if (('#' + route) === location.hash) {
      render(); // same hash — force re-render
    } else {
      location.hash = route;
    }
  };

  function render() {
    var r = parse();

    // Run guards (e.g. auth). First one that returns a route wins.
    for (var i = 0; i < guards.length; i++) {
      var redirect = guards[i](r);
      if (redirect && redirect !== r.raw) {
        location.hash = redirect;
        return;
      }
    }

    var view = views[r.name] || views['home'];
    var mount = document.getElementById('gsm-app');
    if (!mount) return;
    try {
      view(mount, r.param);
    } catch (e) {
      mount.innerHTML = '<div class="gs-error">שגיאה בטעינת המסך: ' + String(e.message) + '</div>';
    }
  }

  GSM.render = render;

  window.addEventListener('hashchange', render);
  // Boot is triggered by app.js after auth check.
})();
