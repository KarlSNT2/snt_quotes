{**
 * Demande de devis depuis le panier (FO-01) : formulaire court puis confirmation.
 * Surcharge : themes/<thème>/modules/snt_quotes/views/templates/front/request.tpl
 *}
{extends file='page.tpl'}

{block name='page_title'}{l s='Request a quote' d='Modules.Sntquotes.Shop'}{/block}

{block name='page_content'}
<div class="snt-request">
  {if $snt_done}
    <div class="snt-request__done" role="status">
      <i class="material-icons" aria-hidden="true">check_circle</i>
      <h2 class="h4">{l s='Your request %ref% has been sent' sprintf=['%ref%' => $snt_done.reference] d='Modules.Sntquotes.Shop'}</h2>
      <p>
        {if $snt_response_days > 1}
          {l s='Our team will send you a quote within %days% working days.' sprintf=['%days%' => $snt_response_days] d='Modules.Sntquotes.Shop'}
        {else}
          {l s='Our team will send you a quote within 1 working day.' d='Modules.Sntquotes.Shop'}
        {/if}
        {l s='You will receive it by e-mail and in your « My quotes » space.' d='Modules.Sntquotes.Shop'}
      </p>
      <p class="snt-request__actions">
        <a class="btn btn-primary" href="{$snt_quotes_url}">{l s='My quotes' d='Modules.Sntquotes.Shop'}</a>
        <a class="btn btn-secondary" href="{$snt_cart_url}">{l s='Back to my cart' d='Modules.Sntquotes.Shop'}</a>
      </p>
    </div>
  {elseif $snt_existing}
    <div class="snt-request__done" role="status">
      <i class="material-icons" aria-hidden="true">info</i>
      <h2 class="h4">{l s='You have already requested a quote for this cart (%ref%).' sprintf=['%ref%' => $snt_existing] d='Modules.Sntquotes.Shop'}</h2>
      <p>{l s='Our team is preparing it. If you change your cart, you can send a new request.' d='Modules.Sntquotes.Shop'}</p>
      <p class="snt-request__actions">
        <a class="btn btn-primary" href="{$snt_quotes_url}">{l s='My quotes' d='Modules.Sntquotes.Shop'}</a>
        <a class="btn btn-secondary" href="{$snt_cart_url}">{l s='Back to my cart' d='Modules.Sntquotes.Shop'}</a>
      </p>
    </div>
  {else}
    <p class="snt-request__intro">{l s='Tell us about your project: our team checks availability, delivery and the best price, then sends you a quote. Your cart is kept as it is.' d='Modules.Sntquotes.Shop'}</p>

    {if $snt_errors}
      <div class="alert alert-danger" role="alert">
        <ul class="mb-0">{foreach $snt_errors as $error}<li>{$error}</li>{/foreach}</ul>
      </div>
    {/if}

    <div class="snt-request__grid">
      <form method="post" action="{$snt_form_action}" enctype="multipart/form-data" class="snt-request__form card">
        <div class="card-block card-body">
          <input type="hidden" name="token" value="{$snt_token}">
          <input type="hidden" name="snt_request" value="1">

          <div class="form-group">
            <label for="snt-project" class="form-control-label">{l s='Project name' d='Modules.Sntquotes.Shop'} <span class="text-muted">({l s='optional' d='Modules.Sntquotes.Shop'})</span></label>
            <input type="text" id="snt-project" name="project_name" class="form-control" maxlength="128" value="{$snt_form.project_name}" placeholder="{l s='e.g. Renovation of the Lyon site' d='Modules.Sntquotes.Shop'}">
          </div>

          <div class="form-group">
            <label for="snt-message" class="form-control-label">{l s='Your message' d='Modules.Sntquotes.Shop'} <span class="text-muted">({l s='optional' d='Modules.Sntquotes.Shop'})</span></label>
            <textarea id="snt-message" name="message" class="form-control" rows="5" maxlength="3000" placeholder="{l s='Quantities to confirm, delivery date, site access, questions…' d='Modules.Sntquotes.Shop'}">{$snt_form.message}</textarea>
          </div>

          <div class="form-group">
            <label for="snt-attachment" class="form-control-label">{l s='Attachment' d='Modules.Sntquotes.Shop'} <span class="text-muted">({l s='optional' d='Modules.Sntquotes.Shop'})</span></label>
            <input type="file" id="snt-attachment" name="attachment" class="form-control-file" accept="{$snt_accept}">
            <small class="form-text text-muted">{l s='Plan, specifications, list… %size% MB maximum.' sprintf=['%size%' => $snt_max_mb] d='Modules.Sntquotes.Shop'}</small>
          </div>

          {if $snt_addresses}
            <div class="form-group">
              <label for="snt-delivery" class="form-control-label">{l s='Delivery address' d='Modules.Sntquotes.Shop'}</label>
              <select id="snt-delivery" name="id_address_delivery" class="form-control">
                {foreach $snt_addresses as $address}
                  <option value="{$address.id}"{if $address.id == $snt_form.delivery} selected{/if}>{$address.label}</option>
                {/foreach}
              </select>
            </div>
            <div class="form-group">
              <label for="snt-invoice" class="form-control-label">{l s='Invoice address' d='Modules.Sntquotes.Shop'}</label>
              <select id="snt-invoice" name="id_address_invoice" class="form-control">
                {foreach $snt_addresses as $address}
                  <option value="{$address.id}"{if $address.id == $snt_form.invoice} selected{/if}>{$address.label}</option>
                {/foreach}
              </select>
            </div>
            <p class="snt-request__help"><a href="{$snt_address_url}">{l s='Add a new address' d='Modules.Sntquotes.Shop'}</a></p>
          {else}
            <p class="snt-request__help">
              {l s='You have no saved address yet: our team will ask you for the delivery address.' d='Modules.Sntquotes.Shop'}
              <a href="{$snt_address_url}">{l s='Add an address' d='Modules.Sntquotes.Shop'}</a>
            </p>
          {/if}

          <button type="submit" class="btn btn-primary snt-request__submit">{l s='Send my request' d='Modules.Sntquotes.Shop'}</button>
          <p class="snt-request__help">
            {if $snt_response_days > 1}
              {l s='Answer within %days% working days.' sprintf=['%days%' => $snt_response_days] d='Modules.Sntquotes.Shop'}
            {else}
              {l s='Answer within 1 working day.' d='Modules.Sntquotes.Shop'}
            {/if}
          </p>
        </div>
      </form>

      <aside class="snt-request__cart card" aria-labelledby="snt-request-cart-title">
        <div class="card-block card-body">
          <h2 id="snt-request-cart-title" class="h5">{l s='Items in your request' d='Modules.Sntquotes.Shop'}</h2>
          <ul class="snt-request__items">
            {foreach $snt_products as $product}
              <li>
                <span class="snt-request__qty">{$product.qty} ×</span>
                <span>
                  {$product.name}
                  {if $product.attributes}<br><small class="text-muted">{$product.attributes}</small>{/if}
                  {if $product.reference}<br><small class="text-muted">{l s='Ref.' d='Modules.Sntquotes.Shop'} {$product.reference}</small>{/if}
                </span>
              </li>
            {/foreach}
          </ul>
          <a href="{$snt_cart_url}" class="snt-request__edit">{l s='Edit my cart' d='Modules.Sntquotes.Shop'}</a>
        </div>
      </aside>
    </div>
  {/if}
</div>
{/block}
