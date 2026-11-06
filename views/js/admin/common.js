/**
 * Utilitaires partagés du BO snt_quotes (sans dépendance : ni jQuery, ni build).
 * Exposés sous window.SntQuotes.
 */
(function (w, d) {
  'use strict';

  var S = w.SntQuotes = w.SntQuotes || {};

  S.escape = function (value) {
    return String(value === null || value === undefined ? '' : value)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  };

  S.format = function (template, params) {
    return String(template).replace(/%[a-z_]+%/gi, function (m) {
      return Object.prototype.hasOwnProperty.call(params || {}, m) ? params[m] : m;
    });
  };

  S.debounce = function (fn, wait) {
    var timer = null;
    return function () {
      var args = arguments;
      var self = this;
      clearTimeout(timer);
      timer = setTimeout(function () { fn.apply(self, args); }, wait);
    };
  };

  /** Lecture d'un nombre saisi à la française (« 1 234,56 »). */
  S.parseNumber = function (value) {
    if (typeof value === 'number') {
      return value;
    }
    var clean = String(value || '').replace(/[\s  ]/g, '').replace(',', '.');
    var n = parseFloat(clean);
    return isNaN(n) ? 0 : n;
  };

  S.moneyFormatter = function (locale, iso) {
    var fmt;
    try {
      fmt = new Intl.NumberFormat((locale || 'fr-FR').replace('_', '-'), { style: 'currency', currency: iso || 'EUR' });
    } catch (e) {
      fmt = new Intl.NumberFormat('fr-FR', { style: 'currency', currency: 'EUR' });
    }
    return function (amount) {
      return amount === null || amount === undefined || amount === '' ? '—' : fmt.format(Number(amount));
    };
  };

  S.numberInput = function (value, decimals) {
    if (value === null || value === undefined || value === '') {
      return '';
    }
    var n = Number(value);
    var fixed = n.toFixed(decimals === undefined ? 2 : decimals);
    // Retire les zéros inutiles au-delà de 2 décimales (prix à 6 décimales).
    if (decimals > 2) {
      fixed = fixed.replace(/(\.\d\d\d*?)0+$/, '$1');
    }
    return fixed.replace('.', ',');
  };

  /**
   * Appel JSON : jeton CSRF en en-tête, erreurs serveur transformées en Error lisible.
   */
  S.api = function (url, options) {
    options = options || {};
    var headers = { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' };
    if (S.csrf) {
      headers['X-CSRF-Token'] = S.csrf;
    }
    var init = { method: options.method || 'GET', headers: headers, credentials: 'same-origin' };
    if (options.query) {
      var query = Object.keys(options.query)
        .filter(function (k) { return options.query[k] !== undefined && options.query[k] !== null; })
        .map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(options.query[k]); }).join('&');
      if (query) {
        url += (url.indexOf('?') === -1 ? '?' : '&') + query;
      }
    }
    if (options.formData) {
      init.method = 'POST';
      init.body = options.formData;
    } else if (options.body !== undefined) {
      init.method = options.method || 'POST';
      headers['Content-Type'] = 'application/json';
      init.body = JSON.stringify(options.body);
    }
    return fetch(url, init).then(function (response) {
      return response.json().catch(function () { return {}; }).then(function (data) {
        if (!response.ok || data.success === false) {
          var error = new Error(data.message || S.i18n.networkError || 'Error');
          error.status = response.status;
          error.data = data;
          throw error;
        }
        return data;
      });
    }, function () {
      throw new Error((S.i18n && S.i18n.networkError) || 'Network error');
    });
  };

  S.i18n = S.i18n || {};

  /* --- Toasts ---------------------------------------------------------- */

  S.toast = function (message, type) {
    if (!message) {
      return;
    }
    var host = d.querySelector('.snt-toasts');
    if (!host) {
      host = d.createElement('div');
      host.className = 'snt-toasts';
      host.setAttribute('aria-live', 'polite');
      d.body.appendChild(host);
    }
    var toast = d.createElement('div');
    toast.className = 'snt-toast snt-toast--' + (type || 'success');
    toast.setAttribute('role', type === 'error' ? 'alert' : 'status');
    toast.textContent = message;
    host.appendChild(toast);
    setTimeout(function () { toast.classList.add('is-leaving'); }, type === 'error' ? 8000 : 4000);
    setTimeout(function () { toast.remove(); }, type === 'error' ? 8500 : 4500);
  };

  /* --- Boîtes de dialogue natives ------------------------------------ */

  S.openDialog = function (dialog) {
    var error = dialog.querySelector('[data-dialog-error]');
    if (error) {
      error.textContent = '';
    }
    if (typeof dialog.showModal === 'function') {
      dialog.showModal();
    } else {
      dialog.setAttribute('open', 'open');
    }
    var first = dialog.querySelector('input:not([type=hidden]), textarea, select');
    if (first) {
      first.focus();
    }
  };

  S.closeDialog = function (dialog) {
    if (typeof dialog.close === 'function') {
      dialog.close();
    } else {
      dialog.removeAttribute('open');
    }
  };

  d.addEventListener('click', function (event) {
    var close = event.target.closest('[data-close]');
    if (close) {
      var dialog = close.closest('dialog');
      if (dialog) {
        S.closeDialog(dialog);
      }
    }
  });

  /**
   * Confirmation des actions sensibles (formulaires data-confirm de la fiche devis).
   */
  d.addEventListener('submit', function (event) {
    var form = event.target;
    if (form.matches && form.matches('form[data-confirm]') && !w.confirm(form.getAttribute('data-confirm'))) {
      event.preventDefault();
    }
  });

  /* --- Autocomplétion accessible -------------------------------------- */

  /**
   * @param {HTMLInputElement} input
   * @param {HTMLElement} list
   * @param {{search: function(string): Promise<Array>, render: function(object): string, select: function(object), minLength?: number}} options
   */
  S.autocomplete = function (input, list, options) {
    var items = [];
    var active = -1;
    var requestId = 0;
    var min = options.minLength || 2;

    function close() {
      list.hidden = true;
      list.innerHTML = '';
      input.setAttribute('aria-expanded', 'false');
      active = -1;
    }

    function highlight(index) {
      var nodes = list.querySelectorAll('[role=option]');
      nodes.forEach(function (n, i) { n.classList.toggle('is-active', i === index); n.setAttribute('aria-selected', i === index ? 'true' : 'false'); });
      active = index;
      if (nodes[index]) {
        nodes[index].scrollIntoView({ block: 'nearest' });
      }
    }

    function choose(index) {
      if (items[index]) {
        options.select(items[index]);
        close();
      }
    }

    var run = S.debounce(function () {
      var term = input.value.trim();
      if (term.length < min) {
        close();
        return;
      }
      var current = ++requestId;
      options.search(term).then(function (result) {
        if (current !== requestId) {
          return;
        }
        items = result || [];
        list.innerHTML = items.length
          ? items.map(function (item, i) { return '<li role="option" id="' + list.id + '-' + i + '" data-index="' + i + '">' + options.render(item) + '</li>'; }).join('')
          : '<li class="snt-autocomplete__empty">' + S.escape(S.i18n.noResult || '—') + '</li>';
        list.hidden = false;
        input.setAttribute('aria-expanded', 'true');
        highlight(items.length ? 0 : -1);
      }).catch(function (e) { S.toast(e.message, 'error'); });
    }, 250);

    input.setAttribute('role', 'combobox');
    input.setAttribute('aria-expanded', 'false');
    input.addEventListener('input', run);
    input.addEventListener('keydown', function (e) {
      if (list.hidden) {
        return;
      }
      if (e.key === 'ArrowDown') { e.preventDefault(); highlight(Math.min(items.length - 1, active + 1)); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); highlight(Math.max(0, active - 1)); }
      else if (e.key === 'Enter') { e.preventDefault(); choose(active); }
      else if (e.key === 'Escape') { close(); }
    });
    list.addEventListener('mousedown', function (e) {
      var li = e.target.closest('[data-index]');
      if (li) {
        e.preventDefault();
        choose(Number(li.getAttribute('data-index')));
      }
    });
    input.addEventListener('blur', function () { setTimeout(close, 150); });

    return { close: close, clear: function () { input.value = ''; close(); } };
  };
})(window, document);
