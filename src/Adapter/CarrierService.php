<?php

declare(strict_types=1);

namespace Snt\Quotes\Adapter;

use Snt\Quotes\Config\ConfigKeys;

/**
 * Transporteurs pour l'éditeur de devis.
 *
 * - TOUS les transporteurs actifs sont proposés, sans filtre (BO-03) ;
 * - l'éligibilité selon les règles (zone, groupe client, tranches poids/prix) n'est qu'un avertissement ;
 * - le « tarif calculé » passe par le calcul natif d'un panier temporaire, donc par les règles
 *   orderfees_shipping / Advanced Shipping Cost (D7), à titre indicatif.
 */
class CarrierService
{
    /**
     * @param array<int, array{id_product: int, id_product_attribute: int, qty: int, unit_price_te: float}> $products
     *
     * @return array<int, array<string, mixed>>
     */
    public function listForQuote(int $idAddressDelivery, int $idCustomer, array $products, int $idLang): array
    {
        $technicalReference = (int) \Configuration::get(ConfigKeys::CARRIER_ID_REFERENCE);
        $idZone = $idAddressDelivery ? (int) \Address::getZoneById($idAddressDelivery) : 0;
        $groups = $idCustomer ? array_map('intval', \Customer::getGroupsStatic($idCustomer)) : [(int) \Configuration::get('PS_UNIDENTIFIED_GROUP')];
        [$weight, $amount] = $this->weightAndAmount($products);
        $relayDefs = $this->relayDefinitions();

        $out = [];
        foreach (\Carrier::getCarriers($idLang, true, false, false, null, \Carrier::ALL_CARRIERS) as $row) {
            $idCarrier = (int) $row['id_carrier'];
            if ($technicalReference && (int) $row['id_reference'] === $technicalReference) {
                continue;
            }
            $warnings = [];
            if ($idZone && !\Carrier::checkCarrierZone($idCarrier, $idZone)) {
                $warnings[] = 'zone';
            }
            $carrierGroups = array_map(static fn (array $g): int => (int) $g['id_group'], (new \Carrier($idCarrier))->getGroups() ?: []);
            if ($carrierGroups && !array_intersect($groups, array_map('intval', $carrierGroups))) {
                $warnings[] = 'group';
            }
            if ($idZone && !$row['is_free'] && $row['need_range'] !== '0') {
                $carrier = new \Carrier($idCarrier);
                $method = (int) $carrier->getShippingMethod();
                if ($method === \Carrier::SHIPPING_METHOD_WEIGHT && !\Carrier::checkDeliveryPriceByWeight($idCarrier, $weight, $idZone)) {
                    $warnings[] = 'weight';
                } elseif ($method === \Carrier::SHIPPING_METHOD_PRICE && !\Carrier::checkDeliveryPriceByPrice($idCarrier, $amount, $idZone, (int) \Context::getContext()->currency->id)) {
                    $warnings[] = 'price';
                }
            }
            if ((float) $row['max_weight'] > 0 && $weight > (float) $row['max_weight']) {
                $warnings[] = 'max_weight';
            }

            $relay = $relayDefs[$idCarrier] ?? null;
            $out[] = [
                'id' => $idCarrier,
                'name' => (string) $row['name'],
                'delay' => (string) $row['delay'],
                'logo' => is_file(_PS_SHIP_IMG_DIR_ . $idCarrier . '.jpg') ? _THEME_SHIP_DIR_ . $idCarrier . '.jpg' : null,
                'is_free' => (bool) $row['is_free'],
                'eligible' => $warnings === [],
                'warnings' => $warnings,
                'is_relay' => $relay !== null,
                'relay_provider' => $relay['provider'] ?? null,
                'relay_s2s' => !empty($relay['s2s']),
            ];
        }

        usort($out, static fn (array $a, array $b): int => [$b['eligible'], $a['name']] <=> [$a['eligible'], $b['name']]);

        return $out;
    }

