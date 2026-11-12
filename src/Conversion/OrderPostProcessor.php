<?php

declare(strict_types=1);

namespace Snt\Quotes\Conversion;

use Snt\Quotes\Config\ConfigKeys;
use Snt\Quotes\Domain\LineType;
use Snt\Quotes\Domain\QuoteStatus;
use Snt\Quotes\Install\TechnicalSetup;
use Snt\Quotes\Relay\NativeRelays;
use Snt\Quotes\Service\HistoryLogger;
use Snt\Quotes\Service\QuoteStateMachine;

/**
 * Post-traitement d'une commande issue d'un panier technique de devis, quel que soit le module de paiement
 * (snt_quotes, HiPay Payments à la notification, PayPlug). Appelé depuis actionValidateOrder, donc AVANT
 * l'écriture du premier statut : les synchronisations par statut (n8n → OASIS) voient le transporteur réel.
 *
 * - transporteur réel sur la commande (orders + order_carrier) ;
 * - libellés des lignes libres sur order_detail ;
 * - bons d'origine décomptés ;
 * - devis → Commandé (et Accepté au passage si besoin), lien devis ↔ commande, liens de paiement consommés ;
 * - nettoyage des prix imposés et de la règle panier technique en fin de requête ;
 * - contrôle des totaux de la commande : en cas d'écart, statut « Erreur conversion devis ».
 */
class OrderPostProcessor
{
    /** @var array<int, string> commandes à basculer en erreur dès leur premier statut => motif */
    private static array $pendingErrors = [];

    /** @var string[] */
    private array $warnings = [];

    public function __construct(
        private readonly QuoteStateMachine $stateMachine,
        private readonly HistoryLogger $history,
        private readonly TechnicalSetup $setup,
        private readonly TechnicalCartBuilder $cartBuilder,
    ) {
    }

    /**
     * @return string[]
     */
    public function lastWarnings(): array
    {
        return $this->warnings;
    }

    public function process(\Order $order, \Cart $cart): void
    {
        $this->warnings = [];
        $idQuote = QuoteCartContext::quoteIdForCart((int) $cart->id);
        if (!$idQuote) {
            return;
        }
        $quote = new \SntQuote($idQuote);
        if (!\Validate::isLoadedObject($quote)) {
            return;
        }
        \Db::getInstance()->update('snt_quote_cart', ['id_order' => (int) $order->id], '`id_cart` = ' . (int) $cart->id);

        // Un devis = une commande. Un second paiement (ancienne page de paiement, double clic) est signalé.
        if ($quote->id_order && (int) $quote->id_order !== (int) $order->id) {
            $reason = sprintf('Second order for quote %s (already ordered: #%d). Refund or cancel it.', $quote->getFullReference(), (int) $quote->id_order);
            self::$pendingErrors[(int) $order->id] = $reason;
            $this->history->log($quote, \SntQuoteHistory::EVENT_NOTE, $reason, ['id_order' => (int) $order->id], null, null, \SntQuoteHistory::ACTOR_SYSTEM);

            return;
        }

        $this->assignRealCarrier($quote, $order);
        $this->applyNativeRelay($quote, $order, $cart);
        $this->renameFreeLines($quote, $order);
        $this->consumeOriginalVouchers($quote);
        $this->addOrderMessage($quote, $order);
        if ($quote->payment_mode === PaymentMode::Phone->value && $order->module === 'hipaypayments') {
            $this->registerHiPayMoto($order, $cart);
        }
        $this->checkTotals($quote, $order);

        $quote->id_order = (int) $order->id;
        $quote->id_cart = (int) $cart->id;
        if (!$quote->payment_mode) {
            $quote->payment_mode = $order->module === 'hipaypayments' ? PaymentMode::CardOnline->value : (string) $order->module;
        }
        $quote->update();

        $status = $quote->getStatus();
        if (in_array($status, [QuoteStatus::Sent, QuoteStatus::Viewed], true)) {
            $this->stateMachine->apply($quote, QuoteStatus::Accepted, 'Accepted by paying online', [], \SntQuoteHistory::ACTOR_CUSTOMER, (int) $quote->id_customer);
        }
        if ($quote->getStatus() === QuoteStatus::Accepted) {
            $this->stateMachine->apply($quote, QuoteStatus::Ordered, null, [
                'id_order' => (int) $order->id,
                'order_reference' => (string) $order->reference,
                'payment' => (string) $order->payment,
            ]);
        }

        \Db::getInstance()->update(
            'snt_quote_payment_link',
            ['used_at' => date('Y-m-d H:i:s')],
            '`id_snt_quote` = ' . (int) $quote->id . ' AND `used_at` IS NULL AND `revoked_at` IS NULL'
        );

        // Nettoyage en fin de requête : validateOrder() et le module de paiement relisent encore le panier.
        $idCart = (int) $cart->id;
        $builder = $this->cartBuilder;
        register_shutdown_function(static function () use ($builder, $idCart): void {
            try {
                $builder->cleanup($idCart);
            } catch (\Throwable $e) {
                \PrestaShopLogger::addLog('[snt_quotes] cleanup cart ' . $idCart . ': ' . $e->getMessage(), 2);
            }
        });
    }

