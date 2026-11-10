<?php

declare(strict_types=1);

namespace Snt\Quotes\Relay;

/**
 * Remplace l'adresse de livraison d'une commande par celle d'un point relais, comme le font les modules
 * transporteurs à la validation d'une commande du site : société = nom du relais, nom et téléphones du client,
 * identifiant du relais dans « other », adresse masquée du carnet (deleted). C'est cette adresse que lit l'ERP.
 */
final class RelayAddress
{
    /**
     * @param array<string, mixed> $relay      relay_data du devis (relay_id, name, address, zip, city, country)
     * @param int|null             $idCustomer propriétaire de l'adresse (null : hors carnet, comme Chronopost)
     *
     * @return int id de l'adresse d'origine (0 si aucune)
     */
    public static function replaceDeliveryAddress(\Order $order, array $relay, string $alias, ?int $idCustomer): int
    {
        $idOriginal = (int) $order->id_address_delivery;
        $current = new \Address($idOriginal);
        $idCountry = (int) \Country::getByIso((string) ($relay['country'] ?? '')) ?: (int) $current->id_country;

        $address = new \Address();
        $address->alias = mb_substr($alias, 0, 32);
        $address->id_country = $idCountry;
        $address->company = mb_substr((string) ($relay['name'] ?? ''), 0, 255);
        $address->lastname = $current->lastname;
        $address->firstname = $current->firstname;
        $address->address1 = mb_substr((string) ($relay['address'] ?? ''), 0, 128);
        $address->address2 = '';
        $address->postcode = (string) ($relay['zip'] ?? '');
        $address->city = mb_substr((string) ($relay['city'] ?? ''), 0, 64);
        $address->phone = $current->phone;
        $address->phone_mobile = $current->phone_mobile;
        $address->other = (string) ($relay['relay_id'] ?? '');
        $address->active = false;
        $address->deleted = true;
        $address->id_customer = $idCustomer; // @phpstan-ignore-line (null accepté par le cœur)
        if (!$address->save()) {
            throw new \RuntimeException('Unable to save the pickup point address.');
        }

        \Db::getInstance()->update('orders', ['id_address_delivery' => (int) $address->id], '`id_order` = ' . (int) $order->id);
        $order->id_address_delivery = (int) $address->id;

        return $idOriginal;
    }
}
