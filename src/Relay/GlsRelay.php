<?php

declare(strict_types=1);

namespace Snt\Quotes\Relay;

use Snt\Quotes\Domain\Exception\QuoteException;

/**
 * Relais GLS du module Nukium « nkmgls » (analysé en 4.0.1).
 *
 * - transporteur relais : code GLS_RELAIS donné par le module (GlsCarrierRoutine::getCarrierCode) ;
 * - recherche : même appel que le widget de la page de commande (controllers/front/checkout.php, searchRelay),
 *   avec les identifiants API enregistrés dans le module GLS (SOAP ou ShipIt selon sa configuration) ;
 * - commande : l'adresse de livraison est remplacée par celle du relais, au nom du client (c'est elle que lit l'ERP).
 *   La table gls_cart_carrier est ensuite renseignée pour que la fiche commande GLS du BO affiche le relais.
 *   Elle l'est en fin de requête : si elle l'était pendant la validation, nkmgls (actionValidateOrderAfter)
 *   remplacerait l'adresse une seconde fois.
 */
class GlsRelay
{
    public const PROVIDER = 'gls';

    public function isAvailable(): bool
    {
        // getInstanceByName() charge nkmgls.php et l'autoload Composer du module (classes Nukium\…).
        return \Module::isEnabled('nkmgls')
            && \Module::getInstanceByName('nkmgls') instanceof \Module
            && class_exists('Nukium\PrestaShop\GLS\Service\Routine\GlsCarrierRoutine')
            && class_exists('Nukium\GLS\Common\Legacy\GlsController')
            && class_exists('Nukium\GLS\Common\Value\GlsValue');
    }

    public function isRelayCarrier(int $idCarrier): bool
    {
        try {
            if ($idCarrier <= 0 || !$this->isAvailable()) {
                return false;
            }
            $carrier = new \Carrier($idCarrier);

            return \Validate::isLoadedObject($carrier)
                && \Nukium\PrestaShop\GLS\Service\Routine\GlsCarrierRoutine::getInstance()->getCarrierCode($carrier) === \Nukium\GLS\Common\Value\GlsValue::GLS_RELAIS;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * @return array<int, array<string, mixed>> points au format du devis
     */
    public function search(int $idCarrier, string $postcode, string $countryIso, string $address = '', string $city = ''): array
    {
        if (!$this->isRelayCarrier($idCarrier)) {
            return [];
        }
        $countryIso = strtoupper(trim($countryIso)) ?: 'FR';

        try {
            $api = \Nukium\GLS\Common\Legacy\GlsController::createInstance([
                'GLS_IS_USING_SHIPIT_API' => \Configuration::get('GLS_IS_USING_SHIPIT_API'),
                'GLS_API_LOGIN' => \Configuration::get('GLS_API_LOGIN'),
                'GLS_API_PWD' => \Configuration::get('GLS_API_PWD'),
                'GLS_WSLOGIN' => \Configuration::get('GLS_WSLOGIN'),
                'GLS_WSPWD' => \Configuration::get('GLS_WSPWD'),
            ]);
            $result = $api->searchRelay($postcode, trim($city), $countryIso, trim($address), [
                'only_xl' => (int) \Configuration::get('GLS_GLSRELAIS_XL_ONLY') === 1,
            ]);
            if (!is_object($result) || !isset($result->exitCode->ErrorCode)) {
                throw new \RuntimeException('no response');
            }
            $code = (int) $result->exitCode->ErrorCode;
            if ($code === 998 || $code === 999) {
                return []; // aucun relais dans la zone
            }
            if ($code !== 0) {
                throw new \RuntimeException('error ' . $code . ' ' . (string) ($result->exitCode->ErrorDscr ?? ''));
            }
            $points = isset($result->SearchResults) && is_array($result->SearchResults) ? $result->SearchResults : [];
        } catch (\Throwable $e) {
            \PrestaShopLogger::addLog('[snt_quotes] GLS pickup point search: ' . get_class($e) . ': ' . $e->getMessage(), 3);

            throw new QuoteException('The pickup point search is unavailable for this carrier. Try again in a few minutes.');
        }

        $out = [];
        foreach (array_slice($points, 0, 12) as $point) {
            $shop = is_object($point) && isset($point->Parcelshop) ? $point->Parcelshop : null;
            if (!$shop || empty($shop->ParcelShopId) || !isset($shop->Address)) {
                continue;
            }
            $a = $shop->Address;
            $out[] = [
                'provider' => self::PROVIDER,
                'relay_id' => trim((string) $shop->ParcelShopId),
                'name' => trim((string) ($a->Name1 ?? '')),
                'address' => trim((string) ($a->Street1 ?? '') . ' ' . (string) ($a->Street2 ?? '')),
                'zip' => (string) ($a->ZipCode ?? ''),
                'city' => (string) ($a->City ?? ''),
                'country' => strtoupper((string) ($a->Country ?? $countryIso)),
                'distance_m' => isset($point->DistanceMeters) ? (int) $point->DistanceMeters : null,
            ];
        }

        return $out;
    }

    /**
     * Adresse de livraison de la commande = relais GLS, au nom du client.
     *
     * @param array<string, mixed> $relay relay_data du devis
     */
    public function applyToOrder(\Order $order, \Cart $cart, array $relay): void
    {
        $idRelay = trim((string) ($relay['relay_id'] ?? ''));
        if ($idRelay === '') {
            return;
        }
        $idOriginal = RelayAddress::replaceDeliveryAddress($order, $relay, 'GLS Relais ' . $idRelay, (int) $order->id_customer);

        $original = new \Address($idOriginal);
        $row = [
            'id_cart' => (int) $order->id_cart,
            'id_customer' => (int) $order->id_customer,
            'id_carrier' => (int) $order->id_carrier,
            'original_id_address_delivery' => $idOriginal,
            'gls_product' => '',
            'parcel_shop_id' => pSQL($idRelay),
            'name' => pSQL(mb_substr((string) ($relay['name'] ?? ''), 0, 255)),
            'address1' => pSQL(mb_substr((string) ($relay['address'] ?? ''), 0, 255)),
            'address2' => '',
            'postcode' => pSQL((string) ($relay['zip'] ?? '')),
            'city' => pSQL((string) ($relay['city'] ?? '')),
            'customer_phone_mobile' => pSQL((string) ($original->phone_mobile ?: $original->phone)),
            'id_country' => (int) \Country::getByIso((string) ($relay['country'] ?? 'FR')),
        ];
        register_shutdown_function(static function () use ($row): void {
            try {
                $db = \Db::getInstance();
                $table = _DB_PREFIX_ . 'gls_cart_carrier';
                if (!$db->executeS('SHOW TABLES LIKE \'' . pSQL($table) . '\'')) {
                    return;
                }
                $db->delete('gls_cart_carrier', '`id_cart` = ' . (int) $row['id_cart']);
                $db->insert('gls_cart_carrier', $row);
            } catch (\Throwable $e) {
                \PrestaShopLogger::addLog('[snt_quotes] GLS relay details for cart ' . (int) $row['id_cart'] . ': ' . $e->getMessage(), 2);
            }
        });
    }
}
