<?php

declare(strict_types=1);

namespace Snt\Quotes\Presenter;

use Snt\Quotes\Domain\QuoteStatus;

/**
 * Badge de statut : même libellé traduit et même couleur dans le BO, le FO, le PDF et les e-mails.
 */
class StatusPresenter
{
    public const DOMAIN_ADMIN = 'Modules.Sntquotes.Admin';
    public const DOMAIN_SHOP = 'Modules.Sntquotes.Shop';

    /**
     * @return array{value: string, label: string, color: string, strike: bool}
     */
    public function present(QuoteStatus $status, string $domain = self::DOMAIN_ADMIN): array
    {
        return [
            'value' => $status->value,
            'label' => $this->label($status, $domain),
            'color' => $status->color(),
            'strike' => $status->isStrikethrough(),
        ];
    }

    public function label(QuoteStatus $status, string $domain = self::DOMAIN_ADMIN): string
    {
        return \Context::getContext()->getTranslator()->trans($status->label(), [], $domain);
    }

    /**
     * Badge HTML autonome (styles en ligne : utilisable dans la Grid, les e-mails et le PDF).
     */
    public function badgeHtml(QuoteStatus $status, string $domain = self::DOMAIN_ADMIN): string
    {
        $badge = $this->present($status, $domain);

        return sprintf(
            '<span class="snt-quote-badge" style="display:inline-block;padding:2px 10px;border-radius:12px;font-size:12px;font-weight:600;line-height:18px;color:#fff;background:%s;%s">%s</span>',
            $badge['color'],
            $badge['strike'] ? 'text-decoration:line-through;' : '',
            htmlspecialchars($badge['label'], ENT_QUOTES, 'UTF-8')
        );
    }

    /**
     * Tous les statuts (filtres de la Grid, légendes).
     *
     * @return array<string, string> libellé => valeur
     */
    public function choices(string $domain = self::DOMAIN_ADMIN): array
    {
        $out = [];
        foreach (QuoteStatus::cases() as $status) {
            $out[$this->label($status, $domain)] = $status->value;
        }

        return $out;
    }
}
