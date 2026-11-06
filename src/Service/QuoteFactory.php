<?php

declare(strict_types=1);

namespace Snt\Quotes\Service;

use Snt\Quotes\Domain\Exception\QuoteException;
use Snt\Quotes\Domain\QuoteStatus;
use Snt\Quotes\Presenter\QuotePresenter;

/**
 * Création de devis : brouillon vierge, depuis un panier client, duplication, nouvelle version.
 */
class QuoteFactory
{
    public function __construct(
        private readonly QuoteEditor $editor,
        private readonly ReferenceGenerator $references,
        private readonly QuotePresenter $presenter,
        private readonly HistoryLogger $history,
    ) {
    }

    /**
     * Devis vierge non enregistré, avec les valeurs de contexte (boutique, langue, devise, commercial).
     */
    public function blank(int $idCustomer = 0): \SntQuote
    {
        $context = \Context::getContext();
        $quote = new \SntQuote();
        $quote->id_shop = (int) $context->shop->id;
        $quote->id_shop_group = (int) $context->shop->id_shop_group;
        $quote->id_lang = (int) $context->language->id;
        $quote->id_currency = (int) $context->currency->id;
        $quote->id_employee = isset($context->employee) ? (int) $context->employee->id : null;
        $quote->id_customer = $idCustomer;
        $quote->status = QuoteStatus::Draft->value;
        if ($idCustomer) {
            $customer = new \Customer($idCustomer);
            if (\Validate::isLoadedObject($customer)) {
                $quote->id_lang = (int) $customer->id_lang ?: $quote->id_lang;
                $quote->id_address_delivery = (int) \Address::getFirstCustomerAddressId($idCustomer) ?: null;
                $quote->id_address_invoice = $quote->id_address_delivery;
            }
        }

        return $quote;
    }

    /**
     * Premier enregistrement d'un brouillon : attribue la référence puis enregistre le contenu.
     *
     * @param array<string, mixed> $payload
     */
    public function create(array $payload, string $source = 'bo'): EditorResultWithQuote
    {
        $quote = $this->blank((int) ($payload['id_customer'] ?? 0));
        $quote->reference = $this->references->next((int) $quote->id_shop);
        $quote->version = 1;
        $quote->source = $source;
        $quote->id_cart_source = isset($payload['id_cart_source']) ? ((int) $payload['id_cart_source'] ?: null) : null;
        $quote->id_order_source = isset($payload['id_order_source']) ? ((int) $payload['id_order_source'] ?: null) : null;
        $result = $this->editor->save($quote, $payload);

        return new EditorResultWithQuote($quote, $result);
    }

    /**
     * Lignes d'un panier client existant, au prix catalogue du client (le panier n'est pas modifié).
     *
     * @return array<int, array<string, mixed>>
     */
    public function linesFromCart(int $idCart, int $idCustomer): array
    {
        $cart = new \Cart($idCart);
        if (!\Validate::isLoadedObject($cart) || (int) $cart->id_customer !== $idCustomer) {
            throw new QuoteException('This cart does not belong to the selected customer.');
        }
        if (\Order::getIdByCartId($idCart)) {
            throw new QuoteException('This cart has already been ordered.');
        }
        $lines = [];
        foreach ($cart->getProducts(true) as $product) {
            $lines[] = [
                'line_type' => 'product',
                'id_product' => (int) $product['id_product'],
                'id_product_attribute' => (int) $product['id_product_attribute'],
                'qty' => (int) $product['cart_quantity'],
                'unit_price_te' => null, // prix catalogue du client, recalculé côté serveur
            ];
        }
        if ($lines === []) {
            throw new QuoteException('This cart is empty.');
        }

        return $lines;
    }

    /**
     * Copie d'un devis : nouveau devis (nouvelle référence) ou nouvelle version (même référence, v+1).
     */
    public function duplicate(\SntQuote $source, bool $asNewVersion): \SntQuote
    {
        $payload = $this->presenter->toEditorPayload($source);
        unset($payload['valid_until']); // nouvelle validité par défaut

        $quote = $this->blank((int) $source->id_customer);
        $quote->id_shop = (int) $source->id_shop;
        $quote->id_shop_group = (int) $source->id_shop_group;
        $quote->id_currency = (int) $source->id_currency;
        $quote->source = 'bo';
        if ($asNewVersion) {
            $quote->reference = (string) $source->reference;
            $quote->version = (int) \Db::getInstance()->getValue(
                'SELECT MAX(`version`) FROM `' . _DB_PREFIX_ . 'snt_quote`
                 WHERE `reference` = \'' . pSQL((string) $source->reference) . '\' AND `id_shop` = ' . (int) $source->id_shop
            ) + 1;
            $quote->id_parent_quote = (int) $source->id;
            $quote->id_root_quote = (int) ($source->id_root_quote ?: $source->id);
        } else {
            $quote->reference = $this->references->next((int) $source->id_shop);
            $quote->version = 1;
        }
        $this->editor->save($quote, $payload, false);
        $this->history->log($quote, \SntQuoteHistory::EVENT_CREATED, null, [
            'from_quote' => (int) $source->id,
            'from_reference' => $source->getFullReference(),
            'new_version' => $asNewVersion,
        ]);
        $this->history->log($source, \SntQuoteHistory::EVENT_NOTE, null, [
            'copied_to' => (int) $quote->id,
            'copied_to_reference' => $quote->getFullReference(),
        ]);

        return $quote;
    }
}
