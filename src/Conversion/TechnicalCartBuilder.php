<?php

declare(strict_types=1);

namespace Snt\Quotes\Conversion;

use Snt\Quotes\Domain\Exception\QuoteException;
use Snt\Quotes\Domain\LineType;
use Snt\Quotes\Install\TechnicalSetup;

/**
 * Construit le panier technique d'un devis : lignes au prix figé (SpecificPrice liés au panier et au client),
 * remises en une règle panier dédiée au montant HT figé, transporteur technique, point relais éventuel.
 *
 * Puis vérifie, AVANT toute commande, que le cœur retrouve les totaux du devis au centime.
 */
class TechnicalCartBuilder
{
    public function __construct(private readonly TechnicalSetup $setup)
    {
    }

    public function build(\SntQuote $quote, string $purpose): \Cart
    {
        $customer = new \Customer((int) $quote->id_customer);
        if (!\Validate::isLoadedObject($customer) || $customer->deleted) {
            throw new QuoteException('The customer of this quote no longer exists.');
        }
        $idAddressDelivery = (int) ($quote->id_address_delivery ?: $quote->id_address_invoice);
        $idAddressInvoice = (int) ($quote->id_address_invoice ?: $idAddressDelivery);
        if (!$idAddressDelivery || !\Validate::isLoadedObject(new \Address($idAddressDelivery))) {
            throw new QuoteException('The quote has no valid delivery address. Edit it in a new version.');
        }
        $idCarrier = $this->setup->ensureCarrier();
        if (!$idCarrier) {
            throw new QuoteException('The « Delivery as quoted » carrier could not be created. Reinstall or update the module.');
        }

        $this->setShopContext((int) $quote->id_shop);
        $this->setup->alignCarrierTaxRule($idCarrier, (int) $quote->id_tax_rules_group_shipping, (int) $quote->id_shop);

        $cart = new \Cart();
        $cart->id_customer = (int) $customer->id;
        $cart->id_guest = 0;
        $cart->id_address_delivery = $idAddressDelivery;
        $cart->id_address_invoice = $idAddressInvoice;
        $cart->id_currency = (int) $quote->id_currency;
        $cart->id_lang = (int) $quote->id_lang;
        $cart->id_shop = (int) $quote->id_shop;
        $cart->id_shop_group = (int) $quote->id_shop_group;
        $cart->id_carrier = $idCarrier;
        $cart->secure_key = (string) $customer->secure_key;
        $cart->recyclable = false;
        $cart->gift = false;
        if (!$cart->add()) {
            throw new \RuntimeException('Unable to create the technical cart.');
        }

        try {
            $this->addLines($quote, $cart, (int) $customer->id_default_group);
            $this->addDiscount($quote, $cart);
            $this->addRelay($quote, $cart, $idCarrier);
            $cart->setDeliveryOption([$idAddressDelivery => $idCarrier . ',']);
            $cart->update();
            QuoteCartContext::register((int) $cart->id, (int) $quote->id, $purpose, (float) $quote->total_ti, (string) $quote->date_upd);
            QuoteCartContext::activateOverrides((int) $quote->id_shop, (int) $quote->id_shop_group);
        } catch (\Throwable $e) {
            $this->discard($cart);
            throw $e;
        }

        return $cart;
    }

    /**
     * Totaux recalculés par le cœur sur le panier technique.
     *
     * @return array{total_te: float, total_ti: float, shipping_te: float, shipping_ti: float}
     */
    public function cartTotals(\Cart $cart): array
    {
        QuoteCartContext::flushCaches();

        return [
            'total_te' => (float) $cart->getOrderTotal(false, \Cart::BOTH),
            'total_ti' => (float) $cart->getOrderTotal(true, \Cart::BOTH),
            'shipping_te' => (float) $cart->getOrderTotal(false, \Cart::ONLY_SHIPPING),
            'shipping_ti' => (float) $cart->getOrderTotal(true, \Cart::ONLY_SHIPPING),
        ];
    }

