<?php

declare(strict_types=1);

namespace Snt\Quotes\Repository;

use Snt\Quotes\Domain\QuoteStatus;

/**
 * Devis visibles par un client dans son espace « Mes devis » : jamais les brouillons ni ceux en validation
 * interne, ni les versions remplacées. Un client ne voit que ses devis de la boutique courante.
 */
class CustomerQuoteRepository
{
    /** @return QuoteStatus[] */
    public static function hiddenStatuses(): array
    {
        return [QuoteStatus::Draft, QuoteStatus::PendingApproval, QuoteStatus::Cancelled];
    }

    /**
     * @return array<int, array<string, mixed>> les devis qui attendent une action du client d'abord
     */
    public function listForCustomer(int $idCustomer, int $idShop): array
    {
        $hidden = implode(',', array_map(static fn (QuoteStatus $s): string => '\'' . $s->value . '\'', self::hiddenStatuses()));

        return \Db::getInstance()->executeS(
            'SELECT `id_snt_quote`, `reference`, `version`, `project_name`, `status`, `total_ti`, `total_te`, `id_currency`,
                    `valid_until`, `sent_at`, `date_add`, `id_order`
             FROM `' . _DB_PREFIX_ . 'snt_quote`
             WHERE `id_customer` = ' . $idCustomer . ' AND `id_shop` = ' . $idShop . ' AND `deleted` = 0
               AND `status` NOT IN (' . $hidden . ')
             ORDER BY (`status` IN (\'sent\', \'viewed\')) DESC, COALESCE(`sent_at`, `date_add`) DESC
             LIMIT 200'
        ) ?: [];
    }

    public function findForCustomer(int $idQuote, int $idCustomer, int $idShop): ?\SntQuote
    {
        if ($idQuote <= 0 || $idCustomer <= 0) {
            return null;
        }
        $quote = new \SntQuote($idQuote);
        if (!\Validate::isLoadedObject($quote)
            || $quote->deleted
            || (int) $quote->id_customer !== $idCustomer
            || (int) $quote->id_shop !== $idShop
            || in_array($quote->getStatus(), self::hiddenStatuses(), true) && $quote->getStatus() !== QuoteStatus::Cancelled
        ) {
            return null;
        }

        return $quote;
    }

    /**
     * Version qui remplace un devis (pour rediriger le client depuis une ancienne version).
     */
    public function latestVersionId(\SntQuote $quote): int
    {
        $root = (int) ($quote->id_root_quote ?: $quote->id);
        $hidden = implode(',', array_map(static fn (QuoteStatus $s): string => '\'' . $s->value . '\'', self::hiddenStatuses()));

        return (int) \Db::getInstance()->getValue(
            'SELECT `id_snt_quote` FROM `' . _DB_PREFIX_ . 'snt_quote`
             WHERE (`id_snt_quote` = ' . $root . ' OR `id_root_quote` = ' . $root . ') AND `deleted` = 0
               AND `status` NOT IN (' . $hidden . ')
             ORDER BY `version` DESC'
        );
    }

    public function countAwaitingAction(int $idCustomer, int $idShop): int
    {
        return (int) \Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'snt_quote`
             WHERE `id_customer` = ' . $idCustomer . ' AND `id_shop` = ' . $idShop . ' AND `deleted` = 0
               AND `status` IN (\'sent\', \'viewed\')'
        );
    }
}
