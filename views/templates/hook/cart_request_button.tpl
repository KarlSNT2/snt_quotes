{**
 * Bouton « Demander un devis » sous « Commander » (hook displayExpressCheckout).
 * Styles en ligne : la feuille du module n'est pas chargée sur le panier.
 * Surcharge : themes/<thème>/modules/snt_quotes/views/templates/hook/cart_request_button.tpl
 *}
<div class="snt-cart-request" style="margin-top: .75rem;">
  <a href="{$snt_request_url}" class="btn btn-secondary btn-block snt-cart-request__btn" rel="nofollow" style="display: flex; align-items: center; justify-content: center; gap: .4rem; width: 100%;">
    <i class="material-icons" aria-hidden="true">request_quote</i>
    {l s='Request a quote' d='Modules.Sntquotes.Shop'}
  </a>
  <p class="snt-cart-request__help text-muted" style="font-size: .8125rem; margin: .4rem 0 0; text-align: center;">{l s='Large order, special delivery? Our team prepares a quote for you. Your cart is kept.' d='Modules.Sntquotes.Shop'}</p>
</div>