    /**
     * @return array{total_te: float, total_ti: float, shipping_te: float, shipping_ti: float}
     */
    public static function quoteTotals(\SntQuote $quote): array
    {
        $shippingTe = (float) $quote->total_shipping_te;
        $precision = (int) \Context::getContext()->getComputingPrecision();

        return [
            'total_te' => (float) $quote->total_te,
            'total_ti' => (float) $quote->total_ti,
            'shipping_te' => $shippingTe,
            'shipping_ti' => (float) \Tools::ps_round($shippingTe * (1 + (float) $quote->shipping_tax_rate / 100), $precision),
        ];
    }

    /**
     * Contrôle préalable : lève une exception explicite (et supprime le panier) si les totaux diffèrent.
     */
    public function assertMatchesQuote(\SntQuote $quote, \Cart $cart): void
    {
        $diff = TotalsComparator::differences(self::quoteTotals($quote), $this->cartTotals($cart));
        if ($diff === []) {
            return;
        }
        $this->discard($cart);
        $parts = [];
        foreach ($diff as $key => $values) {
            $parts[] = $key . ' ' . number_format($values['expected'], 2, ',', ' ') . ' ≠ ' . number_format($values['actual'], 2, ',', ' ');
        }
        \PrestaShopLogger::addLog('[snt_quotes] Totals mismatch for quote ' . $quote->getFullReference() . ': ' . implode(' ; ', $parts), 3, null, 'SntQuote', (int) $quote->id);

        throw new QuoteException(
            'The order would not match the quote to the cent (%details%). No order has been created. Check the shipping rules (free shipping threshold, fees module) and the customer group discounts, then try again.',
            ['%details%' => implode(' ; ', $parts)]
        );
    }

    /**
     * Supprime un panier technique non commandé et tout ce qui lui est rattaché.
     */
    public function discard(\Cart $cart): void
    {
        if (!$cart->id || \Order::getIdByCartId((int) $cart->id)) {
            return;
        }
        $this->cleanup((int) $cart->id);
        QuoteCartContext::forget((int) $cart->id);
        $cart->delete();
    }

    /**
     * Supprime les prix imposés et la règle panier technique d'un panier (après commande ou abandon).
     */
    public function cleanup(int $idCart): void
    {
        \Db::getInstance()->delete('specific_price', '`id_cart` = ' . $idCart);
        $rules = \Db::getInstance()->executeS(
            'SELECT ccr.`id_cart_rule` FROM `' . _DB_PREFIX_ . 'cart_cart_rule` ccr
             INNER JOIN `' . _DB_PREFIX_ . 'cart_rule` cr ON cr.`id_cart_rule` = ccr.`id_cart_rule`
             WHERE ccr.`id_cart` = ' . $idCart . ' AND cr.`code` LIKE \'SNTQ-%\''
        ) ?: [];
        foreach ($rules as $row) {
            $rule = new \CartRule((int) $row['id_cart_rule']);
            if (!\Order::getIdByCartId($idCart)) {
                $rule->delete();
            } else {
                // Commande passée : la règle est conservée pour l'historique de la commande, mais désactivée.
                $rule->active = false;
                $rule->quantity = 0;
                $rule->update();
            }
        }
        QuoteCartContext::flushCaches();
    }

