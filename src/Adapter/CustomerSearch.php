<?php

declare(strict_types=1);

namespace Snt\Quotes\Adapter;

/**
 * Recherche client unique : nom, e-mail, société, SIRET.
 */
class CustomerSearch
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function search(string $term, int $limit = 15): array
    {
        $term = trim($term);
        if (mb_strlen($term) < 2) {
            return [];
        }
        $like = '\'%' . pSQL(str_replace(['%', '_'], ['\%', '\_'], $term)) . '%\'';
        $shops = implode(',', array_map('intval', \Shop::getContextListShopID() ?: [(int) \Context::getContext()->shop->id]));
        $words = array_filter(preg_split('/\s+/', $term) ?: []);
        $nameClause = '';
        if (count($words) >= 2) {
            $nameClause = ' OR (CONCAT(c.`firstname`, \' \', c.`lastname`) LIKE ' . $like . ' OR CONCAT(c.`lastname`, \' \', c.`firstname`) LIKE ' . $like . ')';
        }

        $rows = \Db::getInstance()->executeS(
            'SELECT c.`id_customer`, c.`firstname`, c.`lastname`, c.`email`, c.`company`, c.`siret`, c.`id_lang`
             FROM `' . _DB_PREFIX_ . 'customer` c
             WHERE c.`deleted` = 0 AND c.`id_shop` IN (' . $shops . ')
               AND (c.`email` LIKE ' . $like . ' OR c.`lastname` LIKE ' . $like . ' OR c.`firstname` LIKE ' . $like . '
                    OR c.`company` LIKE ' . $like . ' OR c.`siret` LIKE ' . $like . $nameClause . ')
             ORDER BY c.`company` = \'\', c.`company`, c.`lastname`
             LIMIT ' . max(1, $limit)
        ) ?: [];

        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id_customer'],
            'name' => trim($r['firstname'] . ' ' . $r['lastname']),
            'company' => (string) $r['company'],
            'email' => (string) $r['email'],
            'siret' => (string) $r['siret'],
            'label' => trim(($r['company'] ? $r['company'] . ' — ' : '') . $r['firstname'] . ' ' . $r['lastname'] . ' <' . $r['email'] . '>'),
        ], $rows);
    }

    /**
     * Fiche courte d'un client + ses adresses (listes déroulantes de l'éditeur).
     *
     * @return array<string, mixed>|null
     */
    public function details(int $idCustomer, int $idLang): ?array
    {
        $customer = new \Customer($idCustomer);
        if (!\Validate::isLoadedObject($customer) || $customer->deleted) {
            return null;
        }
        $addresses = [];
        foreach ($customer->getAddresses($idLang) as $a) {
            $addresses[] = [
                'id' => (int) $a['id_address'],
                'alias' => (string) $a['alias'],
                'label' => trim(implode(', ', array_filter([
                    $a['company'] ?? '',
                    trim($a['firstname'] . ' ' . $a['lastname']),
                    $a['address1'],
                    trim($a['postcode'] . ' ' . $a['city']),
                    $a['country'] ?? '',
                ]))),
                'postcode' => (string) $a['postcode'],
                'city' => (string) $a['city'],
                'country_iso' => (string) \Country::getIsoById((int) $a['id_country']),
                'vat_number' => (string) ($a['vat_number'] ?? ''),
            ];
        }
        $group = new \Group((int) $customer->id_default_group, $idLang);

        return [
            'id' => (int) $customer->id,
            'name' => trim($customer->firstname . ' ' . $customer->lastname),
            'company' => (string) $customer->company,
            'email' => (string) $customer->email,
            'siret' => (string) $customer->siret,
            'group' => (string) $group->name,
            'price_display_tax_excluded' => (bool) \Group::getPriceDisplayMethod((int) $customer->id_default_group),
            'addresses' => $addresses,
        ];
    }
}
