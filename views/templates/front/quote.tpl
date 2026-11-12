{**
 * Détail d'un devis dans l'espace client : actions, récapitulatif (identique au PDF), échanges.
 * Surcharge : themes/<thème>/modules/snt_quotes/views/templates/front/quote.tpl
 *}
{extends file='customer/page.tpl'}

{block name='page_title'}{l s='Quote %ref%' sprintf=['%ref%' => $snt_quote.full_reference] d='Modules.Sntquotes.Shop'}{/block}

{block name='page_content'}
<div class="snt-pay snt-quote-fo">
  {if $snt_success}
    <div class="alert alert-success" role="status">{foreach $snt_success as $message}<p class="mb-0">{$message}</p>{/foreach}</div>
  {/if}
  {if $snt_errors}
    <div class="alert alert-danger" role="alert">{foreach $snt_errors as $error}<p class="mb-0">{$error}</p>{/foreach}</div>
  {/if}

  {* --- Action principale selon le statut --------------------------------- *}
  <section class="snt-quote-fo__actions card" aria-label="{l s='What would you like to do?' d='Modules.Sntquotes.Shop'}">
    <div class="card-block card-body">
      {if $snt_order_url}
        <p class="mb-2">{l s='This quote has been ordered.' d='Modules.Sntquotes.Shop'}</p>
        <a class="btn btn-primary" href="{$snt_order_url}">{l s='View my order' d='Modules.Sntquotes.Shop'}</a>
      {elseif $snt_is_expired}
        <p class="mb-2">{l s='This quote has expired. Ask your sales representative for an updated quote.' d='Modules.Sntquotes.Shop'}</p>
      {elseif $snt_can_pay}
        <form method="post" action="{$snt_action_url}" class="snt-quote-fo__main">
          <input type="hidden" name="token" value="{$snt_token}">
          <button type="submit" name="snt_action" value="pay" class="btn btn-primary btn-lg">
            {if $snt_is_accepted}{l s='Pay my quote' d='Modules.Sntquotes.Shop'}{else}{l s='Accept and pay' d='Modules.Sntquotes.Shop'}{/if}
          </button>
          <span class="snt-quote-fo__hint">{l s='You will confirm the terms and conditions of sale and choose your payment method on the next page.' d='Modules.Sntquotes.Shop'}</span>
        </form>
      {elseif $snt_quote.status.value == 'revision_requested'}
        <p class="mb-0">{l s='Your change request has been sent. Your sales representative will send you an updated quote.' d='Modules.Sntquotes.Shop'}</p>
      {elseif $snt_quote.status.value == 'declined'}
        <p class="mb-0">{l s='You declined this quote. Contact your sales representative if you change your mind.' d='Modules.Sntquotes.Shop'}</p>
      {/if}

      <div class="snt-quote-fo__secondary">
        <a class="btn btn-secondary" href="{$snt_pdf_url}"><i class="material-icons" aria-hidden="true">picture_as_pdf</i> {l s='Download the PDF' d='Modules.Sntquotes.Shop'}</a>
        {if $snt_can_revise}
          <button type="button" class="btn btn-secondary" data-snt-toggle="snt-revision">{l s='Request a change' d='Modules.Sntquotes.Shop'}</button>
        {/if}
        {if $snt_can_decline}
          <button type="button" class="btn btn-link" data-snt-toggle="snt-decline">{l s='Decline the quote' d='Modules.Sntquotes.Shop'}</button>
        {/if}
      </div>

      {if $snt_can_revise}
        <form method="post" action="{$snt_action_url}" id="snt-revision" class="snt-quote-fo__panel" hidden>
          <input type="hidden" name="token" value="{$snt_token}">
          <label for="snt-revision-text" class="form-control-label">{l s='What would you like to change?' d='Modules.Sntquotes.Shop'}</label>
          <textarea id="snt-revision-text" name="message" class="form-control" rows="4" maxlength="3000" required></textarea>
          <button type="submit" name="snt_action" value="revision" class="btn btn-primary mt-2">{l s='Send my request' d='Modules.Sntquotes.Shop'}</button>
        </form>
      {/if}
      {if $snt_can_decline}
        <form method="post" action="{$snt_action_url}" id="snt-decline" class="snt-quote-fo__panel" hidden>
          <input type="hidden" name="token" value="{$snt_token}">
          <label for="snt-decline-text" class="form-control-label">{l s='Reason (optional)' d='Modules.Sntquotes.Shop'}</label>
          <textarea id="snt-decline-text" name="message" class="form-control" rows="3" maxlength="3000"></textarea>
          <button type="submit" name="snt_action" value="decline" class="btn btn-danger mt-2">{l s='Confirm: decline the quote' d='Modules.Sntquotes.Shop'}</button>
        </form>
      {/if}
    </div>
  </section>

  {include file='module:snt_quotes/views/templates/front/_quote-summary.tpl'}

  {if $snt_quote.customer_message}
    <section class="card"><div class="card-block card-body">
      <h2 class="h5">{l s='Message from your sales representative' d='Modules.Sntquotes.Shop'}</h2>
      <p class="mb-0">{$snt_quote.customer_message|escape:'html':'UTF-8'|nl2br nofilter}</p>
    </div></section>
  {/if}

  {* --- Échanges ------------------------------------------------------------- *}
  <section class="card snt-thread" aria-labelledby="snt-thread-title">
    <div class="card-block card-body">
      <h2 id="snt-thread-title" class="h5">{l s='Messages' d='Modules.Sntquotes.Shop'}</h2>
      {if $snt_messages}
        <ol class="snt-thread__list">
          {foreach $snt_messages as $message}
            <li class="snt-thread__item snt-thread__item--{$message.author_type}">
              <span class="snt-thread__meta">{if $message.author_type == 'customer'}{l s='You' d='Modules.Sntquotes.Shop'}{else}{$message.author_name}{/if} · {$message.date_display}</span>
              <span class="snt-thread__text">{$message.message|escape:'html':'UTF-8'|nl2br nofilter}</span>
            </li>
          {/foreach}
        </ol>
      {else}
        <p class="text-muted">{l s='No messages yet. Ask your sales representative a question below.' d='Modules.Sntquotes.Shop'}</p>
      {/if}
      <form method="post" action="{$snt_action_url}" class="snt-thread__form">
        <input type="hidden" name="token" value="{$snt_token}">
        <label for="snt-message" class="form-control-label">{l s='Your message' d='Modules.Sntquotes.Shop'}</label>
        <textarea id="snt-message" name="message" class="form-control" rows="3" maxlength="3000" required></textarea>
        <button type="submit" name="snt_action" value="message" class="btn btn-secondary mt-2">{l s='Send' d='Modules.Sntquotes.Shop'}</button>
      </form>
    </div>
  </section>

  <p><a href="{$snt_list_url}">&larr; {l s='Back to my quotes' d='Modules.Sntquotes.Shop'}</a></p>
</div>
{literal}<script>
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-snt-toggle]');
    if (!b) { return; }
    var panel = document.getElementById(b.getAttribute('data-snt-toggle'));
    if (!panel) { return; }
    panel.hidden = !panel.hidden;
    b.setAttribute('aria-expanded', panel.hidden ? 'false' : 'true');
    if (!panel.hidden) { var f = panel.querySelector('textarea'); if (f) { f.focus(); } }
  });
</script>{/literal}
{/block}