    /**
     * Appelé à chaque changement de statut : bascule en erreur les commandes signalées.
     */
    public function onOrderStatusChanged(int $idOrder, int $idNewState): void
    {
        if (!isset(self::$pendingErrors[$idOrder])) {
            return;
        }
        $idError = (int) \Configuration::get(ConfigKeys::OS_ERROR);
        unset(self::$pendingErrors[$idOrder]);
        if (!$idError || $idNewState === $idError) {
            return;
        }
        $order = new \Order($idOrder);
        $history = new \OrderHistory();
        $history->id_order = $idOrder;
        $history->id_employee = 0;
        $history->changeIdOrderState($idError, $order, true);
        $history->add();
    }

    /**
     * Statut payé sur une commande issue d'un devis → devis « Payé ».
     */
    public function onOrderPaid(int $idOrder): void
    {
        $idQuote = (int) \Db::getInstance()->getValue('SELECT `id_snt_quote` FROM `' . _DB_PREFIX_ . 'snt_quote` WHERE `id_order` = ' . $idOrder);
        if (!$idQuote) {
            return;
        }
        $quote = new \SntQuote($idQuote);
        if ($quote->getStatus() === QuoteStatus::Ordered) {
            $this->stateMachine->apply($quote, QuoteStatus::Paid, null, ['id_order' => $idOrder], \SntQuoteHistory::ACTOR_SYSTEM);
        }
    }

    private function assignRealCarrier(\SntQuote $quote, \Order $order): void
    {
        $idReal = 0;
        if ($quote->id_carrier_reference) {
            $carrier = \Carrier::getCarrierByReference((int) $quote->id_carrier_reference);
            $idReal = $carrier instanceof \Carrier && \Validate::isLoadedObject($carrier) ? (int) $carrier->id : 0;
        }
        $idReal = $idReal ?: (int) $quote->id_carrier_real;
        if (!$idReal) {
            return; // retrait / livraison spéciale sans transporteur : on garde « Livraison selon devis »
        }
        \Db::getInstance()->update('orders', ['id_carrier' => $idReal], '`id_order` = ' . (int) $order->id);
        \Db::getInstance()->update('order_carrier', ['id_carrier' => $idReal], '`id_order` = ' . (int) $order->id);
        $order->id_carrier = $idReal;
    }

    /**
     * Point relais d'un module transporteur officiel (Chronopost, Colissimo) : l'adresse de livraison de la commande
     * devient celle du relais, une fois le transporteur réel remis sur la commande. Un échec ne bloque pas la commande : il est signalé à l'opérateur,
     * qui devra saisir le point relais à la main.
     */
    private function applyNativeRelay(\SntQuote $quote, \Order $order, \Cart $cart): void
    {
        $relay = $quote->getRelayData();
        $handler = $relay ? NativeRelays::get((string) ($relay['provider'] ?? '')) : null;
        if (!$relay || !$handler) {
            return;
        }
        try {
            if (!$handler->isRelayCarrier((int) $order->id_carrier)) {
                throw new \RuntimeException('the order carrier is not a pickup carrier of ' . (string) $relay['provider']);
            }
            $handler->applyToOrder($order, $cart, $relay);
        } catch (\Throwable $e) {
            $reason = 'Pickup point ' . (string) ($relay['relay_id'] ?? '') . ' could not be set on the order: ' . $e->getMessage();
            $this->warnings[] = $reason;
            $this->history->log($quote, \SntQuoteHistory::EVENT_NOTE, $reason, ['id_order' => (int) $order->id], null, null, \SntQuoteHistory::ACTOR_SYSTEM);
            \PrestaShopLogger::addLog('[snt_quotes] ' . $reason, 3, null, 'Order', (int) $order->id);
        }
    }

