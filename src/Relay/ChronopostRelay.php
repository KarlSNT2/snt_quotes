<?php

declare(strict_types=1);

namespace Snt\Quotes\Relay;

use Snt\Quotes\Domain\Exception\QuoteException;

/**
 * Points relais du module officiel Chronopost (testé sur la v7.6.7).
 *
 * - transporteurs relais : ceux que le module reconnaît lui-même (Chronopost::isRelais) ;
 * - recherche : même service web et mêmes paramètres que sa page de commande (async/getPointRelais2.php),
 *   avec les identifiants de contrat enregistrés dans le module Chronopost ;
 * - commande : l'adresse de livraison est remplacée par celle du relais (c'est ce que lit l'ERP, qui produit les
 *   étiquettes), et le point est noté dans chrono_cart_relais. Le hook actionValidateOrder de Chronopost ne le
 *   fait pas pour nous, car à cet instant la commande porte encore le transporteur technique.
 */
class ChronopostRelay
{
    public const PROVIDER = 'chronopost';

    public function isAvailable(): bool
    {
        // getInstanceByName() charge chronopost.php (classe Chronopost, bibliothèques et autoload du module).
        return \Module::isEnabled('chronopost')
            && \Module::getInstanceByName('chronopost') instanceof \Module
            && class_exists('Chronopost', false)
            && method_exists('Chronopost', 'isRelais');
    }

