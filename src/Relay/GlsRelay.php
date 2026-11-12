<?php

declare(strict_types=1);

namespace Snt\Quotes\Relay;

use Snt\Quotes\Domain\Exception\QuoteException;

/**
 * Relais GLS du module Nukium « nkmgls » — versions 3.x (testé : 3.1.2 sur la DEV) et 4.x (analysé : 4.0.1).
 *
 * - transporteur relais : référence du transporteur « GLSRELAIS » du module, lue dans sa configuration
 *   (4.x : GLS_CARRIER_MAPPING en JSON ; 3.x : GLS_GLSRELAIS_ID et l'historique GLS_GLSRELAIS_LOG), sans dépendre
 *   des classes internes du module, qui changent d'une version à l'autre ;
 * - recherche : en 4.x, même appel que le widget de la page de commande (GlsController::searchRelay, API SOAP ou ShipIt
 *   selon sa configuration) ; en 3.x, service SOAP GLS « ParcelShopSearch » avec les identifiants du module ;
 * - commande : l'adresse de livraison est remplacée par celle du relais, au nom du client (c'est elle que lit l'ERP).
 *   La table gls_cart_carrier est ensuite renseignée pour que la fiche commande GLS du BO affiche le relais.
 *   Elle l'est en fin de requête : si elle l'était pendant la validation, nkmgls (actionValidateOrderAfter)
 *   remplacerait l'adresse une seconde fois.
 */
class GlsRelay
{
    public const PROVIDER = 'gls';

    private const CARRIER_KEY = 'GLSRELAIS';

    private const SOAP_WSDL = 'https://www.gls-group.eu/276-I-PORTAL-WEBSERVICE/services/ParcelShopSearch/wsdl/2010_01_ParcelShopSearch.wsdl';

    /** @var int[]|null */
    private ?array $references = null;

    public function isAvailable(): bool
    {
        return \Module::isEnabled('nkmgls');
    }

    public function isRelayCarrier(int $idCarrier): bool
    {
        try {
            if ($idCarrier <= 0 || !$this->isAvailable()) {
                return false;
            }
            $carrier = new \Carrier($idCarrier);

            return \Validate::isLoadedObject($carrier) && in_array((int) $carrier->id_reference, $this->relayReferences(), true);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Références (id_reference) du transporteur « GLS Relais », actuel et anciens.
     *
     * @return int[]
     */
    private function relayReferences(): array
    {
        if ($this->references !== null) {
            return $this->references;
        }
        $refs = [];
        $mapping = json_decode((string) \Configuration::get('GLS_CARRIER_MAPPING'), true);
        if (is_array($mapping) && isset($mapping[self::CARRIER_KEY]) && is_array($mapping[self::CARRIER_KEY])) {
            $refs[] = (int) ($mapping[self::CARRIER_KEY]['id_reference'] ?? 0);
            foreach ((array) ($mapping[self::CARRIER_KEY]['history'] ?? []) as $ref) {
                $refs[] = (int) $ref;
            }
        }
        $ids = array_merge(
            [(int) \Configuration::get('GLS_' . self::CARRIER_KEY . '_ID')],
            array_map('intval', explode('|', (string) \Configuration::get('GLS_' . self::CARRIER_KEY . '_LOG')))
        );
        foreach (array_filter($ids) as $id) {
            $carrier = new \Carrier($id);
            if (\Validate::isLoadedObject($carrier)) {
                $refs[] = (int) $carrier->id_reference;
            }
        }

        return $this->references = array_values(array_unique(array_filter($refs)));
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
            $result = $this->callApi($postcode, trim($city), $countryIso, trim($address));
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
            $points = $result->SearchResults ?? [];
            $points = is_array($points) ? $points : (is_object($points) ? [$points] : []); // SOAP : un seul résultat = objet
            if ((int) \Configuration::get('GLS_GLSRELAIS_XL_ONLY') === 1) {
                $points = array_values(array_filter($points, static fn ($p): bool => is_object($p) && isset($p->Parcelshop->Address->Name1)
                    && strtoupper(substr((string) $p->Parcelshop->Address->Name1, -2)) === 'XL'));
            }
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
     * Appel de l'API GLS : celle du module en 4.x, sinon le service SOAP historique (3.x).
     */
    private function callApi(string $postcode, string $city, string $countryIso, string $street): ?object
    {
        \Module::getInstanceByName('nkmgls'); // charge le module et son autoload éventuel
        if (class_exists('Nukium\GLS\Common\Legacy\GlsController')) {
            $api = \Nukium\GLS\Common\Legacy\GlsController::createInstance([
                'GLS_IS_USING_SHIPIT_API' => \Configuration::get('GLS_IS_USING_SHIPIT_API'),
                'GLS_API_LOGIN' => \Configuration::get('GLS_API_LOGIN'),
                'GLS_API_PWD' => \Configuration::get('GLS_API_PWD'),
                'GLS_WSLOGIN' => \Configuration::get('GLS_WSLOGIN'),
                'GLS_WSPWD' => \Configuration::get('GLS_WSPWD'),
            ]);
            $result = $api->searchRelay($postcode, $city, $countryIso, $street, ['only_xl' => false]);

            return is_object($result) ? $result : null;
        }

        if (!extension_loaded('soap')) {
            throw new \RuntimeException('PHP soap extension missing');
        }
        $client = new \SoapClient(self::SOAP_WSDL, ['exceptions' => true, 'connection_timeout' => 15, 'cache_wsdl' => WSDL_CACHE_BOTH]);
        $credentials = ['UserName' => (string) \Configuration::get('GLS_WSLOGIN'), 'Password' => (string) \Configuration::get('GLS_WSPWD')];
        $address = ['ZipCode' => $postcode, 'Country' => $countryIso, 'City' => $city, 'Name1' => '', 'Street1' => $street];
        $response = $client->GetParcelShops(['Credentials' => $credentials, 'Address' => $address]);
        $code = (int) ($response->exitCode->ErrorCode ?? -1);
        if ($code === 998 || $code === 999) {
            // Même repli que le module : recherche sur le seul code postal.
            $address = ['ZipCode' => $postcode, 'Country' => $countryIso, 'City' => '', 'Name1' => '', 'Street1' => ''];
            $response = $client->GetParcelShops(['Credentials' => $credentials, 'Address' => $address]);
        }
        if (is_object($response) && isset($response->SearchResults)) {
            $points = is_array($response->SearchResults) ? $response->SearchResults : [$response->SearchResults];
            $response->SearchResults = array_values(array_filter($points, static fn ($p): bool => is_object($p)
                && strtoupper((string) ($p->Parcelshop->Address->Country ?? '')) === $countryIso));
        }

        return is_object($response) ? $response : null;
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
