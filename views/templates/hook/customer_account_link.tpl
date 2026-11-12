{**
 * Lien « Mes devis » dans « Mon compte » (même structure que les liens du thème classique / ZOne).
 *}
<a class="col-lg-4 col-md-6 col-sm-6 col-xs-12" id="snt-quotes-link" href="{$snt_quotes_url}">
  <span class="link-item">
    <i class="material-icons" aria-hidden="true">request_quote</i>
    {l s='My quotes' d='Modules.Sntquotes.Shop'}
    {if $snt_quotes_awaiting > 0}<span class="badge badge-primary" title="{l s='Waiting for your answer' d='Modules.Sntquotes.Shop'}">{$snt_quotes_awaiting}</span>{/if}
  </span>
</a>
