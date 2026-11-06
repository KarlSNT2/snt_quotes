<?php

declare(strict_types=1);

namespace Snt\Quotes\Repository;

use Snt\Quotes\Domain\QuoteStatus;

/**
 * Lectures SQL des devis (listes, intégrations BO). Les écritures passent par les ObjectModel.
 */
class QuoteRepository
{
    private function t(string $table): string
    {
        return '`' . _DB_PREFIX_ . $table . '`';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findLatestForCustomer(int $idCustomer, int $limit = 5): array
    {
        return \Db::getInstance()->executeS(
            'SELECT q.`id_snt_quote`, q.`reference`, q.`version`, q.`status`, q.`total_ti`, q.`total_te`, q.`id_currency`,
                    q.`valid_until`, q.`date_add`, q.`id_order`, q.`project_name`
             FROM ' . $this->t('snt_quote') . ' q
             WHERE q.`id_customer` = ' . $idCustomer . ' AND q.`deleted` = 0
             ORDER BY q.`date_add` DESC
             LIMIT ' . max(1, $limit)
        ) ?: [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByOrderId(int $idOrder): ?array
    {
        $row = \Db::getInstance()->getRow(
            'SELECT `id_snt_quote`, `reference`, `version`, `status`, `total_ti`, `id_currency`
             FROM ' . $this->t('snt_quote') . ' WHERE `id_order` = ' . $idOrder
        );

        return $row ?: null;
    }

    public function findIdByReference(string $reference, int $idShop, ?int $version = null): int
    {
        return (int) \Db::getInstance()->getValue(
            'SELECT `id_snt_quote` FROM ' . $this->t('snt_quote') . '
             WHERE `reference` = \'' . pSQL($reference) . '\' AND `id_shop` = ' . $idShop . '
             ' . ($version ? 'AND `version` = ' . $version : '') . '
             ORDER BY `version` DESC'
        );
    }

    /**
     * Compteurs des onglets rapides de la liste.
     *
     * @param int[] $shopIds
     *
     * @return array{todo: int, waiting: int, all: int}
     */
    public function countTabs(array $shopIds): array
    {
        $shops = implode(',', array_map('intval', $shopIds ?: [0]));
        $quote = static fn (array $statuses): string => implode(',', array_map(static fn (QuoteStatus $s): string => '\'' . $s->value . '\'', $statuses));

        $row = \Db::getInstance()->getRow(
            'SELECT
                SUM(CASE WHEN `status` IN (' . $quote(QuoteStatus::toProcess()) . ') AND `id_order` IS NULL THEN 1 ELSE 0 END) AS todo,
                SUM(CASE WHEN `status` IN (' . $quote(QuoteStatus::waitingCustomer()) . ') THEN 1 ELSE 0 END) AS waiting,
                COUNT(*) AS total
             FROM ' . $this->t('snt_quote') . '
             WHERE `deleted` = 0 AND `id_shop` IN (' . $shops . ')'
        ) ?: [];

        return [
            'todo' => (int) ($row['todo'] ?? 0),
            'waiting' => (int) ($row['waiting'] ?? 0),
            'all' => (int) ($row['total'] ?? 0),
        ];
    }

    /**
     * Versions d'un même devis (même racine), la plus récente en premier.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findVersions(\SntQuote $quote): array
    {
        $root = (int) ($quote->id_root_quote ?: $quote->id);

        return \Db::getInstance()->executeS(
            'SELECT `id_snt_quote`, `version`, `status`, `total_ti`, `date_add`
             FROM ' . $this->t('snt_quote') . '
             WHERE (`id_snt_quote` = ' . $root . ' OR `id_root_quote` = ' . $root . ') AND `deleted` = 0
             ORDER BY `version` DESC'
        ) ?: [];
    }

    /**
     * Paniers non commandés d'un client (reprise panier → devis).
     *
     * @return array<int, array<string, mixed>>
     */
    public function findOpenCartsForCustomer(int $idCustomer, int $idShop, int $limit = 10): array
    {
        return \Db::getInstance()->executeS(
            'SELECT c.`id_cart`, c.`date_upd`, c.`id_currency`,
                    COUNT(cp.`id_product`) AS nb_lines, COALESCE(SUM(cp.`quantity`), 0) AS nb_products
             FROM ' . $this->t('cart') . ' c
             INNER JOIN ' . $this->t('cart_product') . ' cp ON cp.`id_cart` = c.`id_cart`
             LEFT JOIN ' . $this->t('orders') . ' o ON o.`id_cart` = c.`id_cart`
             LEFT JOIN ' . $this->t('snt_quote') . ' q ON q.`id_cart` = c.`id_cart`
             WHERE c.`id_customer` = ' . $idCustomer . ' AND c.`id_shop` = ' . $idShop . '
               AND o.`id_order` IS NULL AND q.`id_snt_quote` IS NULL
             GROUP BY c.`id_cart`
             ORDER BY c.`date_upd` DESC
             LIMIT ' . max(1, $limit)
        ) ?: [];
    }
}
