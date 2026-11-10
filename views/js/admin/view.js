/**
 * Fiche devis : conversion en commande et liens de paiement.
 */
(function (w, d) {
  'use strict';

  var S = w.SntQuotes;
  var node = d.getElementById('snt-view-config');
  if (!S || !node) {
    return;
  }
  var cfg = JSON.parse(node.textContent);
  S.i18n = Object.assign(S.i18n || {}, cfg.i18n);

  /* --- Conversion -------------------------------------------------- */

  var convertDialog = d.getElementById('snt-dialog-convert');
  var preview = null;

  function selectedMode() {
    var checked = convertDialog.querySelector('input[name=convert_mode]:checked');
    return checked ? checked.value : '';
  }

  function syncState() {
    var mode = (preview.modes || []).filter(function (m) { return m.value === selectedMode(); })[0];
    var block = convertDialog.querySelector('[data-convert-state-block]');
    var select = convertDialog.querySelector('[data-convert-state]');
    // PayPlug impose son propre statut (il déclenche l'envoi du lien).
    block.hidden = !mode || mode.value === 'payplug_link';
    if (mode && mode.default_state) {
      select.value = String(mode.default_state);
    }
  }

  function openConvert() {
    var error = convertDialog.querySelector('[data-dialog-error]');
    S.openDialog(convertDialog);
    convertDialog.querySelector('[data-convert-summary]').textContent = '…';
    S.api(cfg.urls.preview).then(function (data) {
      preview = data;
      convertDialog.querySelector('[data-convert-summary]').textContent = data.summary;
      convertDialog.querySelector('[data-convert-stock]').innerHTML = (data.stock || []).map(function (m) {
        return '<li>' + S.escape(m) + '</li>';
      }).join('');
      var firstAllowed = (data.modes.filter(function (m) { return m.allowed; })[0] || data.modes[0] || {}).value;
      convertDialog.querySelector('[data-convert-modes]').innerHTML = data.modes.map(function (m) {
        return '<label class="snt-mode"><input type="radio" name="convert_mode" value="' + S.escape(m.value) + '"' + (m.value === firstAllowed ? ' checked' : '') + '> '
          + '<span><strong>' + S.escape(m.label) + '</strong>' + (m.allowed ? '' : ' <small class="text-muted">(' + S.escape(S.i18n.notAllowedOnQuote) + ')</small>')
          + (m.help ? '<br><small class="text-muted">' + S.escape(m.help) + '</small>' : '') + '</span></label>';
      }).join('');
      convertDialog.querySelector('[data-convert-state]').innerHTML = data.states.map(function (s) {
        return '<option value="' + s.id + '">' + S.escape(s.name) + '</option>';
      }).join('');
      convertDialog.querySelector('[data-convert-agreed-block]').hidden = !data.needs_acceptance;
      syncState();
    }).catch(function (e) { error.textContent = e.message; });
  }

  if (convertDialog) {
    convertDialog.addEventListener('change', function (e) {
      if (e.target.name === 'convert_mode') {
        syncState();
      }
    });
    convertDialog.querySelector('form').addEventListener('submit', function (e) {
      e.preventDefault();
      var error = convertDialog.querySelector('[data-dialog-error]');
      var submit = convertDialog.querySelector('[data-convert-submit]');
      error.textContent = '';
      if (!selectedMode()) {
        error.textContent = S.i18n.chooseMode;
        return;
      }
      submit.disabled = true;
      S.api(cfg.urls.convert, { body: {
        mode: selectedMode(),
        id_order_state: Number(convertDialog.querySelector('[data-convert-state]').value) || 0,
        comment: convertDialog.querySelector('[data-convert-comment]').value,
        customer_agreed: convertDialog.querySelector('[data-convert-agreed]').checked
      } }).then(function (r) {
        S.closeDialog(convertDialog);
        S.toast(r.message, (r.warnings || []).length ? 'warning' : 'success');
        setTimeout(function () { w.location.href = r.redirect; }, 1200);
      }).catch(function (err) {
        error.textContent = err.message;
        submit.disabled = false;
      });
    });
  }

  /* --- Lien de paiement -------------------------------------------- */

  var linkDialog = d.getElementById('snt-dialog-paylink');

  function openPaylink() {
    var form = linkDialog.querySelector('form');
    form.reset();
    form.elements.to.value = cfg.customerEmail || '';
    linkDialog.querySelector('[data-paylink-create]').hidden = false;
    linkDialog.querySelector('[data-paylink-result]').hidden = true;
    linkDialog.querySelector('[data-paylink-submit]').hidden = false;
    linkDialog.querySelector('[data-paylink-mail]').hidden = false;
    S.openDialog(linkDialog);
  }

  if (linkDialog) {
    linkDialog.querySelector('[data-paylink-send]').addEventListener('change', function (e) {
      linkDialog.querySelector('[data-paylink-mail]').hidden = !e.target.checked;
    });
    linkDialog.querySelector('form').addEventListener('submit', function (e) {
      e.preventDefault();
      var form = e.target;
      var error = linkDialog.querySelector('[data-dialog-error]');
      var submit = linkDialog.querySelector('[data-paylink-submit]');
      error.textContent = '';
      submit.disabled = true;
      S.api(cfg.urls.paymentLink, { body: {
        send: form.elements.send.checked,
        to: form.elements.to.value,
        message: form.elements.message.value
      } }).then(function (r) {
        linkDialog.querySelector('[data-paylink-create]').hidden = true;
        linkDialog.querySelector('[data-paylink-result]').hidden = false;
        linkDialog.querySelector('[data-paylink-url]').value = r.url;
        linkDialog.querySelector('[data-paylink-expires]').textContent = r.message;
        submit.hidden = true;
        S.toast(r.message, 'success');
      }).catch(function (err) {
        error.textContent = err.message;
      }).then(function () { submit.disabled = false; });
    });
    linkDialog.querySelector('[data-paylink-copy]').addEventListener('click', function () {
      var input = linkDialog.querySelector('[data-paylink-url]');
      input.select();
      (navigator.clipboard ? navigator.clipboard.writeText(input.value) : Promise.reject()).then(function () {
        S.toast(S.i18n.copied, 'success');
      }, function () {
        d.execCommand('copy');
        S.toast(S.i18n.copied, 'success');
      });
    });
    linkDialog.addEventListener('close', function () {
      if (!linkDialog.querySelector('[data-paylink-result]').hidden) {
        w.location.reload();
      }
    });
  }

  d.addEventListener('click', function (e) {
    if (e.target.closest('[data-open-convert]') && convertDialog) {
      openConvert();
    } else if (e.target.closest('[data-open-paylink]') && linkDialog) {
      openPaylink();
    } else if (e.target.closest('[data-revoke-link]')) {
      if (!w.confirm(S.i18n.confirmRevoke)) {
        return;
      }
      S.api(cfg.urls.revokeLink, { body: {} }).then(function (r) {
        S.toast(r.message, 'success');
        setTimeout(function () { w.location.reload(); }, 800);
      }).catch(function (err) { S.toast(err.message, 'error'); });
    }
  });
})(window, document);
