{**
 * Fiche commande (BO) : encart « Issue du devis ».
 *}
<div class="card snt-order-quote">
  <div class="card-body d-flex align-items-center">
    <i class="material-icons mr-2" aria-hidden="true">request_quote</i>
    <span>
      {l s='Created from quote' d='Modules.Sntquotes.Admin'}
      <a href="{$snt_quote_url|escape:'html':'UTF-8'}"><strong>{$snt_quote.reference|escape:'html':'UTF-8'}{if $snt_quote.version > 1} v{$snt_quote.version|intval}{/if}</strong></a>
    </span>
  </div>
</div>
