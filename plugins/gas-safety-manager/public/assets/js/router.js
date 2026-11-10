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

  // ---- delegated actions (CSP-safe: no inline on*="" handlers) -------------
  // Markup declares intent with data attributes; one listener dispatches:
  //   data-gsm-nav="/route"        -> GSM.navigate(route)
  //   data-gsm-action="name"       -> handler registered via GSM.registerAction
  //   data-gsm-action="select"     -> select the text of a readonly input
  var actions = {
    select: function (el) { if (el.select) el.select(); }
  };

  GSM.registerAction = function (name, fn) {
    actions[name] = fn;
  };

  document.addEventListener('click', function (e) {
    var t = e.target;
    var el = t && t.closest ? t.closest('[data-gsm-nav],[data-gsm-action]') : null;
    if (!el) return;
    var nav = el.getAttribute('data-gsm-nav');
    if (nav !== null) {
      e.preventDefault();
      GSM.navigate(nav);
      return;
    }
    var fn = actions[el.getAttribute('data-gsm-action')];
    if (fn) fn(el, e);
  });

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
      var box = document.createElement('div');
      box.className = 'gs-error';
      box.textContent = 'שגיאה בטעינת המסך: ' + String(e && e.message);
      mount.innerHTML = '';
      mount.appendChild(box);
    }
  }

  GSM.render = render;

  window.addEventListener('hashchange', render);
  // Boot is triggered by app.js after auth check.
})();
