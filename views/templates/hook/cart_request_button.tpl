{**
 * Bouton « Demander un devis » sous « Commander » (hook displayExpressCheckout).
 * Même gabarit que « Commander » : largeur automatique, centré (Classic et ZOne).
 * Styles en ligne : la feuille du module n'est pas chargée sur le panier.
 * Surcharge : themes/<thème>/modules/snt_quotes/views/templates/hook/cart_request_button.tpl
 *}
<div class="snt-cart-request" style="margin-top: .75rem; text-align: center;">
  <a href="{$snt_request_url}" class="btn btn-secondary snt-cart-request__btn" rel="nofollow" style="display: inline-flex; align-items: center; justify-content: center; gap: .4rem;">
    <i class="material-icons" aria-hidden="true">request_quote</i>
    {l s='Request a quote' d='Modules.Sntquotes.Shop'}
  </a>
</div>
