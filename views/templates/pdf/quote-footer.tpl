{**
 * Pied de page légal (SIRET, TVA, capital, RCS…), répété sur chaque page.
 *}
<table style="width: 100%; border-top: 0.5pt solid #BBBBBB;" cellpadding="2">
  <tr>
    <td style="text-align: center; font-size: 7pt; color: #666666;">
      <strong>{$shop.name|escape:'html':'UTF-8'}</strong>
      {if $shop.address} — {$shop.address|strip_tags|escape:'html':'UTF-8'}{/if}
      {if $shop.phone} — {l s='Tel.' d='Modules.Sntquotes.Pdf' pdf='true'} {$shop.phone|escape:'html':'UTF-8'}{/if}
      {if $shop.email} — {$shop.email|escape:'html':'UTF-8'}{/if}
      {if $settings.legal}<br />{$settings.legal|escape:'html':'UTF-8'|nl2br}{elseif $shop.details}<br />{$shop.details|escape:'html':'UTF-8'|nl2br}{/if}
    </td>
  </tr>
</table>
