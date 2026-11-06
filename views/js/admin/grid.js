/**
 * Liste des devis : initialisation de la Grid PrestaShop (composants exposés par le BO, PS 8 et 9).
 */
(function () {
  'use strict';

  function init() {
    var ps = window.prestashop && window.prestashop.component;
    if (!ps) {
      return;
    }
    if (typeof ps.initComponents === 'function' && !ps.Grid) {
      try { ps.initComponents(['Grid']); } catch (e) { /* composant déjà initialisé */ }
    }
    var Grid = ps.Grid;
    var E = ps.GridExtensions || {};
    if (!Grid) {
      return;
    }
    var grid = new Grid('snt_quote');
    [
      'ExportToSqlManagerExtension',
      'ReloadListExtension',
      'SortingExtension',
      'FiltersResetExtension',
      'ColumnTogglingExtension',
      'SubmitRowActionExtension',
      'SubmitBulkActionExtension',
      'BulkActionCheckboxExtension',
      'LinkRowActionExtension',
      'FiltersSubmitButtonEnablerExtension'
    ].forEach(function (name) {
      if (typeof E[name] === 'function') {
        grid.addExtension(new E[name]());
      }
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