    private function addLines(\SntQuote $quote, \Cart $cart, int $idGroup): void
    {
        $rows = [];
        $freeSlots = [];
        $useEcotax = (bool) \Configuration::get('PS_USE_ECOTAX');
        foreach ($quote->getLines() as $index => $line) {
            $type = $line->getType();
            if (!$type->hasAmount() || (int) $line->qty <= 0) {
                continue;
            }
            if ($type === LineType::Product) {
                $idProduct = (int) $line->id_product;
                $idAttribute = (int) $line->id_product_attribute;
                if (!\Validate::isLoadedObject(new \Product($idProduct))) {
                    throw new QuoteException('Line %n%: this product no longer exists. Create a new version of the quote without it.', ['%n%' => $index + 1]);
                }
            } else {
                $idTax = (int) $line->id_tax_rules_group;
                $freeSlots[$idTax] = ($freeSlots[$idTax] ?? 0) + 1;
                [$idProduct, $idAttribute] = $this->setup->freeLineSlot($idTax, $freeSlots[$idTax]);
            }
            $key = $idProduct . '-' . $idAttribute;
            $net = round((float) $line->unit_net_te, 6);
            if (isset($rows[$key])) {
                if (abs($rows[$key]['net'] - $net) > 0.000001) {
                    throw new QuoteException(
                        'Lines %a% and %b% contain the same product at different prices: PrestaShop cannot put them in the same order. Merge them in a new version of the quote.',
                        ['%a%' => $rows[$key]['line'] + 1, '%b%' => $index + 1]
                    );
                }
                $rows[$key]['qty'] += (int) $line->qty;
                continue;
            }
            $rows[$key] = ['id_product' => $idProduct, 'id_product_attribute' => $idAttribute, 'qty' => (int) $line->qty, 'net' => $net, 'line' => $index];
        }
        if ($rows === []) {
            throw new QuoteException('The quote has no product or free line.');
        }

        $now = date('Y-m-d H:i:s');
        foreach ($rows as $row) {
            \Db::getInstance()->insert('cart_product', [
                'id_cart' => (int) $cart->id,
                'id_product' => $row['id_product'],
                'id_product_attribute' => $row['id_product_attribute'],
                'id_address_delivery' => (int) $cart->id_address_delivery,
                'id_shop' => (int) $cart->id_shop,
                'id_customization' => 0,
                'quantity' => $row['qty'],
                'date_add' => $now,
            ]);

            $ecotax = $useEcotax ? $this->ecotax($row['id_product'], $row['id_product_attribute'], (int) $cart->id_currency) : 0.0;
            $specificPrice = new \SpecificPrice();
            $specificPrice->id_specific_price_rule = 0;
            $specificPrice->id_cart = (int) $cart->id;
            $specificPrice->id_product = $row['id_product'];
            $specificPrice->id_product_attribute = $row['id_product_attribute'];
            $specificPrice->id_shop = (int) $cart->id_shop;
            $specificPrice->id_shop_group = 0;
            $specificPrice->id_currency = (int) $cart->id_currency;
            $specificPrice->id_country = 0;
            $specificPrice->id_group = 0;
            $specificPrice->id_customer = (int) $cart->id_customer;
            $specificPrice->price = ImposedPrice::specificPrice($row['net'], $ecotax, $this->groupReduction($row['id_product'], $idGroup));
            $specificPrice->from_quantity = 1;
            $specificPrice->reduction = 0;
            $specificPrice->reduction_tax = 0;
            $specificPrice->reduction_type = 'amount';
            $specificPrice->from = '0000-00-00 00:00:00';
            $specificPrice->to = '0000-00-00 00:00:00';
            if (!$specificPrice->add()) {
                throw new \RuntimeException('Unable to fix the quote price on the technical cart.');
            }
        }
    }

