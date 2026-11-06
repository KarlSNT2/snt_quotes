{**
 * Fiche client (BO) : 5 derniers devis + création rapide.
 *}
<div class="card snt-customer-quotes">
  <h3 class="card-header">
    <i class="material-icons" aria-hidden="true">request_quote</i>
    {l s='Quotes' d='Modules.Sntquotes.Admin'}
    <span class="badge badge-primary rounded">{$snt_quotes_count|intval}</span>
  </h3>
  <div class="card-body">
    {if $snt_quotes}
      <table class="table table-sm">
        <thead>
          <tr>
            <th>{l s='Reference' d='Modules.Sntquotes.Admin'}</th>
            <th>{l s='Date' d='Modules.Sntquotes.Admin'}</th>
            <th class="text-right">{l s='Amount incl. tax' d='Modules.Sntquotes.Admin'}</th>
            <th>{l s='Status' d='Modules.Sntquotes.Admin'}</th>
          </tr>
        </thead>
        <tbody>
          {foreach from=$snt_quotes item=q}
            <tr>
              <td><a href="{$q.view_url|escape:'html':'UTF-8'}">{$q.reference|escape:'html':'UTF-8'}{if $q.version > 1} v{$q.version|intval}{/if}</a></td>
              <td>{$q.date_display|escape:'html':'UTF-8'}</td>
              <td class="text-right">{$q.total_display|escape:'html':'UTF-8'}</td>
              <td>{$q.status_html nofilter}</td>
            </tr>
          {/foreach}
        </tbody>
      </table>
    {else}
      <p class="text-muted">{l s='No quotes for this customer yet.' d='Modules.Sntquotes.Admin'}</p>
    {/if}
    <a href="{$snt_quotes_new_url|escape:'html':'UTF-8'}" class="btn btn-primary btn-sm">
      <i class="material-icons" aria-hidden="true">add_circle_outline</i> {l s='New quote for this customer' d='Modules.Sntquotes.Admin'}
    </a>
    <a href="{$snt_quotes_import_cart_url|escape:'html':'UTF-8'}" class="btn btn-outline-secondary btn-sm">
      <i class="material-icons" aria-hidden="true">shopping_cart</i> {l s='Quote from one of their carts' d='Modules.Sntquotes.Admin'}
    </a>
  </div>
</div>