    private function renameFreeLines(\SntQuote $quote, \Order $order): void
    {
        $slots = [];
        foreach ($quote->getLines() as $line) {
            if ($line->getType() !== LineType::Free || (int) $line->qty <= 0) {
                continue;
            }
            $idTax = (int) $line->id_tax_rules_group;
            $slots[$idTax] = ($slots[$idTax] ?? 0) + 1;
            [$idProduct, $idAttribute] = $this->setup->freeLineSlot($idTax, $slots[$idTax]);
            \Db::getInstance()->update('order_detail', [
                'product_name' => pSQL(mb_substr((string) $line->label, 0, 255)),
                'product_reference' => pSQL((string) $line->reference),
            ], '`id_order` = ' . (int) $order->id . ' AND `product_id` = ' . $idProduct . ' AND `product_attribute_id` = ' . $idAttribute);
        }
    }

    private function consumeOriginalVouchers(\SntQuote $quote): void
    {
        foreach ($quote->getDiscounts() as $discount) {
            if (!$discount->id_cart_rule || $discount->is_dedicated) {
                continue;
            }
            \Db::getInstance()->execute(
                'UPDATE `' . _DB_PREFIX_ . 'cart_rule` SET `quantity` = GREATEST(`quantity` - 1, 0)
                 WHERE `id_cart_rule` = ' . (int) $discount->id_cart_rule
            );
        }
    }

    private function addOrderMessage(\SntQuote $quote, \Order $order): void
    {
        self::addPrivateOrderNote(
            $order,
            'Commande issue du devis ' . $quote->getFullReference()
                . ($quote->carrier_label ? ' — livraison : ' . $quote->carrier_label : ''),
            (int) ($quote->id_employee ?: 0)
        );
    }

    /**
     * Message privé (non visible du client) dans le bloc « Messages » de la fiche commande.
     * PrestaShop 8 / 9 n'y affiche que les messages des fils client (customer_message), pas l'ancienne table message.
     */
    public static function addPrivateOrderNote(\Order $order, string $text, int $idEmployee = 0): void
    {
        $text = trim(strip_tags($text));
        if ($text === '' || !\Validate::isCleanHtml($text)) {
            return;
        }
        try {
            $idThread = (int) \CustomerThread::getIdCustomerThreadByEmailAndIdOrder(
                (string) (new \Customer((int) $order->id_customer))->email,
                (int) $order->id
            );
            if (!$idThread) {
                $thread = new \CustomerThread();
                $thread->id_contact = 0;
                $thread->id_customer = (int) $order->id_customer;
                $thread->id_shop = (int) $order->id_shop;
                $thread->id_order = (int) $order->id;
                $thread->id_lang = (int) $order->id_lang;
                $thread->email = (string) (new \Customer((int) $order->id_customer))->email;
                $thread->status = 'closed';
                $thread->token = \Tools::passwdGen(12);
                if (!$thread->add()) {
                    return;
                }
                $idThread = (int) $thread->id;
            }
            $message = new \CustomerMessage();
            $message->id_customer_thread = $idThread;
            $message->id_employee = $idEmployee;
            $message->message = mb_substr($text, 0, 1600);
            $message->private = true;
            $message->read = true;
            $message->add();
        } catch (\Throwable $e) {
            \PrestaShopLogger::addLog('[snt_quotes] order note ' . (int) $order->id . ': ' . $e->getMessage(), 2, null, 'Order', (int) $order->id);
        }
    }

    private function registerHiPayMoto(\Order $order, \Cart $cart): void
    {
        if (!class_exists('HiPayPaymentsMotoOrder')) {
            return;
        }
        $moto = new \HiPayPaymentsMotoOrder();
        $moto->id_order = (int) $order->id;
        $moto->id_cart = (int) $cart->id;
        $moto->save();
    }

    private function checkTotals(\SntQuote $quote, \Order $order): void
    {
        $expected = TechnicalCartBuilder::quoteTotals($quote);
        $actual = [
            'total_te' => (float) $order->total_paid_tax_excl,
            'total_ti' => (float) $order->total_paid_tax_incl,
            'shipping_te' => (float) $order->total_shipping_tax_excl,
            'shipping_ti' => (float) $order->total_shipping_tax_incl,
        ];
        $diff = TotalsComparator::differences($expected, $actual);
        if ($diff === []) {
            return;
        }
        $reason = 'Order totals differ from the quote: ' . json_encode($diff);
        self::$pendingErrors[(int) $order->id] = $reason;
        $this->warnings[] = $reason;
        $this->history->log($quote, \SntQuoteHistory::EVENT_NOTE, $reason, ['id_order' => (int) $order->id, 'differences' => $diff], null, null, \SntQuoteHistory::ACTOR_SYSTEM);
        \PrestaShopLogger::addLog('[snt_quotes] ' . $reason, 3, null, 'Order', (int) $order->id);
    }
}