    public function isRelayCarrier(int $idCarrier): bool
    {
        try {
            return $idCarrier > 0 && $this->isAvailable() && \Chronopost::isRelais($idCarrier);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * @return array<int, array<string, mixed>> points au format du devis (provider, relay_id, name, address, zip, city, country, distance_m)
     */
    public function search(int $idCarrier, string $postcode, string $countryIso, string $address = '', string $city = ''): array
    {
        if (!$this->isRelayCarrier($idCarrier)) {
            return [];
        }
        $countryIso = strtoupper(trim($countryIso)) ?: 'FR';

        try {
            include_once _PS_MODULE_DIR_ . 'chronopost/libraries/PointRelaisServiceWSService.php';
            $carrier = new \Carrier($idCarrier);
            $code = (string) (\Chronopost::getCodeFromCarrier($carrier->id_reference) ?: '');
            $definitions = \Chronopost\Libraries\Chronoconfig::getCarriersDefinition();
            $account = $this->account($code);

            $params = new \recherchePointChronopost();
            $params->accountNumber = $account['account'];
            $params->password = $account['password'];
            $params->productCode = (string) ($definitions[$code]['product_code'] ?? '');
            $params->zipCode = $postcode;
            $params->address = mb_substr(trim($address), 0, 60);
            if (trim($city) !== '') {
                $params->city = trim($city);
            }
            $params->countryCode = $countryIso;
            // Points de retrait (P) par défaut, ou le réglage du module ; ToShop Direct : commerces uniquement (C).
            $params->type = \Configuration::get('CHRONOPOST_MAP_DROPMODE') ?: 'P';
            if (in_array((int) $carrier->id_reference, array_map('intval', \Chronopost::getToShopIDs()), true)) {
                $params->type = 'C';
            }
            $params->service = 'L';
            $params->weight = 0;
            $params->shippingDate = date('d/m/Y');
            $params->maxPointChronopost = 11;
            $params->maxDistanceSearch = 40;
            $params->holidayTolerant = 1;

            $ws = new \PointRelaisServiceWSService();
            if (in_array($countryIso, ['FR', 'FX', 'MC'], true)) {
                $params->countryCode = \Chronopost::maybeCountryMapping($countryIso);
                $result = $ws->recherchePointChronopost($params)->return;
            } else {
                // Même appel que le module Chronopost (async/getPointRelais2.php) : le service accepte cet objet.
                $result = $ws->recherchePointChronopostInter($params)->return; // @phpstan-ignore-line
            }
        } catch (\Throwable $e) {
            \PrestaShopLogger::addLog('[snt_quotes] Chronopost relay search: ' . get_class($e) . ': ' . $e->getMessage(), 3);

            throw new QuoteException('The pickup point search is unavailable for this carrier. Try again in a few minutes.');
        }

        $points = $result->listePointRelais ?? [];
        if (is_object($points)) {
            $points = [$points]; // un seul résultat : SOAP renvoie l'objet seul
        }
        $out = [];
        foreach (is_array($points) ? $points : [] as $point) {
            $id = trim((string) ($point->identifiant ?? ''));
            if ($id === '') {
                continue;
            }
            $out[] = [
                'provider' => self::PROVIDER,
                'relay_id' => $id,
                'name' => (string) ($point->nom ?? ''),
                'address' => trim((string) ($point->adresse1 ?? '') . ' ' . (string) ($point->adresse2 ?? '')),
                'zip' => (string) ($point->codePostal ?? ''),
                'city' => (string) ($point->localite ?? ''),
                'country' => strtoupper((string) ($point->codePays ?? $countryIso)),
                'distance_m' => isset($point->distanceEnMetre) ? (int) $point->distanceEnMetre : null,
            ];
        }

        return $out;
    }

    /**
     * Après la remise du transporteur réel sur la commande : point relais pour l'étiquette et adresse de livraison.
     * Reproduit Chronopost::hookActionValidateOrder() à partir du point enregistré dans le devis (sans appel au
     * service web : la conversion ne dépend pas de sa disponibilité).
     *
     * @param array<string, mixed> $relay relay_data du devis
     */
    public function applyToOrder(\Order $order, \Cart $cart, array $relay): void
    {
        $idRelay = trim((string) ($relay['relay_id'] ?? ''));
        if ($idRelay === '') {
            return;
        }
        // L'adresse d'abord : c'est elle que lit l'ERP.
        $idOriginal = RelayAddress::replaceDeliveryAddress($order, $relay, 'Point ChronoRelais ' . $idRelay, null);

        // Puis les tables du module Chronopost, pour que la fiche commande du BO reste cohérente (point relais
        // et adresse d'origine). Sans conséquence sur la commande si elles sont absentes.
        try {
            $db = \Db::getInstance();
            $db->execute(
                'INSERT INTO `' . _DB_PREFIX_ . 'chrono_cart_relais` (`id_cart`, `id_pr`)
                 VALUES (' . (int) $cart->id . ', \'' . pSQL(mb_substr($idRelay, 0, 10)) . '\')
                 ON DUPLICATE KEY UPDATE `id_pr` = VALUES(`id_pr`)'
            );
            if ($idOriginal) {
                $db->insert('chrono_metadata', [
                    'id_entity' => (int) $order->id,
                    'entity_type' => 'order',
                    'meta_key' => 'delivery_address_original',
                    'meta_value' => $idOriginal,
                ], false, true, \Db::ON_DUPLICATE_KEY);
            }
        } catch (\Throwable $e) {
            \PrestaShopLogger::addLog('[snt_quotes] Chronopost tables: ' . $e->getMessage(), 2, null, 'Order', (int) $order->id);
        }
    }

    /**
     * Contrat du transporteur, sinon le premier contrat du module (comme sa page de commande).
     *
     * @return array<string, mixed>
     */
    private function account(string $code): array
    {
        $account = $code !== ''
            ? \Chronopost::getAccountInformationByAccountNumber(\Configuration::get('CHRONOPOST_' . $code . '_ACCOUNT'))
            : false;
        if (is_array($account) && !empty($account['account'])) {
            return $account;
        }
        $contracts = \Chronopost\Libraries\Chronoconfig::getContracts();
        if (!is_array($contracts) || empty($contracts[0]['account'])) {
            throw new \RuntimeException('No Chronopost contract configured.');
        }

        return $contracts[0];
    }
}
