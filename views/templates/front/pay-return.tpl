{**
 * Retour de la page de paiement HiPay.
 *}
{extends file='page.tpl'}

{block name='page_title'}{l s='Quote payment' d='Modules.Sntquotes.Shop'}{/block}

{block name='page_content'}
<div class="snt-pay">
  {if $snt_return_state == 'success'}
    <div class="snt-pay__state snt-pay__state--ok" role="status">
      <h2>{l s='Thank you for your payment' d='Modules.Sntquotes.Shop'}</h2>
      {if $snt_order_reference}
        <p>{l s='Your order %ref% is recorded.' sprintf=['%ref%' => $snt_order_reference] d='Modules.Sntquotes.Shop'}</p>
      {else}
        <p>{l s='Your payment is being confirmed by the bank. You will receive the order confirmation by e-mail within a few minutes.' d='Modules.Sntquotes.Shop'}</p>
      {/if}
    </div>
  {elseif $snt_return_state == 'cancel'}
    <div class="snt-pay__state">
      <h2>{l s='Payment cancelled' d='Modules.Sntquotes.Shop'}</h2>
      <p>{l s='No payment has been taken. You can try again whenever you like.' d='Modules.Sntquotes.Shop'}</p>
      <a class="btn btn-primary" href="{$snt_pay_url}">{l s='Back to my quote' d='Modules.Sntquotes.Shop'}</a>
    </div>
  {elseif $snt_return_state == 'failure'}
    <div class="snt-pay__state">
      <h2>{l s='The payment did not go through' d='Modules.Sntquotes.Shop'}</h2>
      <p>{l s='No payment has been taken. Try again, with another card if needed, or contact us.' d='Modules.Sntquotes.Shop'}</p>
      <a class="btn btn-primary" href="{$snt_pay_url}">{l s='Try again' d='Modules.Sntquotes.Shop'}</a>
      <a class="btn btn-secondary" href="mailto:{$snt_contact_email}">{l s='Contact us' d='Modules.Sntquotes.Shop'}</a>
    </div>
  {else}
    <div class="snt-pay__state">
      <h2>{l s='This link is not valid' d='Modules.Sntquotes.Shop'}</h2>
      <a class="btn btn-primary" href="mailto:{$snt_contact_email}">{l s='Contact us' d='Modules.Sntquotes.Shop'}</a>
    </div>
  {/if}
</div>
{/block}
