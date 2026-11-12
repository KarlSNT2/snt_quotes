{**
 * Espace client « Mes devis » : cartes sur mobile, tableau sur ordinateur.
 * Surcharge : themes/<thème>/modules/snt_quotes/views/templates/front/quotes.tpl
 *}
{extends file='customer/page.tpl'}

{block name='page_title'}{l s='My quotes' d='Modules.Sntquotes.Shop'}{/block}

{block name='page_content'}
<div class="snt-quotes-fo">
  {if !$snt_quotes}
    <p class="snt-empty-fo">{l s='You have no quotes yet. Your sales representative will send them to you here. You can also request a quote from your cart.' d='Modules.Sntquotes.Shop'}</p>
  {else}
    {if $snt_awaiting}
      <section class="snt-awaiting" aria-labelledby="snt-awaiting-title">
        <h2 id="snt-awaiting-title" class="h5">{l s='Waiting for your answer' d='Modules.Sntquotes.Shop'}</h2>
        {foreach $snt_awaiting as $quote}
          <div class="snt-awaiting__item">
            <div>
              <strong>{$quote.reference}</strong>{if $quote.project_name} — {$quote.project_name}{/if}<br>
              <span class="snt-awaiting__amount">{$quote.amount}</span>
              {if $quote.valid_until}<span class="{if $quote.expires_soon}snt-text-urgent{else}text-muted{/if}"> · {l s='Valid until %date%' sprintf=['%date%' => $quote.valid_until] d='Modules.Sntquotes.Shop'}</span>{/if}
            </div>
            <a class="btn btn-primary" href="{$quote.url}">{l s='View and confirm' d='Modules.Sntquotes.Shop'}</a>
          </div>
        {/foreach}
      </section>
    {/if}

    <table class="table table-striped table-bordered snt-quotes-fo__table hidden-sm-down d-none d-md-table">
      <thead class="thead-default">
        <tr>
          <th scope="col">{l s='Reference' d='Modules.Sntquotes.Shop'}</th>
          <th scope="col">{l s='Project' d='Modules.Sntquotes.Shop'}</th>
          <th scope="col">{l s='Date' d='Modules.Sntquotes.Shop'}</th>
          <th scope="col" class="text-right">{if $snt_te_first}{l s='Total excl. tax' d='Modules.Sntquotes.Shop'}{else}{l s='Total incl. tax' d='Modules.Sntquotes.Shop'}{/if}</th>
          <th scope="col">{l s='Status' d='Modules.Sntquotes.Shop'}</th>
          <th scope="col">{l s='Valid until' d='Modules.Sntquotes.Shop'}</th>
          <th scope="col"><span class="sr-only">{l s='Actions' d='Modules.Sntquotes.Shop'}</span></th>
        </tr>
      </thead>
      <tbody>
        {foreach $snt_quotes as $quote}
          <tr>
            <th scope="row">{if $quote.url}<a href="{$quote.url}">{$quote.reference}</a>{else}{$quote.reference}{/if}</th>
            <td>{$quote.project_name}</td>
            <td>{$quote.date}</td>
            <td class="text-right">{$quote.amount}</td>
            <td><span class="snt-badge{if $quote.status.strike} snt-badge--strike{/if}" style="background: {$quote.status.color};">{$quote.status.label}</span></td>
            <td{if $quote.expires_soon} class="snt-text-urgent"{/if}>{$quote.valid_until}</td>
            <td class="text-right">{if $quote.url}<a href="{$quote.url}">{if $quote.awaiting}{l s='View and confirm' d='Modules.Sntquotes.Shop'}{else}{l s='View' d='Modules.Sntquotes.Shop'}{/if}</a>{else}<span class="text-muted">{l s='Being prepared' d='Modules.Sntquotes.Shop'}</span>{/if}</td>
          </tr>
        {/foreach}
      </tbody>
    </table>

    <ul class="snt-quotes-fo__cards hidden-md-up d-md-none">
      {foreach $snt_quotes as $quote}
        <li class="snt-quote-card">
          {if $quote.url}<a href="{$quote.url}" class="snt-quote-card__link">{else}<div class="snt-quote-card__link">{/if}
            <span class="snt-quote-card__top">
              <strong>{$quote.reference}</strong>
              <span class="snt-badge{if $quote.status.strike} snt-badge--strike{/if}" style="background: {$quote.status.color};">{$quote.status.label}</span>
            </span>
            {if $quote.project_name}<span class="snt-quote-card__project">{$quote.project_name}</span>{/if}
            <span class="snt-quote-card__bottom">
              <span>{$quote.date}</span>
              <strong>{$quote.amount}</strong>
            </span>
            {if $quote.valid_until}<span class="snt-quote-card__validity{if $quote.expires_soon} snt-text-urgent{/if}">{l s='Valid until %date%' sprintf=['%date%' => $quote.valid_until] d='Modules.Sntquotes.Shop'}</span>{/if}
          {if $quote.url}</a>{else}</div>{/if}
        </li>
      {/foreach}
    </ul>
  {/if}
</div>
{/block}
