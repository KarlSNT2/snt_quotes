/**
 * Éditeur de devis (BO). Une seule page en sections + récapitulatif collant.
 *
 * Principe : le navigateur ne calcule rien de définitif. Chaque modification déclenche un
 * recalcul serveur (/api/recalculate) ; l'enregistrement renvoie aussi les montants du serveur.
 */
(function (w, d) {
  'use strict';

  var S = w.SntQuotes;
  var root = d.getElementById('snt-editor');
  if (!root || !S) {
    return;
  }

  var boot = JSON.parse(d.getElementById('snt-quote-bootstrap').textContent);
  S.i18n = JSON.parse(d.getElementById('snt-quote-i18n').textContent);
  S.csrf = boot.csrf;
  var t = S.i18n;
  var money = S.moneyFormatter(boot.locale, boot.currency.iso);
  var urls = boot.urls;
  var perms = boot.permissions;

  var uid = 0;
  var state = {
    quote: boot.quote,
    id: boot.quote.id_snt_quote || 0,
    customer: null,
    carriers: [],
    result: null,
    dirty: false,
    saving: false,
    lastSavedAt: null
  };
  state.quote.lines = (state.quote.lines || []).map(prepareLine);
  state.quote.vouchers = state.quote.vouchers || [];

  function prepareLine(line) {
    line._key = 'l' + (++uid);
    line._priceTouched = line.catalog_price_te !== null && line.catalog_price_te !== undefined
      && Math.abs(Number(line.unit_price_te) - Number(line.catalog_price_te)) > 0.000001;
    line._showComment = !!line.comment;
    return line;
  }

  function $(selector, scope) { return (scope || root).querySelector(selector); }
  function $all(selector, scope) { return Array.prototype.slice.call((scope || root).querySelectorAll(selector)); }

  /* ================================================================== */
  /* Payload, recalcul, enregistrement                                   */
  /* ================================================================== */

  function payload(extra) {
    var q = state.quote;
    var data = {
      id_snt_quote: state.id || null,
      id_customer: q.id_customer || 0,
      id_employee: q.id_employee || 0,
      id_currency: q.id_currency || boot.currency.id,
      id_address_delivery: q.id_address_delivery || 0,
      id_address_invoice: q.id_address_invoice || 0,
      project_name: q.project_name || '',
      id_cart_source: q.id_cart_source || 0,
      lines: q.lines.map(function (l) {
        return {
          line_type: l.line_type,
          id_product: l.id_product || 0,
          id_product_attribute: l.id_product_attribute || 0,
          label: l.label || '',
          reference: l.reference || '',
          qty: l.qty || 0,
          unit_price_te: l.unit_price_te === null || l.unit_price_te === undefined ? null : l.unit_price_te,
          reduction_type: l.reduction_value > 0 ? (l.reduction_type || 'percent') : null,
          reduction_value: l.reduction_value || 0,
          id_tax_rules_group: l.id_tax_rules_group || 0,
          comment: l.comment || ''
        };
      }),
      delivery_mode: q.delivery_mode || 'carrier',
      id_carrier: q.id_carrier || 0,
      carrier_label: q.carrier_label || '',
      shipping_te: q.shipping_te || 0,
      shipping_discount_te: q.shipping_discount_te || 0,
      free_shipping: !!q.free_shipping,
      id_tax_rules_group_shipping: q.id_tax_rules_group_shipping || 0,
      relay: q.relay || null,
      global_discount_type: q.global_discount_value > 0 ? (q.global_discount_type || 'percent') : '',
      global_discount_value: q.global_discount_value || 0,
      vouchers: q.vouchers,
      valid_until: q.valid_until || '',
      allowed_payment_methods: q.allowed_payment_methods || [],
      customer_message: q.customer_message || '',
      internal_note: q.internal_note || ''
    };
    return Object.assign(data, extra || {});
  }

  var recalcSeq = 0;
  function recalc() {
    if (!state.quote.id_customer) {
      renderTotals(null);
      return Promise.resolve();
    }
    var seq = ++recalcSeq;
    return S.api(urls.recalc, { body: payload() }).then(function (result) {
      if (seq === recalcSeq) {
        applyResult(result);
      }
    }).catch(function (e) {
      if (seq === recalcSeq) {
        showWarnings([{ message: e.message, error: true }]);
      }
    });
  }
  var recalcSoon = S.debounce(recalc, 350);

  function changed() {
    state.dirty = true;
    setAutosaveLabel(t.unsaved);
    recalcSoon();
  }

  function save(autosave) {
    if (state.saving) {
      return Promise.resolve(false);
    }
    if (!state.quote.id_customer) {
      if (!autosave) {
        S.toast(t.chooseCustomerFirst, 'error');
        $('#snt-customer-search').focus();
      }
      return Promise.resolve(false);
    }
    state.saving = true;
    setAutosaveLabel(t.saving);
    var wasNew = !state.id;
    return S.api(urls.save, { body: payload({ autosave: !!autosave }) }).then(function (result) {
      state.id = result.id_snt_quote;
      state.dirty = false;
      Object.assign(urls, result.urls || {});
      applyResult(result);
      setAutosaveLabel(S.format(t.saved, { '%time%': result.saved_at }));
      $('[data-ref]').textContent = result.reference;
      var pdf = $('[data-action=pdf]');
      pdf.hidden = false;
      pdf.href = urls.pdf;
      if (wasNew && w.history && w.history.replaceState) {
        w.history.replaceState({}, '', urls.edit);
      }
      if (!autosave && result.message) {
        S.toast(result.message, 'success');
      }
      return true;
    }).catch(function (e) {
      setAutosaveLabel(t.unsaved);
      S.toast(e.message, 'error');
      return false;
    }).then(function (ok) {
      state.saving = false;
      return ok;
    });
  }

  function setAutosaveLabel(text) {
    $('[data-autosave]').textContent = text || '';
  }

  setInterval(function () {
    if (state.dirty && state.quote.id_customer && !state.saving) {
      save(true);
    }
  }, 30000);

  w.addEventListener('beforeunload', function (e) {
    if (state.dirty) {
      e.preventDefault();
      e.returnValue = t.leave;
      return t.leave;
    }
    return undefined;
  });

  /* ================================================================== */
  /* Résultat du serveur → affichage                                     */
  /* ================================================================== */

  function applyResult(result) {
    state.result = result;
    (result.lines || []).forEach(function (calc, i) {
      var line = state.quote.lines[i];
      if (!line) {
        return;
      }
      line.tax_rate = calc.tax_rate;
      line.stock = calc.stock;
      line.catalog_price_te = calc.catalog_price_te;
      line.wholesale_price = calc.wholesale_price;
      if (!line.label && calc.label) {
        line.label = calc.label;
      }
      if (calc.reference && !line.reference) {
        line.reference = calc.reference;
      }
      if ((line.unit_price_te === null || line.unit_price_te === undefined) && calc.unit_price_te !== null) {
        line.unit_price_te = calc.unit_price_te;
        var priceInput = $('tr[data-key="' + line._key + '"] [data-l=unit_price_te]');
        if (priceInput && d.activeElement !== priceInput) {
          priceInput.value = S.numberInput(line.unit_price_te, 6);
        }
      }
      updateLineComputed(line, calc);
    });
    renderTotals(result.totals);
    showWarnings(result.warnings || []);
    $('[data-approval]').hidden = !(result.requires_approval && !result.can_approve);
    renderTaxInfo();
  }

  function updateLineComputed(line, calc) {
    var row = $('tr[data-key="' + line._key + '"]');
    if (!row) {
      return;
    }
    var set = function (sel, html) { var el = $(sel, row); if (el) { el.innerHTML = html; } };
    if (line.line_type === 'product' || line.line_type === 'free') {
      set('[data-c=net]', calc.unit_net_te === null ? '—' : money(calc.unit_net_te));
      set('[data-c=total]', calc.total_te === null ? '—' : '<strong>' + money(calc.total_te) + '</strong>');
      set('[data-c=tax]', calc.tax_rate === null ? '—' : String(calc.tax_rate).replace('.', ',') + ' %');
      var hints = [];
      if (line.line_type === 'product' && calc.catalog_price_te !== null && calc.catalog_price_te !== undefined) {
        hints.push(S.escape(S.format(t.catalogPrice, { '%price%': money(calc.catalog_price_te) })));
      }
      if (perms.see_margin && calc.wholesale_price) {
        hints.push(S.escape(S.format(t.purchasePrice, { '%price%': money(calc.wholesale_price) })));
        var marginAmount = (calc.unit_net_te - calc.wholesale_price) * line.qty;
        var rate = calc.unit_net_te > 0 ? ((calc.unit_net_te - calc.wholesale_price) / calc.unit_net_te * 100) : 0;
        hints.push(S.escape(S.format(t.margin, { '%amount%': money(marginAmount), '%rate%': rate.toFixed(1).replace('.', ',') + ' %' })));
      }
      set('[data-c=hints]', hints.join(' · '));
      row.classList.toggle('is-below-cost', !!calc.below_cost);
      var stock = $('[data-c=stock]', row);
      if (stock) {
        stock.hidden = line.line_type !== 'product' || calc.stock === null || calc.stock === undefined;
        stock.textContent = S.format(t.stock, { '%qty%': calc.stock });
        stock.classList.toggle('is-low', calc.stock !== null && calc.stock < line.qty);
      }
    }
  }

  function renderTotals(totals) {
    var box = $('[data-totals]');
    var set = function (key, value) { var el = $('[data-t=' + key + ']', box); if (el) { el.textContent = value; } };
    if (!totals) {
      ['products_te', 'discounts_te', 'shipping_te', 'total_te', 'total_ti'].forEach(function (k) { set(k, '—'); });
      $('[data-taxes]', box).innerHTML = '';
      return;
    }
    set('products_te', money(totals.products_te));
    $('[data-row=discounts]', box).hidden = !(totals.discounts_te > 0);
    set('discounts_te', '−' + money(totals.discounts_te));
    set('shipping_te', totals.shipping_te > 0 ? money(totals.shipping_te) : (totals.shipping_gross_te > 0 ? t.free : money(0)));
    set('total_te', money(totals.total_te));
    set('total_ti', money(totals.total_ti));
    $('[data-taxes]', box).innerHTML = (totals.tax_breakdown || []).map(function (tax) {
      return '<div class="snt-totals__tax"><dt>' + S.escape(t.vat) + ' ' + String(tax.rate).replace('.', ',') + ' %</dt><dd>' + money(tax.tax) + '</dd></div>';
    }).join('');

    var marginBox = $('[data-margin]');
    if (perms.see_margin && state.result && state.result.lines) {
      var margin = 0;
      var known = false;
      state.result.lines.forEach(function (l, i) {
        var line = state.quote.lines[i];
        if (line && l.wholesale_price && l.unit_net_te !== null) {
          margin += (l.unit_net_te - l.wholesale_price) * line.qty;
          known = true;
        }
      });
      marginBox.hidden = !known;
      marginBox.textContent = known ? S.format(t.margin, { '%amount%': money(margin), '%rate%': totals.products_te > 0 ? (margin / totals.products_te * 100).toFixed(1).replace('.', ',') + ' %' : '—' }) : '';
    }
  }

  function showWarnings(list) {
    $('[data-warnings]').innerHTML = list.map(function (w) {
      return '<li class="' + (w.error ? 'is-error' : '') + '">' + S.escape(w.message) + '</li>';
    }).join('');
  }

  /* ================================================================== */
  /* Section 1 — client                                                  */
  /* ================================================================== */

  var customerSearch = S.autocomplete($('#snt-customer-search'), $('#snt-customer-results'), {
    search: function (term) { return S.api(urls.customers, { query: { q: term } }).then(function (r) { return r.items; }); },
    render: function (c) {
      return '<strong>' + S.escape(c.company || c.name) + '</strong>'
        + (c.company ? ' — ' + S.escape(c.name) : '')
        + '<br><small class="text-muted">' + S.escape(c.email) + (c.siret ? ' · SIRET ' + S.escape(c.siret) : '') + '</small>';
    },
    select: function (c) { selectCustomer(c.id, true); }
  });

  function selectCustomer(idCustomer, isNewChoice) {
    return S.api(urls.customer, { query: { id: idCustomer } }).then(function (r) {
      var c = r.customer;
      state.customer = c;
      state.quote.id_customer = c.id;
      if (isNewChoice || !state.quote.id_address_delivery) {
        state.quote.id_address_delivery = c.addresses.length ? c.addresses[0].id : 0;
        state.quote.id_address_invoice = state.quote.id_address_delivery;
      }
      $('[data-customer-picker]').hidden = true;
      $('[data-customer-card]').hidden = false;
      $('[data-customer-name]').textContent = c.company ? c.company + ' — ' + c.name : c.name;
      $('[data-customer-meta]').textContent = [c.email, c.siret ? 'SIRET ' + c.siret : '', c.group].filter(Boolean).join(' · ');
      renderAddresses();
      renderCarts(r.carts || []);
      if (isNewChoice) {
        changed();
        loadCarriers();
        refreshTaxRules();
      }
      if (boot.import === 'cart' && r.carts && r.carts.length) {
        $('[data-carts]').scrollIntoView({ behavior: 'smooth', block: 'center' });
        boot.import = '';
      }
    }).catch(function (e) { S.toast(e.message, 'error'); });
  }

  function renderAddresses() {
    var c = state.customer;
    var options = c && c.addresses.length
      ? c.addresses.map(function (a) { return '<option value="' + a.id + '">' + S.escape(a.alias + ' — ' + a.label) + '</option>'; }).join('')
      : '<option value="0">' + S.escape(t.noAddress) + '</option>';
    var delivery = $('#snt-address-delivery');
    var invoice = $('#snt-address-invoice');
    delivery.innerHTML = options;
    invoice.innerHTML = options;
    delivery.value = String(state.quote.id_address_delivery || 0);
    invoice.value = String(state.quote.id_address_invoice || state.quote.id_address_delivery || 0);
    renderTaxInfo();
  }

  function currentAddress() {
    var c = state.customer;
    if (!c) {
      return null;
    }
    var id = Number(state.quote.id_address_delivery);
    return c.addresses.filter(function (a) { return a.id === id; })[0] || null;
  }

  function renderTaxInfo() {
    var info = $('[data-tax-info]');
    var address = currentAddress();
    var exempt = state.result && state.result.totals && state.result.totals.total_tax === 0 && address && address.vat_number;
    info.hidden = !exempt;
    info.textContent = exempt ? S.format(t.taxExempt, { '%vat%': address.vat_number }) : '';
  }

  function renderCarts(carts) {
    var box = $('[data-carts]');
    box.hidden = !carts.length;
    $('[data-carts-list]').innerHTML = carts.map(function (c) {
      return '<li><button type="button" class="btn btn-link btn-sm px-0" data-action="import-cart" data-cart="' + c.id + '">'
        + '<i class="material-icons" aria-hidden="true">shopping_cart</i> '
        + S.escape(S.format(t.cartLine, { '%date%': c.date, '%count%': c.nb_products })) + '</button></li>';
    }).join('');
  }

  function importCart(idCart) {
    S.api(urls.cartLines, { query: { id_cart: idCart, id_customer: state.quote.id_customer, id_address: state.quote.id_address_delivery, id_currency: state.quote.id_currency } })
      .then(function (r) {
        r.lines.forEach(function (l) { state.quote.lines.push(prepareLine(l)); });
        state.quote.id_cart_source = r.id_cart;
        renderLines();
        changed();
        loadCarriers();
        S.toast(S.format(t.cartImported, { '%count%': r.lines.length }), 'success');
      }).catch(function (e) { S.toast(e.message, 'error'); });
  }

  function renderEmployees() {
    var select = $('#snt-employee');
    select.innerHTML = boot.employees.map(function (e) {
      return '<option value="' + e.id + '">' + S.escape(e.name) + '</option>';
    }).join('');
    select.value = String(state.quote.id_employee || (boot.employees[0] && boot.employees[0].id) || '');
    state.quote.id_employee = Number(select.value) || 0;
  }

  /* ================================================================== */
  /* Section 2 — lignes                                                  */
  /* ================================================================== */

  S.autocomplete($('#snt-product-search'), $('#snt-product-results'), {
    search: function (term) {
      return S.api(urls.products, { query: { q: term, id_customer: state.quote.id_customer, id_address: state.quote.id_address_delivery, id_currency: state.quote.id_currency } })
        .then(function (r) { return r.items; });
    },
    render: function (p) {
      return '<span class="snt-product-option">'
        + (p.image ? '<img src="' + S.escape(p.image) + '" alt="" loading="lazy">' : '<span class="snt-product-option__noimg"></span>')
        + '<span><strong>' + S.escape(p.name) + '</strong>' + (p.active ? '' : ' <span class="badge badge-secondary">off</span>')
        + '<br><small class="text-muted">' + S.escape(p.reference) + (p.ean13 ? ' · ' + S.escape(p.ean13) : '')
        + ' · ' + S.escape(S.format(t.stock, { '%qty%': p.stock })) + '</small></span>'
        + '<span class="snt-product-option__price">' + money(p.price_te) + ' <small>' + S.escape(t.priceTe) + '</small></span></span>';
    },
    select: function (p) {
      addLine({
        line_type: 'product',
        id_product: p.id_product,
        id_product_attribute: p.id_product_attribute,
        label: p.name,
        reference: p.reference,
        qty: 1,
        unit_price_te: p.needs_combination ? null : p.price_te,
        catalog_price_te: p.price_te,
        reduction_type: 'percent',
        reduction_value: 0,
        stock: p.stock,
        wholesale_price: p.wholesale_price,
        needs_combination: p.needs_combination
      }, true);
      $('#snt-product-search').value = '';
      $('#snt-product-search').focus();
    }
  });

  function addLine(line, focusQty) {
    line = prepareLine(line);
    state.quote.lines.push(line);
    renderLines();
    changed();
    if (line.line_type === 'product') {
      loadCarriersSoon();
    }
    var row = $('tr[data-key="' + line._key + '"]');
    if (row) {
      var target = line.needs_combination ? $('[data-l=id_product_attribute]', row) : (focusQty ? $('[data-l=qty]', row) : $('input,textarea', row));
      if (target) {
        target.focus();
        if (target.select) { target.select(); }
      }
    }
    if (line.needs_combination) {
      loadCombinations(line);
    }
  }

  function loadCombinations(line) {
    S.api(urls.combinations, { query: { id_product: line.id_product } }).then(function (r) {
      line.combinations = r.items;
      renderLines();
    });
  }

  function lineRow(line, index) {
    var e = S.escape;
    var type = line.line_type;
    var actions = '<td class="snt-col-actions"><div class="snt-line-actions">'
      + '<button type="button" class="btn btn-link btn-sm" data-la="up" title="' + e(t.moveUp) + '" aria-label="' + e(t.moveUp) + '"' + (index === 0 ? ' disabled' : '') + '><i class="material-icons">arrow_upward</i></button>'
      + '<button type="button" class="btn btn-link btn-sm" data-la="down" title="' + e(t.moveDown) + '" aria-label="' + e(t.moveDown) + '"' + (index === state.quote.lines.length - 1 ? ' disabled' : '') + '><i class="material-icons">arrow_downward</i></button>'
      + '<button type="button" class="btn btn-link btn-sm" data-la="duplicate" title="' + e(t.duplicate) + '" aria-label="' + e(t.duplicate) + '"><i class="material-icons">content_copy</i></button>'
      + '<button type="button" class="btn btn-link btn-sm text-danger" data-la="remove" title="' + e(t.remove) + '" aria-label="' + e(t.remove) + '"><i class="material-icons">delete</i></button>'
      + '</div></td>';
    var handle = '<td class="snt-col-handle"><span class="snt-handle" draggable="true" title="' + e(t.drag) + '" aria-hidden="true"><i class="material-icons">drag_indicator</i></span></td>';

    if (type === 'section') {
      return '<tr class="snt-line snt-line--section" data-key="' + line._key + '">' + handle
        + '<td colspan="7"><input type="text" class="form-control snt-input-section" data-l="label" value="' + e(line.label) + '" placeholder="' + e(t.sectionLabel) + '" aria-label="' + e(t.sectionLabel) + '" maxlength="255"></td>'
        + actions + '</tr>';
    }
    if (type === 'comment') {
      return '<tr class="snt-line snt-line--comment" data-key="' + line._key + '">' + handle
        + '<td colspan="7"><textarea class="form-control" rows="2" data-l="comment" placeholder="' + e(t.commentLabel) + '" aria-label="' + e(t.commentLabel) + '">' + e(line.comment) + '</textarea></td>'
        + actions + '</tr>';
    }

    var description;
    if (type === 'free') {
      var taxOptions = boot.taxRules.map(function (r) {
        return '<option value="' + r.id + '"' + (Number(line.id_tax_rules_group) === r.id ? ' selected' : '') + '>' + e(r.name) + '</option>';
      }).join('');
      description = '<input type="text" class="form-control form-control-sm" data-l="label" value="' + e(line.label) + '" placeholder="' + e(t.freeLabel) + '" aria-label="' + e(t.freeLabel) + '" maxlength="255">'
        + '<div class="snt-line-sub"><input type="text" class="form-control form-control-sm snt-input-ref" data-l="reference" value="' + e(line.reference) + '" placeholder="' + e(t.reference) + '" aria-label="' + e(t.reference) + '" maxlength="64">'
        + '<select class="custom-select custom-select-sm" data-l="id_tax_rules_group" aria-label="' + e(t.vat) + '"><option value="0">' + e(t.vat) + ' 0 %</option>' + taxOptions + '</select></div>';
    } else {
      description = '<div class="snt-line-title">' + e(line.label) + '</div><div class="text-muted small">' + e(line.reference) + ' <span class="snt-stock" data-c="stock" hidden></span></div>';
      if (line.needs_combination || (line.combinations && line.combinations.length)) {
        var combos = (line.combinations || []).map(function (c) {
          return '<option value="' + c.id + '"' + (Number(line.id_product_attribute) === c.id ? ' selected' : '') + '>' + e(c.label + (c.reference ? ' (' + c.reference + ')' : '')) + '</option>';
        }).join('');
        description += '<select class="custom-select custom-select-sm mt-1" data-l="id_product_attribute" aria-label="' + e(t.chooseCombination) + '"><option value="0">' + e(t.chooseCombination) + '…</option>' + combos + '</select>';
      }
    }
    description += '<div class="snt-line-hints small" data-c="hints"></div>';
    description += line._showComment
      ? '<textarea class="form-control form-control-sm mt-1" rows="2" data-l="comment" placeholder="' + e(t.lineComment) + '" aria-label="' + e(t.lineComment) + '">' + e(line.comment) + '</textarea>'
      : '<button type="button" class="btn btn-link btn-sm px-0" data-la="comment">+ ' + e(t.addComment) + '</button>';

    var reductionType = line.reduction_type || 'percent';
    return '<tr class="snt-line snt-line--' + type + '" data-key="' + line._key + '">' + handle
      + '<td class="snt-col-desc">' + description + '</td>'
      + '<td class="snt-col-qty"><input type="number" min="1" step="1" class="form-control form-control-sm" data-l="qty" value="' + e(line.qty) + '" aria-label="Qty"></td>'
      + '<td class="snt-col-price"><input type="text" inputmode="decimal" class="form-control form-control-sm" data-l="unit_price_te" value="' + e(S.numberInput(line.unit_price_te, 6)) + '" aria-label="Unit price"></td>'
      + '<td class="snt-col-discount"><div class="input-group input-group-sm"><input type="text" inputmode="decimal" class="form-control" data-l="reduction_value" value="' + e(line.reduction_value > 0 ? S.numberInput(line.reduction_value, 2) : '') + '" aria-label="Discount">'
      + '<div class="input-group-append"><select class="custom-select snt-unit-select" data-l="reduction_type" aria-label="Discount type">'
      + '<option value="percent"' + (reductionType === 'percent' ? ' selected' : '') + '>%</option>'
      + '<option value="amount"' + (reductionType === 'amount' ? ' selected' : '') + '>' + e(boot.currency.sign) + '</option></select></div></div></td>'
      + '<td class="snt-col-amount text-right" data-c="net">—</td>'
      + '<td class="snt-col-tax text-right" data-c="tax">—</td>'
      + '<td class="snt-col-amount text-right" data-c="total">—</td>'
      + actions + '</tr>';
  }

  function renderLines() {
    var lines = state.quote.lines;
    $('[data-lines]').innerHTML = lines.map(lineRow).join('');
    $('[data-lines-empty]').hidden = lines.length > 0;
    if (state.result && state.result.lines) {
      lines.forEach(function (line, i) {
        if (state.result.lines[i]) {
          updateLineComputed(line, state.result.lines[i]);
        }
      });
    }
  }

  function lineFromEvent(event) {
    var row = event.target.closest('tr[data-key]');
    if (!row) {
      return null;
    }
    var key = row.getAttribute('data-key');
    for (var i = 0; i < state.quote.lines.length; i++) {
      if (state.quote.lines[i]._key === key) {
        return { line: state.quote.lines[i], index: i, row: row };
      }
    }
    return null;
  }

  var refreshPriceSoon = S.debounce(function (line) {
    // Prix dégressifs : si le prix n'a pas été modifié à la main, on reprend le prix catalogue pour la nouvelle quantité.
    if (line.line_type !== 'product' || line._priceTouched || !line.id_product) {
      return;
    }
    S.api(urls.productLine, { query: {
      id_product: line.id_product, id_product_attribute: line.id_product_attribute || 0, qty: line.qty,
      id_customer: state.quote.id_customer, id_address: state.quote.id_address_delivery, id_currency: state.quote.id_currency
    } }).then(function (r) {
      line.unit_price_te = r.line.price_te;
      line.catalog_price_te = r.line.price_te;
      if (r.line.reference) { line.reference = r.line.reference; }
      if (r.line.name) { line.label = r.line.name; }
      var input = $('tr[data-key="' + line._key + '"] [data-l=unit_price_te]');
      if (input && d.activeElement !== input) {
        input.value = S.numberInput(line.unit_price_te, 6);
      }
      changed();
    }).catch(function () { /* le serveur recalculera à l'enregistrement */ });
  }, 400);

  root.addEventListener('input', function (event) {
    var field = event.target.getAttribute('data-l');
    if (!field) {
      return;
    }
    var ctx = lineFromEvent(event);
    if (!ctx) {
      return;
    }
    var value = event.target.value;
    switch (field) {
      case 'qty':
        ctx.line.qty = Math.max(0, parseInt(value, 10) || 0);
        refreshPriceSoon(ctx.line);
        loadCarriersSoon();
        break;
      case 'unit_price_te':
        ctx.line.unit_price_te = S.parseNumber(value);
        ctx.line._priceTouched = true;
        break;
      case 'reduction_value':
        ctx.line.reduction_value = S.parseNumber(value);
        break;
      default:
        ctx.line[field] = value;
    }
    changed();
  });

  root.addEventListener('change', function (event) {
    var field = event.target.getAttribute('data-l');
    if (!field) {
      return;
    }
    var ctx = lineFromEvent(event);
    if (!ctx) {
      return;
    }
    if (field === 'id_product_attribute') {
      ctx.line.id_product_attribute = Number(event.target.value) || 0;
      ctx.line.needs_combination = !ctx.line.id_product_attribute;
      ctx.line._priceTouched = false;
      ctx.line.unit_price_te = null;
      ctx.line.label = '';
      refreshPriceSoon(ctx.line);
    } else if (field === 'reduction_type' || field === 'id_tax_rules_group') {
      ctx.line[field] = field === 'id_tax_rules_group' ? Number(event.target.value) : event.target.value;
    } else if (field === 'unit_price_te') {
      event.target.value = S.numberInput(ctx.line.unit_price_te, 6);
    }
    changed();
  });

  root.addEventListener('click', function (event) {
    var button = event.target.closest('[data-la]');
    if (!button) {
      return;
    }
    var ctx = lineFromEvent(event);
    if (!ctx) {
      return;
    }
    var lines = state.quote.lines;
    switch (button.getAttribute('data-la')) {
      case 'up':
        if (ctx.index > 0) { lines.splice(ctx.index - 1, 0, lines.splice(ctx.index, 1)[0]); }
        break;
      case 'down':
        if (ctx.index < lines.length - 1) { lines.splice(ctx.index + 1, 0, lines.splice(ctx.index, 1)[0]); }
        break;
      case 'duplicate':
        var copy = prepareLine(JSON.parse(JSON.stringify(ctx.line)));
        copy._priceTouched = ctx.line._priceTouched;
        lines.splice(ctx.index + 1, 0, copy);
        break;
      case 'remove':
        lines.splice(ctx.index, 1);
        loadCarriersSoon();
        break;
      case 'comment':
        ctx.line._showComment = true;
        break;
    }
    renderLines();
    changed();
    var focusKey = button.getAttribute('data-la') === 'comment' ? ctx.line._key : null;
    if (focusKey) {
      var area = $('tr[data-key="' + focusKey + '"] textarea[data-l=comment]');
      if (area) { area.focus(); }
    }
  });

  // Glisser-déposer (la poignée seule est « draggable » ; les boutons ↑/↓ restent l'alternative clavier).
  var dragKey = null;
  root.addEventListener('dragstart', function (event) {
    var row = event.target.closest('tr[data-key]');
    if (!row) { return; }
    dragKey = row.getAttribute('data-key');
    row.classList.add('is-dragging');
    event.dataTransfer.effectAllowed = 'move';
    event.dataTransfer.setData('text/plain', dragKey);
  });
  root.addEventListener('dragover', function (event) {
    if (dragKey && event.target.closest('tbody[data-lines] tr')) {
      event.preventDefault();
    }
  });
  root.addEventListener('drop', function (event) {
    var target = event.target.closest('tbody[data-lines] tr[data-key]');
    if (!dragKey || !target) { return; }
    event.preventDefault();
    var lines = state.quote.lines;
    var from = lines.findIndex(function (l) { return l._key === dragKey; });
    var to = lines.findIndex(function (l) { return l._key === target.getAttribute('data-key'); });
    if (from > -1 && to > -1 && from !== to) {
      lines.splice(to, 0, lines.splice(from, 1)[0]);
      renderLines();
      changed();
    }
  });
  root.addEventListener('dragend', function () {
    dragKey = null;
    $all('.is-dragging').forEach(function (r) { r.classList.remove('is-dragging'); });
  });

  /* ================================================================== */
  /* Section 3 — livraison                                               */
  /* ================================================================== */

  function carrierLines() {
    return state.quote.lines.filter(function (l) { return l.line_type === 'product' && l.id_product; }).map(function (l) {
      return { line_type: 'product', id_product: l.id_product, id_product_attribute: l.id_product_attribute || 0, qty: l.qty, unit_price_te: l.unit_price_te || 0 };
    });
  }

  function loadCarriers() {
    if (!state.quote.id_customer) {
      return;
    }
    S.api(urls.carriers, { body: { id_customer: state.quote.id_customer, id_address_delivery: state.quote.id_address_delivery, lines: carrierLines() } })
      .then(function (r) {
        state.carriers = r.items;
        renderCarriers();
      }).catch(function (e) { S.toast(e.message, 'error'); });
  }
  var loadCarriersSoon = S.debounce(loadCarriers, 800);

  function selectedCarrier() {
    var id = Number(state.quote.id_carrier);
    return state.carriers.filter(function (c) { return c.id === id; })[0] || null;
  }

  function renderCarriers() {
    var box = $('[data-carriers]');
    var warningText = { zone: t.warnZone, group: t.warnGroup, weight: t.warnWeight, price: t.warnPrice, max_weight: t.warnMaxWeight };
    box.innerHTML = state.carriers.map(function (c) {
      var checked = Number(state.quote.id_carrier) === c.id;
      return '<label class="snt-carrier' + (checked ? ' is-selected' : '') + (c.eligible ? '' : ' is-out') + '">'
        + '<input type="radio" name="snt_carrier" value="' + c.id + '"' + (checked ? ' checked' : '') + '>'
        + (c.logo ? '<img src="' + S.escape(c.logo) + '" alt="" class="snt-carrier__logo">' : '<span class="snt-carrier__logo snt-carrier__logo--none"><i class="material-icons" aria-hidden="true">local_shipping</i></span>')
        + '<span class="snt-carrier__text"><strong>' + S.escape(c.name) + '</strong>'
        + (c.delay ? '<br><small class="text-muted">' + S.escape(c.delay) + '</small>' : '')
        + (c.eligible ? '' : '<br><small class="snt-carrier__out"><i class="material-icons" aria-hidden="true">info</i> ' + S.escape(warningText[c.warnings[0]] || '') + '</small>')
        + '</span></label>';
    }).join('');
    var carrier = selectedCarrier();
    var warning = $('[data-carrier-warning]');
    if (carrier && !carrier.eligible) {
      warning.hidden = false;
      warning.textContent = carrier.warnings.map(function (w) { return warningText[w]; }).filter(Boolean).join(' · ') + ' ' + t.warnSuffix;
    } else {
      warning.hidden = true;
    }
    renderRelay();
  }

  function renderRelay() {
    var carrier = selectedCarrier();
    var box = $('[data-relay]');
    var isRelay = !!(carrier && carrier.is_relay && state.quote.delivery_mode === 'carrier');
    box.hidden = !isRelay;
    if (!isRelay) {
      return;
    }
    var selected = $('[data-relay-selected]');
    var relay = state.quote.relay;
    selected.hidden = !relay;
    selected.innerHTML = relay
      ? '<i class="material-icons" aria-hidden="true">place</i> <strong>' + S.escape(relay.name) + '</strong>, ' + S.escape(relay.address) + ', ' + S.escape(relay.zip) + ' ' + S.escape(relay.city)
      : '';
    var address = currentAddress();
    var input = $('#snt-relay-postcode');
    if (!input.value && address) {
      input.value = address.postcode;
    }
  }

  function searchRelays() {
    var carrier = selectedCarrier();
    var address = currentAddress();
    if (!carrier) {
      return;
    }
    var list = $('[data-relay-list]');
    S.api(urls.relays, { query: { id_carrier: carrier.id, postcode: $('#snt-relay-postcode').value.trim(), country: address ? address.country_iso : 'FR' } })
      .then(function (r) {
        list.innerHTML = r.items.length ? r.items.map(function (p, i) {
          return '<li><button type="button" class="btn btn-link btn-sm text-left" data-relay-index="' + i + '"><strong>' + S.escape(p.name) + '</strong> — '
            + S.escape(p.address + ', ' + p.zip + ' ' + p.city) + (p.distance_m ? ' <small class="text-muted">(' + (p.distance_m / 1000).toFixed(1).replace('.', ',') + ' km)</small>' : '') + '</button></li>';
        }).join('') : '<li class="text-muted">' + S.escape(t.noResult) + '</li>';
        list._items = r.items;
      }).catch(function (e) { S.toast(e.message, 'error'); });
  }

  $('[data-carriers]').addEventListener('change', function (event) {
    if (event.target.name !== 'snt_carrier') { return; }
    state.quote.id_carrier = Number(event.target.value);
    state.quote.relay = null;
    $('[data-relay-list]').innerHTML = '';
    renderCarriers();
    changed();
  });

  $('[data-relay-list]').addEventListener('click', function (event) {
    var button = event.target.closest('[data-relay-index]');
    var list = $('[data-relay-list]');
    if (!button || !list._items) { return; }
    state.quote.relay = list._items[Number(button.getAttribute('data-relay-index'))];
    list.innerHTML = '';
    renderRelay();
    changed();
  });

  function renderDeliveryMode() {
    var mode = state.quote.delivery_mode || 'carrier';
    $all('input[name=delivery_mode]').forEach(function (r) { r.checked = r.value === mode; });
    $('[data-carrier-block]').hidden = mode === 'pickup';
    $('[data-special-label]').hidden = mode !== 'special';
    $('[data-shipping-block]').hidden = mode === 'pickup';
    renderRelay();
  }

  function calculatedShipping() {
    if (!state.quote.id_carrier) {
      S.toast(t.chooseCarrierFirst, 'error');
      return;
    }
    S.api(urls.shipping, { body: {
      id_carrier: state.quote.id_carrier, id_customer: state.quote.id_customer,
      id_address_delivery: state.quote.id_address_delivery, id_address_invoice: state.quote.id_address_invoice,
      id_currency: state.quote.id_currency, lines: carrierLines()
    } }).then(function (r) {
      state.quote.shipping_te = r.shipping_te;
      $('#snt-shipping').value = S.numberInput(r.shipping_te, 2);
      S.toast(S.format(t.calculatedRate, { '%price%': money(r.shipping_te) }), 'success');
      changed();
    }).catch(function (e) { S.toast(e.message, 'warning'); });
  }

  /* ================================================================== */
  /* Section 4 — remises                                                 */
  /* ================================================================== */

  S.autocomplete($('#snt-voucher-search'), $('#snt-voucher-results'), {
    search: function (term) { return S.api(urls.vouchers, { query: { q: term, id_customer: state.quote.id_customer } }).then(function (r) { return r.items; }); },
    render: function (v) {
      var value = v.reduction_type === 'percent' ? v.reduction_value + ' %' : money(v.reduction_value) + (v.tax_included ? ' ' + t.voucherTaxIncl : '');
      return '<strong>' + S.escape(v.code || v.label) + '</strong> — ' + S.escape(v.label) + ' (' + S.escape(value) + ')'
        + (v.valid ? '' : '<br><small class="text-danger">' + S.escape(t.voucherInvalid) + '</small>');
    },
    select: function (v) {
      if (!v.valid) {
        S.toast(t.voucherInvalid, 'error');
        return;
      }
      if (state.quote.vouchers.some(function (x) { return x.id_cart_rule === v.id_cart_rule; })) {
        return;
      }
      state.quote.vouchers.push({ id_cart_rule: v.id_cart_rule, code: v.code, label: v.label, reduction_type: v.reduction_type, reduction_value: v.reduction_value });
      renderVouchers();
      changed();
      $('#snt-voucher-search').value = '';
    }
  });

  function renderVouchers() {
    $('[data-vouchers]').innerHTML = state.quote.vouchers.map(function (v, i) {
      var value = v.reduction_type === 'percent' ? String(v.reduction_value).replace('.', ',') + ' %' : money(v.reduction_value);
      return '<li class="snt-voucher"><i class="material-icons" aria-hidden="true">' + (v.id_cart_rule ? 'confirmation_number' : 'redeem') + '</i> '
        + '<span><strong>' + S.escape(v.code || t.dedicated) + '</strong> — ' + S.escape(v.label) + ' (' + S.escape(value) + ')'
        + (v.date_to ? ' · ' + S.escape(v.date_to) : '') + '</span>'
        + '<button type="button" class="btn btn-link btn-sm text-danger" data-voucher-remove="' + i + '" aria-label="' + S.escape(t.remove) + '"><i class="material-icons">close</i></button></li>';
    }).join('');
  }

  $('[data-vouchers]').addEventListener('click', function (event) {
    var button = event.target.closest('[data-voucher-remove]');
    if (!button) { return; }
    state.quote.vouchers.splice(Number(button.getAttribute('data-voucher-remove')), 1);
    renderVouchers();
    changed();
  });

  function addDedicated() {
    var value = S.parseNumber($('#snt-ded-value').value);
    if (value <= 0) {
      S.toast(t.dedicatedValue, 'error');
      $('#snt-ded-value').focus();
      return;
    }
    state.quote.vouchers.push({
      id_cart_rule: 0,
      is_dedicated: true,
      label: $('#snt-ded-label').value.trim() || $('#snt-ded-label').placeholder,
      reduction_type: $('#snt-ded-type').value,
      reduction_value: value,
      date_to: $('#snt-ded-date').value || null,
      minimum_amount: S.parseNumber($('#snt-ded-min').value)
    });
    ['#snt-ded-label', '#snt-ded-value', '#snt-ded-date', '#snt-ded-min'].forEach(function (s) { $(s).value = ''; });
    renderVouchers();
    changed();
  }

  /* ================================================================== */
  /* Champs simples (data-field)                                         */
  /* ================================================================== */

  function fieldValue(el) {
    if (el.type === 'checkbox') { return el.checked; }
    if (el.hasAttribute('data-money')) { return S.parseNumber(el.value); }
    if (el.tagName === 'SELECT' && /^id_/.test(el.getAttribute('data-field'))) { return Number(el.value) || 0; }
    return el.value;
  }

  function fillFields() {
    $all('[data-field]').forEach(function (el) {
      var key = el.getAttribute('data-field');
      var value = state.quote[key];
      if (el.type === 'radio') {
        el.checked = el.value === (value || 'carrier');
      } else if (el.type === 'checkbox') {
        el.checked = !!value;
      } else if (el.hasAttribute('data-money')) {
        el.value = value ? S.numberInput(value, 2) : '';
      } else if (value !== undefined && value !== null) {
        el.value = value;
      }
    });
    $all('[data-currency-sign]').forEach(function (el) { el.textContent = boot.currency.sign; });
    $all('[data-currency-option]').forEach(function (el) { el.textContent = boot.currency.sign; });
    if (!state.quote.global_discount_type) {
      $('[data-field=global_discount_type]').value = 'percent';
    }
  }

  root.addEventListener('input', function (event) {
    var key = event.target.getAttribute('data-field');
    if (!key || event.target.type === 'radio' || event.target.tagName === 'SELECT') { return; }
    state.quote[key] = fieldValue(event.target);
    changed();
  });

  root.addEventListener('change', function (event) {
    var key = event.target.getAttribute('data-field');
    if (!key) { return; }
    state.quote[key] = fieldValue(event.target);
    if (event.target.hasAttribute('data-money')) {
      event.target.value = state.quote[key] ? S.numberInput(state.quote[key], 2) : '';
    }
    if (key === 'id_address_delivery') {
      renderTaxInfo();
      loadCarriers();
      refreshTaxRules();
    }
    if (key === 'delivery_mode') {
      renderDeliveryMode();
    }
    changed();
  });

  function refreshTaxRules() {
    S.api(urls.taxRules, { query: { id_address: state.quote.id_address_delivery } }).then(function (r) {
      boot.taxRules = r.items;
      if (state.quote.lines.some(function (l) { return l.line_type === 'free'; })) {
        renderLines();
      }
    }).catch(function () { /* non bloquant */ });
  }

  function renderPaymentMethods() {
    var box = $('[data-payment-methods]');
    var selected = state.quote.allowed_payment_methods || [];
    box.innerHTML = boot.paymentMethods.map(function (m) {
      return '<label class="snt-check"><input type="checkbox" value="' + m + '"' + (selected.indexOf(m) > -1 ? ' checked' : '') + '> ' + S.escape(t['payment_' + m] || m) + '</label>';
    }).join('');
    box.addEventListener('change', function () {
      state.quote.allowed_payment_methods = $all('input:checked', box).map(function (i) { return i.value; });
      changed();
    });
  }

  /* ================================================================== */
  /* Boutons et modales                                                  */
  /* ================================================================== */

  root.addEventListener('click', function (event) {
    var button = event.target.closest('[data-action]');
    if (!button) { return; }
    var action = button.getAttribute('data-action');
    switch (action) {
      case 'change-customer':
        $('[data-customer-picker]').hidden = false;
        $('[data-customer-card]').hidden = true;
        $('#snt-customer-search').focus();
        break;
      case 'open-quick-customer':
        S.openDialog(d.getElementById('snt-dialog-customer'));
        break;
      case 'open-quick-address':
        if (!state.quote.id_customer) { S.toast(t.chooseCustomerFirst, 'error'); break; }
        S.openDialog(d.getElementById('snt-dialog-address'));
        break;
      case 'import-cart':
        importCart(Number(button.getAttribute('data-cart')));
        break;
      case 'add-free':
        addLine({ line_type: 'free', label: '', reference: '', qty: 1, unit_price_te: 0, reduction_type: 'percent', reduction_value: 0, id_tax_rules_group: boot.taxRules.length ? boot.taxRules[0].id : 0 });
        break;
      case 'add-section':
        addLine({ line_type: 'section', label: '', qty: 0 });
        break;
      case 'add-comment':
        addLine({ line_type: 'comment', label: '', comment: '', qty: 0 });
        break;
      case 'open-bulk':
        if (!state.quote.id_customer) { S.toast(t.chooseCustomerFirst, 'error'); break; }
        d.querySelector('[data-bulk-errors]').innerHTML = '';
        S.openDialog(d.getElementById('snt-dialog-bulk'));
        break;
      case 'search-relays':
        searchRelays();
        break;
      case 'calculated-shipping':
        calculatedShipping();
        break;
      case 'add-dedicated':
        addDedicated();
        break;
      case 'save':
        save(false);
        break;
      case 'send':
        openSend();
        break;
      case 'pdf':
        if (state.dirty) {
          event.preventDefault();
          save(false).then(function (ok) { if (ok) { w.open(urls.pdf, '_blank', 'noopener'); } });
        }
        break;
    }
  });

  function openSend() {
    var hasAmount = state.quote.lines.some(function (l) { return l.line_type === 'product' || l.line_type === 'free'; });
    if (!hasAmount) {
      S.toast(t.addLinesFirst, 'error');
      return;
    }
    save(false).then(function (ok) {
      if (!ok) { return; }
      state.dirty = false;
      S.openSendModal({
        previewUrl: urls.sendPreview,
        sendUrl: urls.send,
        pdfUrl: urls.pdf,
        to: state.customer ? state.customer.email : '',
        copyEmployee: true,
        onSent: function () { state.dirty = false; }
      });
    });
  }

  d.querySelector('[data-form=quick-customer]').addEventListener('submit', function (event) {
    event.preventDefault();
    var form = event.target;
    var body = {};
    new FormData(form).forEach(function (v, k) { body[k] = v; });
    S.api(urls.quickCustomer, { body: body }).then(function (r) {
      S.closeDialog(form.closest('dialog'));
      form.reset();
      S.toast(t.customerCreated, 'success');
      selectCustomer(r.customer.id, true).then(function () {
        if (!r.customer.addresses.length) {
          S.openDialog(d.getElementById('snt-dialog-address'));
        }
      });
    }).catch(function (e) { form.querySelector('[data-dialog-error]').textContent = e.message; });
  });

  d.querySelector('[data-form=quick-address]').addEventListener('submit', function (event) {
    event.preventDefault();
    var form = event.target;
    var body = { id_customer: state.quote.id_customer };
    new FormData(form).forEach(function (v, k) { body[k] = v; });
    S.api(urls.quickAddress, { body: body }).then(function (r) {
      S.closeDialog(form.closest('dialog'));
      form.reset();
      state.customer = r.customer;
      state.quote.id_address_delivery = r.id_address;
      if (!state.quote.id_address_invoice) { state.quote.id_address_invoice = r.id_address; }
      renderAddresses();
      loadCarriers();
      refreshTaxRules();
      changed();
      S.toast(t.addressCreated, 'success');
    }).catch(function (e) { form.querySelector('[data-dialog-error]').textContent = e.message; });
  });

  d.querySelector('[data-form=bulk]').addEventListener('submit', function (event) {
    event.preventDefault();
    var form = event.target;
    var data = new FormData(form);
    var url = urls.bulkLines + (urls.bulkLines.indexOf('?') === -1 ? '?' : '&')
      + 'id_customer=' + state.quote.id_customer + '&id_address=' + state.quote.id_address_delivery + '&id_currency=' + state.quote.id_currency;
    S.api(url, { formData: data }).then(function (r) {
      r.lines.forEach(function (l) { state.quote.lines.push(prepareLine(l)); });
      renderLines();
      changed();
      loadCarriers();
      var errors = form.querySelector('[data-bulk-errors]');
      if (r.errors && r.errors.length) {
        errors.innerHTML = '<ul class="text-danger">' + r.errors.map(function (m) { return '<li>' + S.escape(m) + '</li>'; }).join('') + '</ul>';
      } else {
        S.closeDialog(form.closest('dialog'));
        form.reset();
      }
      S.toast(S.format(t.bulkImported, { '%count%': r.lines.length }), 'success');
    }).catch(function (e) { form.querySelector('[data-bulk-errors]').textContent = e.message; });
  });

  /* ================================================================== */
  /* Démarrage                                                           */
  /* ================================================================== */

  renderEmployees();
  fillFields();
  renderLines();
  renderVouchers();
  renderPaymentMethods();
  renderDeliveryMode();
  if (state.id) {
    var pdfLink = $('[data-action=pdf]');
    pdfLink.hidden = false;
    pdfLink.href = urls.pdf;
  }
  if (state.quote.id_customer) {
    selectCustomer(state.quote.id_customer, false).then(function () {
      loadCarriers();
      recalc();
    });
  } else {
    $('#snt-customer-search').focus();
  }
})(window, document);