    /**
     * Toutes les remises sur produits (globale, bons, bon dédié) en une règle panier HT au montant figé,
     * réservée au client, utilisable une fois. Les bons d'origine sont décomptés à la commande.
     */
    private function addDiscount(\SntQuote $quote, \Cart $cart): void
    {
        $amount = round((float) $quote->total_discount_te, 6);
        if ($amount <= 0) {
            return;
        }
        $rule = new \CartRule();
        $rule->name = [];
        foreach (\Language::getLanguages(false) as $lang) {
            $rule->name[(int) $lang['id_lang']] = ($lang['iso_code'] === 'fr' ? 'Remises du devis ' : 'Quote discounts ') . $quote->getFullReference();
        }
        $rule->code = 'SNTQ-' . strtoupper(substr(bin2hex(random_bytes(6)), 0, 10));
        $rule->id_customer = (int) $cart->id_customer;
        $rule->date_from = date('Y-m-d H:i:s', time() - 86400);
        $rule->date_to = date('Y-m-d H:i:s', time() + 365 * 86400);
        $rule->quantity = 1;
        $rule->quantity_per_user = 1;
        $rule->priority = 1;
        $rule->partial_use = false;
        $rule->highlight = false;
        $rule->active = true;
        $rule->minimum_amount = 0;
        $rule->reduction_amount = $amount;
        $rule->reduction_tax = false;
        $rule->reduction_currency = (int) $cart->id_currency;
        $rule->reduction_product = 0;
        $rule->cart_rule_restriction = false;
        $rule->description = 'snt_quotes:' . (int) $quote->id;
        if (!$rule->add()) {
            throw new \RuntimeException('Unable to create the quote discount on the technical cart.');
        }
        $cart->addCartRule((int) $rule->id);
    }

    /**
     * Point relais choisi dans le devis → table de snt_carriers, qui matérialise l'adresse à la commande.
     */
    private function addRelay(\SntQuote $quote, \Cart $cart, int $idTechnicalCarrier): void
    {
        $relay = $quote->getRelayData();
        if (!$relay || !\Module::isEnabled('snt_carriers')) {
            return;
        }
        $payload = [
            'id' => (string) ($relay['relay_id'] ?? ''),
            'name' => (string) ($relay['name'] ?? ''),
            'address' => (string) ($relay['address'] ?? ''),
            'zip' => (string) ($relay['zip'] ?? ''),
            'city' => (string) ($relay['city'] ?? ''),
            'country' => (string) ($relay['country'] ?? ''),
        ];
        \Db::getInstance()->insert('snt_cart_relay', [
            'id_cart' => (int) $cart->id,
            'id_shop' => (int) $cart->id_shop,
            'id_carrier' => (int) ($quote->id_carrier_real ?: $idTechnicalCarrier),
            'provider' => pSQL((string) ($relay['provider'] ?? '')),
            'relay_id' => pSQL($payload['id']),
            'payload_json' => pSQL((string) json_encode($payload), true),
            'date_upd' => date('Y-m-d H:i:s'),
        ], false, true, \Db::ON_DUPLICATE_KEY);
    }

    private function ecotax(int $idProduct, int $idAttribute, int $idCurrency): float
    {
        $ecotax = 0.0;
        if ($idAttribute) {
            $ecotax = (float) \Db::getInstance()->getValue('SELECT `ecotax` FROM `' . _DB_PREFIX_ . 'product_attribute` WHERE `id_product_attribute` = ' . $idAttribute);
        }
        if ($ecotax <= 0) {
            $ecotax = (float) \Db::getInstance()->getValue('SELECT `ecotax` FROM `' . _DB_PREFIX_ . 'product` WHERE `id_product` = ' . $idProduct);
        }

        return $ecotax > 0 ? (float) \Tools::convertPrice($ecotax, $idCurrency) : 0.0;
    }

    /**
     * Remise de groupe que le cœur appliquera au prix imposé (même règle que Product::priceCalculation).
     */
    private function groupReduction(int $idProduct, int $idGroup): float
    {
        $fromCategory = \GroupReduction::getValueForProduct($idProduct, $idGroup);
        if ($fromCategory !== false) {
            return (float) $fromCategory;
        }

        return (float) \Group::getReductionByIdGroup($idGroup) / 100;
    }

    private function setShopContext(int $idShop): void
    {
        $context = \Context::getContext();
        if ((int) $context->shop->id !== $idShop) {
            $context->shop = new \Shop($idShop);
        }
        \Shop::setContext(\Shop::CONTEXT_SHOP, $idShop);
    }
}
