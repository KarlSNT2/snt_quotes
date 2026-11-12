{**
 * Récapitulatif figé d'un devis côté client (page de paiement et espace « Mes devis »).
 * $snt_quote : vue de QuotePresenter ; $snt_te_first : prix HT en premier (réglage « Affichage des prix »).
 * Surcharge : themes/<thème>/modules/snt_quotes/views/templates/front/_quote-summary.tpl
 *}
<section class="snt-pay__quote card" aria-labelledby="snt-quote-summary">
  <div class="card-block card-body">
    <div class="snt-pay__head">
      <h2 id="snt-quote-summary" class="h4">{l s='Quote summary' d='Modules.Sntquotes.Shop'}</h2>
      <span class="snt-badge{if $snt_quote.status.strike} snt-badge--strike{/if}" style="background: {$snt_quote.status.color};">{$snt_quote.status.label}</span>
    </div>
    <p class="snt-pay__meta">
      {if $snt_quote.project_name}<strong>{$snt_quote.project_name}</strong><br>{/if}
      {if $snt_quote.valid_until_display}{l s='Valid until %date%' sprintf=['%date%' => $snt_quote.valid_until_display] d='Modules.Sntquotes.Shop'}{/if}
      {if $snt_quote.employee} · {l s='Your contact: %name%' sprintf=['%name%' => $snt_quote.employee.name] d='Modules.Sntquotes.Shop'}{/if}
    </p>

    <div class="table-responsive">
      <table class="table snt-pay__lines">
        <thead>
          <tr>
            <th scope="col">{l s='Description' d='Modules.Sntquotes.Shop'}</th>
            <th scope="col" class="text-center">{l s='Qty' d='Modules.Sntquotes.Shop'}</th>
            {if $snt_te_first}
              <th scope="col" class="text-right">{l s='Unit price excl. tax' d='Modules.Sntquotes.Shop'}</th>
              <th scope="col" class="text-right">{l s='Total excl. tax' d='Modules.Sntquotes.Shop'}</th>
            {else}
              <th scope="col" class="text-right">{l s='Unit price incl. tax' d='Modules.Sntquotes.Shop'}</th>
              <th scope="col" class="text-right">{l s='Total incl. tax' d='Modules.Sntquotes.Shop'}</th>
            {/if}
          </tr>
        </thead>
        <tbody>
          {foreach $snt_quote.lines as $line}
            {if $line.type == 'section'}
              <tr class="snt-pay__section"><td colspan="4"><strong>{$line.label}</strong></td></tr>
            {elseif $line.type == 'comment'}
              <tr><td colspan="4"><em>{$line.comment}</em></td></tr>
            {else}
              <tr>
                <td>{$line.label}{if $line.reference}<br><small class="text-muted">{$line.reference}</small>{/if}{if $line.comment}<br><small><em>{$line.comment}</em></small>{/if}</td>
                <td class="text-center">{$line.qty}</td>
                {if $snt_te_first}
                  <td class="text-right">{if $line.has_reduction}<s class="text-muted">{$line.unit_price_te_display}</s><br>{/if}<strong>{$line.unit_net_te_display}</strong><br><small class="snt-pay__ht">{$line.unit_net_ti_display} {l s='incl. tax' d='Modules.Sntquotes.Shop'}</small></td>
                  <td class="text-right"><strong>{$line.total_te_display}</strong><br><small class="snt-pay__ht">{$line.total_ti_display} {l s='incl. tax' d='Modules.Sntquotes.Shop'}</small></td>
                {else}
                  <td class="text-right">{if $line.has_reduction}<s class="text-muted">{$line.unit_price_ti_display}</s><br>{/if}<strong>{$line.unit_net_ti_display}</strong><br><small class="snt-pay__ht">{$line.unit_net_te_display} {l s='excl. tax' d='Modules.Sntquotes.Shop'}</small></td>
                  <td class="text-right"><strong>{$line.total_ti_display}</strong><br><small class="snt-pay__ht">{$line.total_te_display} {l s='excl. tax' d='Modules.Sntquotes.Shop'}</small></td>
                {/if}
              </tr>
            {/if}
          {/foreach}
        </tbody>
      </table>
    </div>

    <dl class="snt-pay__totals">
      {if $snt_te_first}
        <div><dt>{l s='Products excl. tax' d='Modules.Sntquotes.Shop'}</dt><dd>{$snt_quote.total_products_te_display}</dd></div>
        {foreach $snt_quote.discounts as $discount}
          <div><dt>{if $discount.scope == 'global'}{l s='Discount' d='Modules.Sntquotes.Shop'}{else}{$discount.label}{/if}</dt><dd>-{$discount.amount_te_display}</dd></div>
        {/foreach}
        <div><dt>{l s='Shipping' d='Modules.Sntquotes.Shop'}{if $snt_quote.carrier_label} — {$snt_quote.carrier_label}{/if}</dt><dd>{if $snt_quote.free_shipping}{l s='Free' d='Modules.Sntquotes.Shop'}{else}{$snt_quote.shipping_te_display}{/if}</dd></div>
        <div class="snt-pay__grand"><dt>{l s='Total excl. tax' d='Modules.Sntquotes.Shop'}</dt><dd>{$snt_quote.total_te_display}</dd></div>
        {foreach $snt_quote.taxes as $tax}
          <div class="snt-pay__tax"><dt>{l s='VAT %rate%%' sprintf=['%rate%' => $tax.rate_display] d='Modules.Sntquotes.Shop'}</dt><dd>{$tax.tax_display}</dd></div>
        {/foreach}
        <div class="snt-pay__sub"><dt>{l s='Total incl. tax' d='Modules.Sntquotes.Shop'}</dt><dd>{$snt_quote.total_ti_display}</dd></div>
      {else}
        <div><dt>{l s='Products incl. tax' d='Modules.Sntquotes.Shop'}</dt><dd>{$snt_quote.total_products_ti_display}<small class="snt-pay__ht">{$snt_quote.total_products_te_display} {l s='excl. tax' d='Modules.Sntquotes.Shop'}</small></dd></div>
        {foreach $snt_quote.discounts as $discount}
          <div><dt>{if $discount.scope == 'global'}{l s='Discount' d='Modules.Sntquotes.Shop'}{else}{$discount.label}{/if}</dt><dd>-{$discount.amount_ti_display}<small class="snt-pay__ht">-{$discount.amount_te_display} {l s='excl. tax' d='Modules.Sntquotes.Shop'}</small></dd></div>
        {/foreach}
        <div><dt>{l s='Shipping' d='Modules.Sntquotes.Shop'}{if $snt_quote.carrier_label} — {$snt_quote.carrier_label}{/if}</dt><dd>{if $snt_quote.free_shipping}{l s='Free' d='Modules.Sntquotes.Shop'}{else}{$snt_quote.shipping_ti_display}<small class="snt-pay__ht">{$snt_quote.shipping_te_display} {l s='excl. tax' d='Modules.Sntquotes.Shop'}</small>{/if}</dd></div>
        <div class="snt-pay__sub"><dt>{l s='Total excl. tax' d='Modules.Sntquotes.Shop'}</dt><dd>{$snt_quote.total_te_display}</dd></div>
        {foreach $snt_quote.taxes as $tax}
          <div class="snt-pay__tax"><dt>{l s='VAT %rate%%' sprintf=['%rate%' => $tax.rate_display] d='Modules.Sntquotes.Shop'}</dt><dd>{$tax.tax_display}</dd></div>
        {/foreach}
        <div class="snt-pay__grand"><dt>{l s='Total incl. tax' d='Modules.Sntquotes.Shop'}</dt><dd>{$snt_quote.total_ti_display}</dd></div>
      {/if}
    </dl>
    {if $snt_quote.tax_exempt}<p class="small text-muted">{l s='VAT reverse charge (intra-community supply).' d='Modules.Sntquotes.Shop'}</p>{/if}
  </div>
</section>
