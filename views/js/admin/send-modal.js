/**
 * Modale « Envoyer au client » (éditeur et fiche devis) : aperçu de l'e-mail, PDF, pièces jointes.
 * Usage : SntQuotes.openSendModal({ previewUrl, sendUrl, pdfUrl, to, subject, copyEmployee })
 */
(function (w, d) {
  'use strict';

  var S = w.SntQuotes = w.SntQuotes || {};

  S.openSendModal = function (config) {
    var dialog = d.getElementById('snt-dialog-send');
    if (!dialog) {
      return;
    }
    var form = dialog.querySelector('form');
    var iframe = dialog.querySelector('[data-send-preview]');
    var error = dialog.querySelector('[data-dialog-error]');
    var submit = dialog.querySelector('[data-send-submit]');
    var message = form.elements.message;

    form.reset();
    form.elements.to.value = config.to || '';
    form.elements.subject.value = config.subject || '';
    form.elements.copy_employee.checked = config.copyEmployee !== false;
    dialog.querySelector('[data-send-pdf]').href = config.pdfUrl || '#';
    error.textContent = '';
    iframe.srcdoc = '';

    function refreshPreview() {
      return S.api(config.previewUrl, { body: { message: message.value } }).then(function (data) {
        iframe.srcdoc = data.html || '';
        if (!form.elements.subject.value) {
          form.elements.subject.value = data.subject || '';
        }
        dialog.querySelector('[data-send-approval]').hidden = !data.requiresApproval;
        dialog.querySelector('[data-send-disabled]').hidden = data.enabled !== false;
      });
    }

    var debouncedPreview = S.debounce(function () {
      refreshPreview().catch(function (e) { error.textContent = e.message; });
    }, 600);

    message.oninput = debouncedPreview;

    form.onsubmit = function (event) {
      event.preventDefault();
      error.textContent = '';
      if (!form.elements.to.value.trim()) {
        error.textContent = form.elements.to.validationMessage || '';
        form.elements.to.focus();
        return;
      }
      var data = new FormData(form);
      if (!form.elements.copy_employee.checked) {
        data.set('copy_employee', '0');
      }
      submit.disabled = true;
      S.api(config.sendUrl, { formData: data }).then(function (result) {
        S.closeDialog(dialog);
        S.toast(result.message, result.result === 'sent' ? 'success' : 'warning');
        if (typeof config.onSent === 'function') {
          config.onSent(result);
        }
        if (result.redirect) {
          setTimeout(function () { w.location.href = result.redirect; }, 900);
        }
      }).catch(function (e) {
        error.textContent = e.message;
      }).then(function () {
        submit.disabled = false;
      });
    };

    S.openDialog(dialog);
    refreshPreview().catch(function (e) {
      error.textContent = e.message;
    });
  };

  // Fiche devis : boutons [data-send-config].
  d.addEventListener('click', function (event) {
    var button = event.target.closest('[data-send-config]');
    if (!button) {
      return;
    }
    event.preventDefault();
    try {
      S.openSendModal(JSON.parse(button.getAttribute('data-send-config')));
    } catch (e) {
      S.toast(e.message, 'error');
    }
  });
})(window, document);
