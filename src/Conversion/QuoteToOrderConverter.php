<?php

declare(strict_types=1);

namespace Snt\Quotes\Conversion;

use Snt\Quotes\Config\ConfigKeys;
use Snt\Quotes\Config\ModuleConfig;
use Snt\Quotes\Domain\Exception\QuoteException;
use Snt\Quotes\Domain\LineType;
use Snt\Quotes\Domain\QuoteStatus;
use Snt\Quotes\Payment\PayPlugGateway;
use Snt\Quotes\Service\QuoteStateMachine;

/**
 * Devis → commande, sans tunnel.
 *
 * 1. verrou + contrôles (une seule commande par devis) ;
 * 2. panier technique au prix figé + contrôle des totaux au centime AVANT toute commande ;
 * 3. validateOrder() du module de paiement choisi (snt_quotes, HiPay Payments, PayPlug) ;
 * 4. post-traitement dans actionValidateOrder (OrderPostProcessor) : transporteur réel, lignes libres, lien devis ↔ commande.
 */
class QuoteToOrderConverter
{
    public function __construct(
        private readonly TechnicalCartBuilder $cartBuilder,
        private readonly OrderPostProcessor $postProcessor,
        private readonly QuoteStateMachine $stateMachine,
        private readonly ModuleConfig $config,
        private readonly PayPlugGateway $payPlug,
    ) {
    }

    /**
     * Modes de conversion possibles pour ce devis et cette boutique.
     *
     * @return PaymentMode[]
     */
    public function availableModes(\SntQuote $quote): array
    {
        $modes = [PaymentMode::Transfer, PaymentMode::Deferred, PaymentMode::Pending];
        if (\Module::isEnabled('hipaypayments')) {
            $modes[] = PaymentMode::Phone;
        }
        if ($this->config->get(ConfigKeys::PAYMENT_GATEWAY, (int) $quote->id_shop) === 'payplug' && $this->payPlug->isAvailable()) {
            $modes[] = PaymentMode::PayPlugLink;
        }

        return $modes;
    }

    /**
     * Avertissements non bloquants (stock), affichés dans la modale de confirmation.
     *
     * @return array<int, array{line: int, label: string, stock: int, qty: int}>
     */
    public function stockWarnings(\SntQuote $quote): array
    {
        $out = [];
        foreach ($quote->getLines() as $i => $line) {
            if ($line->getType() !== LineType::Product) {
                continue;
            }
            $stock = (int) \StockAvailable::getQuantityAvailableByProduct((int) $line->id_product, (int) $line->id_product_attribute ?: null, (int) $quote->id_shop);
            if ($stock < (int) $line->qty) {
                $out[] = ['line' => $i + 1, 'label' => (string) $line->label, 'stock' => $stock, 'qty' => (int) $line->qty];
            }
        }

        return $out;
    }

    public function assertConvertible(\SntQuote $quote): void
    {
        if ($quote->id_order) {
            throw new QuoteException('This quote has already been converted into an order.');
        }
        $status = $quote->getStatus();
        if (!in_array($status, [QuoteStatus::Accepted, QuoteStatus::Sent, QuoteStatus::Viewed], true)) {
            throw new QuoteException('Only an accepted (or sent) quote can be converted into an order. Current status: « %status% ».', ['%status%' => $status->label()]);
        }
        if ($quote->delivery_mode === 'carrier' && !$quote->id_carrier_real) {
            throw new QuoteException('The quote has no carrier. Create a new version with a carrier.');
        }
    }

    /**
     * Prépare et contrôle le panier technique sans créer de commande (paiement en ligne HiPay).
     */
    public function prepareCart(\SntQuote $quote, string $purpose): \Cart
    {
        $this->assertConvertible($quote);
        $cart = $this->cartBuilder->build($quote, $purpose);
        $this->cartBuilder->assertMatchesQuote($quote, $cart);

        return $cart;
    }

