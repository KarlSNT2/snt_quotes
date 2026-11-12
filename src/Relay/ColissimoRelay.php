<?php

declare(strict_types=1);

namespace Snt\Quotes\Relay;

use Snt\Quotes\Domain\Exception\QuoteException;

/**
 * Points de retrait du module officiel Colissimo (testé sur la v2.2.6 ; la recherche côté serveur
 * — ColissimoPointRetraitRequest — n'existe pas dans les versions plus anciennes, où le relais reste indisponible ici).
 *
 * - transporteur relais : service de type RELAIS dans la table colissimo_service ;
 * - recherche : même requête que le widget de la page de commande (controllers/front/widget.php),
 *   avec les identifiants enregistrés dans le module Colissimo ;
 * - commande : seule l'adresse de livraison est remplacée par celle du point de retrait (même forme que
 *   ColissimoTools::createAddressFromPickupPoint). Les tables d'étiquetage de Colissimo ne sont pas alimentées :
 *   les étiquettes sont produites par l'ERP.
 */
class ColissimoRelay
{
    public const PROVIDER = 'colissimo';

    public function isAvailable(): bool
    {
        // getInstanceByName() charge colissimo.php et son chargeur de classes.
        return \Module::isEnabled('colissimo')
            && \Module::getInstanceByName('colissimo') instanceof \Module
            && class_exists('ColissimoService')
            && class_exists('ColissimoPointRetraitRequest')
            && class_exists('ColissimoClient');
    }

    public function isRelayCarrier(int $idCarrier): bool
    {
        try {
            if ($idCarrier <= 0 || !$this->isAvailable()) {
                return false;
            }
            $carrier = new \Carrier($idCarrier);

            return \Validate::isLoadedObject($carrier)
                && $carrier->external_module_name === 'colissimo'
                && \ColissimoService::getServiceTypeByIdCarrier((int) $carrier->id_reference) === \ColissimoService::TYPE_RELAIS;
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
        if (trim($city) === '') {
            // Le service Colissimo refuse une recherche sans ville (erreur 105).
            throw new QuoteException('Enter the city as well to search for the pickup points of this carrier.');
        }

        try {
            $credentials = [];
            if (\Configuration::get('COLISSIMO_CONNEXION_KEY')) {
                $credentials['key'] = (string) \Configuration::get('COLISSIMO_ACCOUNT_KEY');
            } else {
                $credentials['contract_number'] = (string) \Configuration::get('COLISSIMO_ACCOUNT_LOGIN');
                $credentials['password'] = (string) \Configuration::get('COLISSIMO_ACCOUNT_PASSWORD');
                $credentials['partner_code'] = (string) \Configuration::get('COLISSIMO_ACCOUNT_PARENT_ID');
            }
            $payload = [
                'address' => trim($address),
                'zipCode' => $postcode,
                'city' => trim($city),
                'countryCode' => $countryIso,
                'weight' => '1',
                'shippingDate' => date('d/m/Y'),
                'filterRelay' => '1',
                'requestId' => 'SNTQ-' . time(),
                'lang' => 'FR',
                'optionInter' => $countryIso !== 'FR' ? '1' : '0',
                'origin' => 'API',
            ];
            $client = new \ColissimoClient();
            // Classe de Colissimo ≥ 2.2.6, vérifiée par isAvailable() ; absente des versions analysées plus anciennes.
            $client->setRequest(new \ColissimoPointRetraitRequest($credentials, $payload, false)); // @phpstan-ignore-line
            $response = $client->request();
            if ((int) $response->errorCode !== 0) {
                throw new \RuntimeException('error ' . (int) $response->errorCode . ' ' . (string) $response->errorMessage);
            }
            $points = is_array($response->points) ? $response->points : [];
        } catch (\Throwable $e) {
            \PrestaShopLogger::addLog('[snt_quotes] Colissimo pickup point search: ' . get_class($e) . ': ' . $e->getMessage(), 3);

            throw new QuoteException('The pickup point search is unavailable for this carrier. Try again in a few minutes.');
        }

        $out = [];
        foreach (array_slice($points, 0, 12) as $point) {
            if (!is_array($point)) {
                continue;
            }
            $id = trim((string) ($point['identifiant'] ?? ''));
            if ($id === '') {
                continue;
            }
            $out[] = [
                'provider' => self::PROVIDER,
                'relay_id' => $id,
                'name' => (string) ($point['nom'] ?? ''),
                'address' => trim((string) ($point['adresse1'] ?? '') . ' ' . (string) ($point['adresse2'] ?? '')),
                'zip' => (string) ($point['codePostal'] ?? ''),
                'city' => (string) ($point['localite'] ?? ''),
                'country' => strtoupper((string) ($point['codePays'] ?? $countryIso)),
                'distance_m' => isset($point['distanceEnMetre']) ? (int) $point['distanceEnMetre'] : null,
            ];
        }

        return $out;
    }

    /**
     * Adresse de livraison de la commande = point de retrait, au nom du client.
     *
     * @param array<string, mixed> $relay relay_data du devis
     */
    public function applyToOrder(\Order $order, \Cart $cart, array $relay): void
    {
        $idRelay = trim((string) ($relay['relay_id'] ?? ''));
        if ($idRelay === '') {
            return;
        }
        RelayAddress::replaceDeliveryAddress($order, $relay, 'COLISSIMO POINT PICKUP ' . $idRelay, (int) $order->id_customer);
    }
}
