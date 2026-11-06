{**
 * En-tête PDF du devis (répété sur chaque page).
 * Surcharge : themes/<thème>/modules/snt_quotes/views/templates/pdf/quote-header.tpl
 * Attention : dans les PDF, Smarty n'échappe pas automatiquement → |escape obligatoire.
 *}
<table style="width: 100%;" cellpadding="0" cellspacing="0">
  <tr>
    <td style="width: 50%;">
      {if $shop.logo_path}
        <img src="{$shop.logo_path|escape:'html':'UTF-8'}" style="height: 55px;" />
      {else}
        <span style="font-size: 16pt; font-weight: bold; color: {$settings.color};">{$shop.name|escape:'html':'UTF-8'}</span>
      {/if}
    </td>
    <td style="width: 50%; text-align: right;">
      <span style="font-size: 18pt; font-weight: bold; color: {$settings.color};">{l s='QUOTE' d='Modules.Sntquotes.Pdf' pdf='true'}</span><br />
      <span style="font-size: 10pt; font-weight: bold;">{$view.full_reference|escape:'html':'UTF-8'}</span><br />
      <span style="font-size: 8pt; color: #555555;">
        {l s='Issued on' d='Modules.Sntquotes.Pdf' pdf='true'} {if $view.sent_at_display}{$view.sent_at_display|escape:'html':'UTF-8'}{else}{$view.date_add_display|escape:'html':'UTF-8'}{/if}
        {if $view.valid_until_display}<br />{l s='Valid until' d='Modules.Sntquotes.Pdf' pdf='true'} {$view.valid_until_display|escape:'html':'UTF-8'}{/if}
      </span>
    </td>
  </tr>
</table>
<div style="line-height: 2pt; border-bottom: 1.5pt solid {$settings.color};">&nbsp;</div>