    /**
     * Tarif HT selon les règles de la boutique, via un panier temporaire supprimé aussitôt.
     * Renvoie null si le transporteur ne peut pas livrer ce panier selon les règles.
     *
     * @param array<int, array{id_product: int, id_product_attribute: int, qty: int, unit_price_te: float}> $products
     */
    public function calculatedShipping(int $idCarrier, int $idCustomer, int $idAddressDelivery, int $idAddressInvoice, int $idCurrency, int $idLang, array $products): ?float
    {
        $products = array_filter($products, static fn (array $p): bool => $p['id_product'] > 0 && $p['qty'] > 0);
        if (!$products || !$idAddressDelivery) {
            return null;
        }
        $context = \Context::getContext();
        $previousCart = $context->cart;
        $previousCustomer = $context->customer;

        $cart = new \Cart();
        $cart->id_customer = $idCustomer;
        $cart->id_address_delivery = $idAddressDelivery;
        $cart->id_address_invoice = $idAddressInvoice ?: $idAddressDelivery;
        $cart->id_currency = $idCurrency;
        $cart->id_lang = $idLang;
        $cart->id_shop = (int) $context->shop->id;
        $cart->id_shop_group = (int) $context->shop->id_shop_group;
        $cart->id_carrier = $idCarrier;
        $cart->secure_key = $idCustomer ? (string) (new \Customer($idCustomer))->secure_key : md5(uniqid('', true));
        if (!$cart->add()) {
            return null;
        }

        try {
            $now = date('Y-m-d H:i:s');
            foreach ($products as $p) {
                \Db::getInstance()->insert('cart_product', [
                    'id_cart' => (int) $cart->id,
                    'id_product' => (int) $p['id_product'],
                    'id_product_attribute' => (int) $p['id_product_attribute'],
                    'id_address_delivery' => $idAddressDelivery,
                    'id_shop' => (int) $cart->id_shop,
                    'quantity' => (int) $p['qty'],
                    'date_add' => $now,
                ], false, true, \Db::ON_DUPLICATE_KEY);
            }
            $context->cart = $cart;
            $context->customer = $idCustomer ? new \Customer($idCustomer) : $previousCustomer;
            $cart->setDeliveryOption([$idAddressDelivery => $idCarrier . ',']);
            $cost = $cart->getPackageShippingCost($idCarrier, false);

            return $cost === false || $cost === null ? null : round((float) $cost, 6);
        } catch (\Throwable $e) {
            \PrestaShopLogger::addLog('[snt_quotes] calculatedShipping: ' . $e->getMessage(), 2);

            return null;
        } finally {
            $context->cart = $previousCart;
            $context->customer = $previousCustomer;
            $cart->delete();
        }
    }

    /**
     * Points relais (snt_carriers) autour d'un code postal.
     *
     * @return array<int, array<string, mixed>>
     */
    public function searchRelays(int $idCarrier, string $postcode, string $countryIso): array
    {
        $def = $this->relayDefinitions()[$idCarrier] ?? null;
        if (!$def || !class_exists('RelayProviderFactory')) {
            return [];
        }
        $provider = \RelayProviderFactory::make((string) $def['provider'], ['s2s' => !empty($def['s2s'])]);
        $points = $provider->search($postcode, strtoupper($countryIso));

        return array_map(static fn (array $p): array => [
            'provider' => (string) $def['provider'],
            'relay_id' => (string) ($p['id'] ?? ''),
            'name' => (string) ($p['name'] ?? ''),
            'address' => (string) ($p['address'] ?? ''),
            'zip' => (string) ($p['zip'] ?? ''),
            'city' => (string) ($p['city'] ?? ''),
            'country' => strtoupper($countryIso),
            'distance_m' => isset($p['distance_m']) ? (int) $p['distance_m'] : null,
        ], is_array($points) ? $points : []);
    }

    /**
     * Transporteurs relais déclarés par snt_carriers, indexés par id_carrier courant.
     *
     * @return array<int, array<string, mixed>>
     */
    private function relayDefinitions(): array
    {
        $module = \Module::isEnabled('snt_carriers') ? \Module::getInstanceByName('snt_carriers') : null;
        if (!$module || !method_exists($module, 'getCarrierDefs')) {
            return [];
        }
        $out = [];
        foreach ((array) $module->getCarrierDefs() as $def) {
            if (($def['type'] ?? '') !== 'relay') {
                continue;
            }
            $id = (int) \Configuration::get('SNT_CARRIER_ID_' . strtoupper((string) $def['code']));
            if ($id) {
                $out[$id] = $def;
            }
        }

        return $out;
    }

    /**
     * @param array<int, array{id_product: int, id_product_attribute: int, qty: int, unit_price_te: float}> $products
     *
     * @return array{0: float, 1: float}
     */
    private function weightAndAmount(array $products): array
    {
        $weight = 0.0;
        $amount = 0.0;
        foreach ($products as $p) {
            if ($p['id_product'] > 0) {
                $weight += $this->unitWeight($p['id_product'], $p['id_product_attribute']) * $p['qty'];
            }
            $amount += $p['unit_price_te'] * $p['qty'];
        }

        return [$weight, $amount];
    }

    private function unitWeight(int $idProduct, int $idProductAttribute): float
    {
        $weight = (float) \Db::getInstance()->getValue('SELECT `weight` FROM `' . _DB_PREFIX_ . 'product` WHERE `id_product` = ' . $idProduct);
        if ($idProductAttribute) {
            $weight += (float) \Db::getInstance()->getValue(
                'SELECT `weight` FROM `' . _DB_PREFIX_ . 'product_attribute` WHERE `id_product_attribute` = ' . $idProductAttribute
            );
        }

        return $weight;
    }
}
