{**
 * Corps du PDF de devis (TCPDF : HTML et CSS limités, tableaux à largeurs fixes).
 * - en-tête de tableau répété à chaque page (thead) ;
 * - nobr="true" empêche de couper une ligne entre deux pages.
 * Surcharge : themes/<thème>/modules/snt_quotes/views/templates/pdf/quote.tpl
 *}
<style>
  .small { font-size: 7.5pt; color: #555555; }
  .label { font-size: 7.5pt; color: #777777; text-transform: uppercase; }
  .box { border: 0.5pt solid #CCCCCC; }
  th { background-color: {$settings.color}; color: #FFFFFF; font-weight: bold; font-size: 7.5pt; }
  td { font-size: 8pt; }
  .right { text-align: right; }
  .center { text-align: center; }
  .strike { text-decoration: line-through; color: #888888; }
  .section { background-color: #EEF1F5; font-weight: bold; font-size: 8.5pt; }
  .comment { font-style: italic; color: #555555; }
  .total { font-size: 10pt; font-weight: bold; color: {$settings.color}; }
</style>

{* --- Parties ------------------------------------------------------------ *}
<table style="width: 100%;" cellpadding="4">
  <tr>
    <td style="width: 48%;" class="box">
      <span class="label">{l s='Customer' d='Modules.Sntquotes.Pdf' pdf='true'}</span><br />
      {if $view.customer.company}<strong>{$view.customer.company|escape:'html':'UTF-8'}</strong><br />{/if}
      {$view.customer.name|escape:'html':'UTF-8'}<br />
      {if $view.address_invoice}{$view.address_invoice.lines_text|escape:'html':'UTF-8'|nl2br}{/if}
      {if $view.vat_number}<br /><span class="small">{l s='VAT no.' d='Modules.Sntquotes.Pdf' pdf='true'} {$view.vat_number|escape:'html':'UTF-8'}</span>{/if}
      {if $view.customer.siret}<br /><span class="small">{l s='SIRET' d='Modules.Sntquotes.Pdf' pdf='true'} {$view.customer.siret|escape:'html':'UTF-8'}</span>{/if}
    </td>
    <td style="width: 4%;"></td>
    <td style="width: 48%;" class="box">
      <span class="label">{l s='Delivery' d='Modules.Sntquotes.Pdf' pdf='true'}</span><br />
      {if $view.delivery_mode == 'pickup'}
        {l s='Pickup at our premises' d='Modules.Sntquotes.Pdf' pdf='true'}
      {elseif $view.relay}
        <strong>{$view.relay.name|escape:'html':'UTF-8'}</strong><br />
        {$view.relay.address|escape:'html':'UTF-8'}<br />{$view.relay.zip|escape:'html':'UTF-8'} {$view.relay.city|escape:'html':'UTF-8'}
      {elseif $view.address_delivery}
        {$view.address_delivery.lines_text|escape:'html':'UTF-8'|nl2br}
      {/if}
      {if $view.employee}
        <br /><br /><span class="label">{l s='Your contact' d='Modules.Sntquotes.Pdf' pdf='true'}</span><br />
        {$view.employee.name|escape:'html':'UTF-8'} — {$view.employee.email|escape:'html':'UTF-8'}{if $view.employee.phone} — {$view.employee.phone|escape:'html':'UTF-8'}{/if}
      {/if}
    </td>
  </tr>
</table>

{if $view.project_name}
  <p style="font-size: 9pt;"><strong>{l s='Project:' d='Modules.Sntquotes.Pdf' pdf='true'}</strong> {$view.project_name|escape:'html':'UTF-8'}</p>
{else}
  <br />
{/if}

{* --- Lignes ------------------------------------------------------------- *}
<table style="width: 100%;" cellpadding="3" cellspacing="0">
  <thead>
    <tr>
      <th style="width: 13%;">{l s='Ref.' d='Modules.Sntquotes.Pdf' pdf='true'}</th>
      <th style="width: 35%;">{l s='Description' d='Modules.Sntquotes.Pdf' pdf='true'}</th>
      <th style="width: 7%;" class="center">{l s='Qty' d='Modules.Sntquotes.Pdf' pdf='true'}</th>
      <th style="width: 12%;" class="right">{l s='Unit price excl. tax' d='Modules.Sntquotes.Pdf' pdf='true'}</th>
      <th style="width: 8%;" class="right">{l s='Discount' d='Modules.Sntquotes.Pdf' pdf='true'}</th>
      <th style="width: 7%;" class="right">{l s='VAT' d='Modules.Sntquotes.Pdf' pdf='true'}</th>
      <th style="width: 18%;" class="right">{l s='Total excl. tax' d='Modules.Sntquotes.Pdf' pdf='true'}</th>
    </tr>
  </thead>
  <tbody>
  {foreach from=$view.lines item=line name=lines}
    {if $line.type == 'section'}
      <tr nobr="true"><td colspan="7" class="section">{$line.label|escape:'html':'UTF-8'}{if $line.comment}<br /><span class="comment">{$line.comment|escape:'html':'UTF-8'|nl2br}</span>{/if}</td></tr>
    {elseif $line.type == 'comment'}
      <tr nobr="true"><td colspan="7" class="comment">{if $line.label}{$line.label|escape:'html':'UTF-8'}<br />{/if}{$line.comment|escape:'html':'UTF-8'|nl2br}</td></tr>
    {else}
      <tr nobr="true" style="background-color: {if $smarty.foreach.lines.iteration is odd}#FFFFFF{else}#F8F9FB{/if};">
        <td style="width: 13%;" class="small">{$line.reference|escape:'html':'UTF-8'}</td>
        <td style="width: 35%;">{$line.label|escape:'html':'UTF-8'}{if $line.comment}<br /><span class="comment">{$line.comment|escape:'html':'UTF-8'|nl2br}</span>{/if}</td>
        <td style="width: 7%;" class="center">{$line.qty|intval}</td>
        <td style="width: 12%;" class="right">
          {if $line.has_reduction}<span class="strike">{$line.unit_price_te_display|escape:'html':'UTF-8'}</span><br />{/if}{$line.unit_net_te_display|escape:'html':'UTF-8'}
        </td>
        <td style="width: 8%;" class="right">{if $line.reduction_display}{$line.reduction_display|escape:'html':'UTF-8'}{/if}</td>
        <td style="width: 7%;" class="right">{$line.tax_rate_display|escape:'html':'UTF-8'} %</td>
        <td style="width: 18%;" class="right">{$line.total_te_display|escape:'html':'UTF-8'}</td>
      </tr>
    {/if}
  {/foreach}
  </tbody>
</table>

<br />

{* --- TVA et totaux ------------------------------------------------------ *}
<table style="width: 100%;" cellpadding="0" cellspacing="0" nobr="true">
  <tr>
    <td style="width: 50%;">
      {if $view.tax_exempt}
        <p class="small">{l s='VAT reverse charge — Article 196 of Directive 2006/112/EC (intra-community supply).' d='Modules.Sntquotes.Pdf' pdf='true'}{if $view.vat_number} {l s='Customer VAT no.' d='Modules.Sntquotes.Pdf' pdf='true'} {$view.vat_number|escape:'html':'UTF-8'}{/if}</p>
      {elseif $view.taxes}
        <table style="width: 95%;" cellpadding="2" class="box">
          <tr>
            <th>{l s='VAT rate' d='Modules.Sntquotes.Pdf' pdf='true'}</th>
            <th class="right">{l s='Base excl. tax' d='Modules.Sntquotes.Pdf' pdf='true'}</th>
            <th class="right">{l s='VAT' d='Modules.Sntquotes.Pdf' pdf='true'}</th>
          </tr>
          {foreach from=$view.taxes item=tax}
            <tr>
              <td>{$tax.rate_display|escape:'html':'UTF-8'} %</td>
              <td class="right">{$tax.base_display|escape:'html':'UTF-8'}</td>
              <td class="right">{$tax.tax_display|escape:'html':'UTF-8'}</td>
            </tr>
          {/foreach}
        </table>
      {/if}
    </td>
    <td style="width: 50%;">
      <table style="width: 100%;" cellpadding="3">
        <tr><td>{l s='Products excl. tax' d='Modules.Sntquotes.Pdf' pdf='true'}</td><td class="right">{$view.total_products_te_display|escape:'html':'UTF-8'}</td></tr>
        {foreach from=$view.discounts item=discount}
          <tr><td>{if $discount.scope == 'global'}{l s='Overall discount' d='Modules.Sntquotes.Pdf' pdf='true'}{else}{$discount.label|escape:'html':'UTF-8'}{/if}{if $discount.reduction_display} ({$discount.reduction_display|escape:'html':'UTF-8'}){/if}</td><td class="right">-{$discount.amount_te_display|escape:'html':'UTF-8'}</td></tr>
        {/foreach}
        <tr>
          <td>{l s='Shipping' d='Modules.Sntquotes.Pdf' pdf='true'}{if $view.carrier_label} — {$view.carrier_label|escape:'html':'UTF-8'}{/if}</td>
          <td class="right">
            {if $view.free_shipping}{l s='Free' d='Modules.Sntquotes.Pdf' pdf='true'}{else}{if $view.shipping_discounted}<span class="strike">{$view.shipping_gross_te_display|escape:'html':'UTF-8'}</span> {/if}{$view.shipping_te_display|escape:'html':'UTF-8'}{/if}
          </td>
        </tr>
        <tr><td style="border-top: 0.5pt solid #CCCCCC;"><strong>{l s='Total excl. tax' d='Modules.Sntquotes.Pdf' pdf='true'}</strong></td><td class="right" style="border-top: 0.5pt solid #CCCCCC;"><strong>{$view.total_te_display|escape:'html':'UTF-8'}</strong></td></tr>
        <tr><td>{l s='VAT' d='Modules.Sntquotes.Pdf' pdf='true'}</td><td class="right">{$view.total_tax_display|escape:'html':'UTF-8'}</td></tr>
        <tr><td class="total">{l s='Total incl. tax' d='Modules.Sntquotes.Pdf' pdf='true'}</td><td class="right total">{$view.total_ti_display|escape:'html':'UTF-8'}</td></tr>
      </table>
    </td>
  </tr>
</table>

<br />

{* --- Conditions --------------------------------------------------------- *}
<table style="width: 100%;" cellpadding="4" nobr="true">
  <tr>
    <td class="box">
      <span class="label">{l s='Terms' d='Modules.Sntquotes.Pdf' pdf='true'}</span><br />
      {if $view.valid_until_display}{l s='This quote is valid until %date%.' sprintf=['%date%' => $view.valid_until_display] d='Modules.Sntquotes.Pdf' pdf='true'}<br />{/if}
      {if $settings.payment_terms}{$settings.payment_terms|escape:'html':'UTF-8'|nl2br}<br />{/if}
      {if $settings.iban && in_array('transfer', $view.payment_methods)}
        {l s='Bank transfer:' d='Modules.Sntquotes.Pdf' pdf='true'} IBAN {$settings.iban|escape:'html':'UTF-8'}{if $settings.bic} — BIC {$settings.bic|escape:'html':'UTF-8'}{/if} — {l s='reference to quote:' d='Modules.Sntquotes.Pdf' pdf='true'} {$view.reference|escape:'html':'UTF-8'}
      {/if}
    </td>
  </tr>
  {if $view.customer_message}
  <tr>
    <td>
      <span class="label">{l s='Message' d='Modules.Sntquotes.Pdf' pdf='true'}</span><br />
      {$view.customer_message|escape:'html':'UTF-8'|nl2br}
    </td>
  </tr>
  {/if}
</table>

<br />

{* --- Bon pour accord ---------------------------------------------------- *}
<table style="width: 100%;" cellpadding="5" nobr="true">
  <tr>
    <td style="width: 45%;"></td>
    <td style="width: 55%; border: 0.8pt solid {$settings.color};">
      <strong>{l s='Approved — « Bon pour accord »' d='Modules.Sntquotes.Pdf' pdf='true'}</strong><br />
      <span class="small">{l s='Date, signature and company stamp, preceded by the handwritten words « Bon pour accord ».' d='Modules.Sntquotes.Pdf' pdf='true'}</span><br />
      {l s='Date:' d='Modules.Sntquotes.Pdf' pdf='true'} ____ / ____ / ________<br /><br /><br /><br /><br />
    </td>
  </tr>
</table>

{if $settings.cgv}
  <br pagebreak="true" />
  <h3 style="color: {$settings.color};">{l s='General terms and conditions of sale' d='Modules.Sntquotes.Pdf' pdf='true'}</h3>
  <div style="font-size: 7pt; text-align: justify;">{$settings.cgv nofilter}</div>
{/if}