    /**
     * Crée la commande. $actorType : employee (BO) ou customer (FO, page de paiement).
     */
    public function convert(\SntQuote $quote, PaymentMode $mode, ?string $comment = null, ?int $idOrderState = null, string $actorType = 'employee'): ConversionResult
    {
        if ($mode === PaymentMode::CardOnline) {
            throw new \LogicException('Online card payments are converted by the HiPay notification.');
        }
        $lock = 'snt_quote_' . (int) $quote->id;
        if (!(int) \Db::getInstance()->getValue('SELECT GET_LOCK(\'' . pSQL($lock) . '\', 15)', false)) {
            throw new QuoteException('Another conversion of this quote is in progress. Wait a few seconds, then reload the page.');
        }

        try {
            $quote = new \SntQuote((int) $quote->id); // état à jour sous verrou
            $this->assertConvertible($quote);
            if (in_array($quote->getStatus(), [QuoteStatus::Sent, QuoteStatus::Viewed], true)) {
                $this->stateMachine->apply($quote, QuoteStatus::Accepted, $comment ?: 'Accepted when converting into an order', ['payment_mode' => $mode->value], $actorType === 'customer' ? \SntQuoteHistory::ACTOR_CUSTOMER : null, $actorType === 'customer' ? (int) $quote->id_customer : null);
            }

            $cart = $this->cartBuilder->build($quote, 'conversion');
            $this->cartBuilder->assertMatchesQuote($quote, $cart);

            [$module, $state, $paymentLabel] = $this->moduleAndState($quote, $mode, $idOrderState);
            $quote->payment_mode = $mode->value;
            $quote->update();

            // Le commentaire n'est pas passé à validateOrder() : PrestaShop l'y range dans l'ancienne table « message »,
            // invisible sur la fiche commande. Il est ajouté plus bas en message privé de la commande.
            $idOrder = $this->validate($quote, $cart, $module, $state, $paymentLabel, null);
            if (!$idOrder) {
                throw new \RuntimeException('validateOrder() did not create the order.');
            }
            $order = new \Order($idOrder);

            // Si le hook n'a pas tourné (module non accroché), on post-traite ici.
            $quote = new \SntQuote((int) $quote->id);
            if ((int) $quote->id_order !== $idOrder) {
                $this->postProcessor->process($order, $cart);
            }
            if ($comment !== null && trim($comment) !== '') {
                $idEmployee = $actorType === 'employee' ? (int) (\Context::getContext()->employee->id ?? 0) : 0;
                OrderPostProcessor::addPrivateOrderNote($order, $comment, $idEmployee);
            }

            return new ConversionResult($idOrder, (string) $order->reference, $this->postProcessor->lastWarnings());
        } finally {
            \Db::getInstance()->getValue('SELECT RELEASE_LOCK(\'' . pSQL($lock) . '\')', false);
        }
    }

    /**
     * @return array{0: \PaymentModule, 1: int, 2: string}
     */
    private function moduleAndState(\SntQuote $quote, PaymentMode $mode, ?int $idOrderState): array
    {
        $idShop = (int) $quote->id_shop;
        switch ($mode) {
            case PaymentMode::Phone:
                $module = \Module::getInstanceByName('hipaypayments');
                $state = $idOrderState ?: $this->config->getInt(ConfigKeys::OS_PENDING, $idShop);
                $label = 'HiPay Payments [MO/TO]';
                break;
            case PaymentMode::PayPlugLink:
                $module = \Module::getInstanceByName('payplug');
                $state = $this->payPlug->emailLinkStateId();
                $label = 'PayPlug [lien de paiement]';
                if (!$state) {
                    throw new QuoteException('The PayPlug « payment link by e-mail » status is not configured in the PayPlug module.');
                }
                break;
            case PaymentMode::Transfer:
                $module = \Module::getInstanceByName('snt_quotes');
                $state = $idOrderState ?: ($this->config->getInt(ConfigKeys::OS_TRANSFER, $idShop) ?: (int) \Configuration::get('PS_OS_BANKWIRE'));
                $label = 'Virement (devis)';
                break;
            case PaymentMode::Deferred:
                $module = \Module::getInstanceByName('snt_quotes');
                $state = $idOrderState ?: $this->config->getInt(ConfigKeys::OS_DEFERRED, $idShop);
                $label = 'Paiement différé B2B (devis)';
                break;
            default:
                $module = \Module::getInstanceByName('snt_quotes');
                $state = $idOrderState ?: $this->config->getInt(ConfigKeys::OS_PENDING, $idShop);
                $label = 'Devis — en attente de paiement';
        }
        if (!$module instanceof \PaymentModule || !\Module::isEnabled((string) $module->name)) {
            throw new QuoteException('The payment module required for this method is not enabled.');
        }
        if (!$state || !\Validate::isLoadedObject(new \OrderState($state))) {
            throw new QuoteException('The initial order status for this payment method is not configured (settings, « Payment » tab).');
        }

        return [$module, $state, $label];
    }

    private function validate(\SntQuote $quote, \Cart $cart, \PaymentModule $module, int $idState, string $paymentLabel, ?string $comment): int
    {
        $context = \Context::getContext();
        $saved = [
            'cart' => $context->cart,
            'customer' => $context->customer,
            'currency' => $context->currency,
            'language' => $context->language,
            'country' => $context->country,
        ];
        $customer = new \Customer((int) $cart->id_customer);
        $context->cart = $cart;
        $context->customer = $customer;
        $context->currency = new \Currency((int) $cart->id_currency);
        $context->language = new \Language((int) $cart->id_lang);
        $context->country = new \Country((int) (new \Address((int) $cart->id_address_invoice))->id_country);
        $totals = TechnicalCartBuilder::quoteTotals($quote);

        try {
            $module->validateOrder(
                (int) $cart->id,
                $idState,
                $totals['total_ti'],
                $paymentLabel,
                $comment ? strip_tags($comment) : null,
                [],
                (int) $cart->id_currency,
                false,
                (string) $customer->secure_key,
                new \Shop((int) $cart->id_shop)
            );

            return (int) $module->currentOrder;
        } finally {
            foreach ($saved as $key => $value) {
                $context->{$key} = $value;
            }
        }
    }
}
