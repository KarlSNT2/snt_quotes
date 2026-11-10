{**
 * Page de paiement d'un devis par lien (sans connexion).
 * Surcharge : themes/<thème>/modules/snt_quotes/views/templates/front/pay.tpl
 * Smarty front : variables échappées automatiquement ; « nofilter » uniquement pour du HTML produit par le module.
 *}
{extends file='page.tpl'}

{block name='page_title'}
  {if $snt_quote}{l s='Quote %ref%' sprintf=['%ref%' => $snt_quote.full_reference] d='Modules.Sntquotes.Shop'}{else}{l s='Quote payment' d='Modules.Sntquotes.Shop'}{/if}
{/block}

{block name='page_content'}
<div class="snt-pay">
  {if $snt_errors}
    <div class="alert alert-danger" role="alert">{foreach $snt_errors as $error}<p class="mb-0">{$error}</p>{/foreach}</div>
  {/if}

  {if $snt_state == 'invalid'}
    <div class="snt-pay__state">
      <h2>{l s='This link is not valid' d='Modules.Sntquotes.Shop'}</h2>
      <p>{l s='Check that you copied the whole link from the e-mail, or contact us.' d='Modules.Sntquotes.Shop'}</p>
      <a class="btn btn-primary" href="mailto:{$snt_contact_email}">{l s='Contact us' d='Modules.Sntquotes.Shop'}</a>
    </div>
  {elseif $snt_state == 'expired' || $snt_state == 'revoked' || $snt_state == 'unavailable'}
    <div class="snt-pay__state">
      <h2>{l s='This payment link has expired' d='Modules.Sntquotes.Shop'}</h2>
      <p>{l s='Your sales representative can send you a new link or an updated quote.' d='Modules.Sntquotes.Shop'}</p>
      <a class="btn btn-primary" href="mailto:{$snt_contact_email}?subject={$snt_quote.full_reference|default:''|escape:'url'}">{l s='Contact my sales representative' d='Modules.Sntquotes.Shop'}</a>
    </div>
  {elseif $snt_state == 'replaced'}
    <div class="snt-pay__state">
      <h2>{l s='This quote has been updated' d='Modules.Sntquotes.Shop'}</h2>
      <p>{l s='A new version has been sent to you by e-mail. Use the link in that e-mail.' d='Modules.Sntquotes.Shop'}</p>
      <a class="btn btn-primary" href="mailto:{$snt_contact_email}">{l s='Contact my sales representative' d='Modules.Sntquotes.Shop'}</a>
    </div>
  {elseif $snt_state == 'paid'}
    <div class="snt-pay__state snt-pay__state--ok">
      <h2>{l s='This quote has already been ordered' d='Modules.Sntquotes.Shop'}</h2>
      <p>{l s='Thank you! You will find your order in your customer account.' d='Modules.Sntquotes.Shop'}</p>
    </div>
  {elseif $snt_state == 'done'}
    <div class="snt-pay__state snt-pay__state--ok" role="status">
      <h2>{l s='Thank you, your order is recorded' d='Modules.Sntquotes.Shop'}</h2>
      {if $snt_done.reference}<p>{l s='Order reference: %ref%' sprintf=['%ref%' => $snt_done.reference] d='Modules.Sntquotes.Shop'}</p>{/if}
      {if $snt_done.type == 'payplug'}
        <p>{l s='You will receive an e-mail with a secure payment link within a few minutes.' d='Modules.Sntquotes.Shop'}</p>
      {elseif $snt_done.type == 'transfer'}
        <p>{l s='Please make the bank transfer, quoting your order reference.' d='Modules.Sntquotes.Shop'}</p>
        {if $snt_iban}<p class="snt-pay__iban">IBAN : <strong>{$snt_iban}</strong>{if $snt_bic}<br>BIC : <strong>{$snt_bic}</strong>{/if}</p>{/if}
      {else}
        <p>{l s='Payment according to the terms agreed with your sales representative.' d='Modules.Sntquotes.Shop'}</p>
      {/if}
      <p>{l s='A confirmation e-mail has been sent to you.' d='Modules.Sntquotes.Shop'}</p>
    </div>
  {/if}

  {if $snt_quote && $snt_state != 'invalid'}
    <section class="snt-pay__quote card" aria-labelledby="snt-pay-summary">
      <div class="card-block card-body">
        <div class="snt-pay__head">
          <h2 id="snt-pay-summary" class="h4">{l s='Quote summary' d='Modules.Sntquotes.Shop'}</h2>
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
                <th scope="col" class="text-right">{l s='Unit price excl. tax' d='Modules.Sntquotes.Shop'}</th>
                <th scope="col" class="text-right">{l s='Total excl. tax' d='Modules.Sntquotes.Shop'}</th>
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
                    <td class="text-right">{if $line.has_reduction}<s class="text-muted">{$line.unit_price_te_display}</s><br>{/if}{$line.unit_net_te_display}</td>
                    <td class="text-right">{$line.total_te_display}</td>
                  </tr>
                {/if}
              {/foreach}
            </tbody>
          </table>
        </div>

        <dl class="snt-pay__totals">
          <div><dt>{l s='Products excl. tax' d='Modules.Sntquotes.Shop'}</dt><dd>{$snt_quote.total_products_te_display}</dd></div>
          {foreach $snt_quote.discounts as $discount}
            <div><dt>{if $discount.scope == 'global'}{l s='Discount' d='Modules.Sntquotes.Shop'}{else}{$discount.label}{/if}</dt><dd>-{$discount.amount_te_display}</dd></div>
          {/foreach}
          <div><dt>{l s='Shipping' d='Modules.Sntquotes.Shop'}{if $snt_quote.carrier_label} — {$snt_quote.carrier_label}{/if}</dt><dd>{if $snt_quote.free_shipping}{l s='Free' d='Modules.Sntquotes.Shop'}{else}{$snt_quote.shipping_te_display}{/if}</dd></div>
          <div><dt>{l s='Total excl. tax' d='Modules.Sntquotes.Shop'}</dt><dd>{$snt_quote.total_te_display}</dd></div>
          {foreach $snt_quote.taxes as $tax}
            <div class="snt-pay__tax"><dt>{l s='VAT %rate%%' sprintf=['%rate%' => $tax.rate_display] d='Modules.Sntquotes.Shop'}</dt><dd>{$tax.tax_display}</dd></div>
          {/foreach}
          <div class="snt-pay__grand"><dt>{l s='Total incl. tax' d='Modules.Sntquotes.Shop'}</dt><dd>{$snt_quote.total_ti_display}</dd></div>
        </dl>
        {if $snt_quote.tax_exempt}<p class="small text-muted">{l s='VAT reverse charge (intra-community supply).' d='Modules.Sntquotes.Shop'}</p>{/if}
      </div>
    </section>
  {/if}

  {if $snt_state == 'pay'}
    <section class="snt-pay__form card" aria-labelledby="snt-pay-title">
      <div class="card-block card-body">
        <h2 id="snt-pay-title" class="h4">{l s='Accept and pay' d='Modules.Sntquotes.Shop'}</h2>
        {if $snt_methods}
          <form method="post" action="{$snt_form_action}">
            <input type="hidden" name="k" value="{$snt_form_key}">
            <fieldset>
              <legend class="sr-only">{l s='Payment method' d='Modules.Sntquotes.Shop'}</legend>
              {foreach $snt_methods as $method}
                <label class="snt-pay__method">
                  <input type="radio" name="method" value="{$method.value}" required{if $method@first} checked{/if}>
                  <span><strong>{$method.label}</strong><br><small>{$method.help}</small></span>
                </label>
              {/foreach}
            </fieldset>
            <label class="snt-pay__cgv">
              <input type="checkbox" name="cgv" value="1" required>
              <span>
                {if $snt_cgv_url}
                  {l s='I have read and accept the [1]terms and conditions of sale[/1] and this quote.' sprintf=['[1]' => "<a href=\"{$snt_cgv_url}\" target=\"_blank\" rel=\"noopener\">", '[/1]' => '</a>'] d='Modules.Sntquotes.Shop'}
                {else}
                  {l s='I accept the terms and conditions of sale and this quote.' d='Modules.Sntquotes.Shop'}
                {/if}
              </span>
            </label>
            <p class="snt-pay__total">{l s='Total to pay:' d='Modules.Sntquotes.Shop'} <strong>{$snt_quote.total_ti_display}</strong></p>
            <button type="submit" name="snt_quote_pay" value="1" class="btn btn-primary btn-lg">{l s='Accept and continue' d='Modules.Sntquotes.Shop'}</button>
            <p class="snt-pay__secure"><i class="material-icons" aria-hidden="true">lock</i> {l s='Secure link, valid until %date%. Your card details are never stored by our shop.' sprintf=['%date%' => $snt_link_expires] d='Modules.Sntquotes.Shop'}</p>
          </form>
        {else}
          <p>{l s='No online payment method is available for this quote. Contact your sales representative.' d='Modules.Sntquotes.Shop'}</p>
          <a class="btn btn-primary" href="mailto:{$snt_contact_email}">{l s='Contact my sales representative' d='Modules.Sntquotes.Shop'}</a>
        {/if}
      </div>
    </section>
  {/if}
</div>
{/block}
