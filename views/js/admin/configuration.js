/**
 * Page de configuration : onglets accessibles (sans dépendance) et test de connexion S3.
 */
(function (w, d) {
  'use strict';

  var S = w.SntQuotes;
  var tabs = d.querySelectorAll('.snt-config [data-tab]');
  var active = d.querySelector('[data-active-tab]');

  function show(key) {
    tabs.forEach(function (tab) {
      var on = tab.getAttribute('data-tab') === key;
      tab.classList.toggle('active', on);
      tab.setAttribute('aria-selected', on ? 'true' : 'false');
      d.getElementById('pane-' + tab.getAttribute('data-tab')).hidden = !on;
    });
    if (active) {
      active.value = key;
    }
  }

  tabs.forEach(function (tab, index) {
    tab.addEventListener('click', function (e) {
      e.preventDefault();
      show(tab.getAttribute('data-tab'));
    });
    tab.addEventListener('keydown', function (e) {
      var next = e.key === 'ArrowRight' ? index + 1 : (e.key === 'ArrowLeft' ? index - 1 : null);
      if (next !== null && tabs[(next + tabs.length) % tabs.length]) {
        e.preventDefault();
        var target = tabs[(next + tabs.length) % tabs.length];
        show(target.getAttribute('data-tab'));
        target.focus();
      }
    });
  });

  // Ouvre l'onglet qui contient le premier champ en erreur.
  var invalid = d.querySelector('.snt-config .is-invalid, .snt-config .has-danger, .snt-config .form-error-message');
  if (invalid) {
    var pane = invalid.closest('[role=tabpanel]');
    if (pane) {
      show(pane.id.replace('pane-', ''));
    }
  }

  var test = d.querySelector('[data-s3-test]');
  if (test && S) {
    test.addEventListener('click', function () {
      var out = d.querySelector('[data-s3-result]');
      out.textContent = '…';
      test.disabled = true;
      S.api(test.getAttribute('data-s3-test'), { body: {} }).then(function (r) {
        out.textContent = r.message;
        out.className = 'ml-2 ' + (r.ok ? 'text-success' : 'text-warning');
      }).catch(function (e) {
        out.textContent = e.message;
        out.className = 'ml-2 text-danger';
      }).then(function () { test.disabled = false; });
    });
  }
})(window, document);
