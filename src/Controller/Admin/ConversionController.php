<?php

declare(strict_types=1);

namespace Snt\Quotes\Controller\Admin;

use Snt\Quotes\Config\ConfigKeys;
use Snt\Quotes\Config\ModuleConfig;
use Snt\Quotes\Conversion\PaymentMode;
use Snt\Quotes\Conversion\QuoteToOrderConverter;
use Snt\Quotes\Domain\Exception\QuoteException;
use Snt\Quotes\Mail\QuoteMailer;
use Snt\Quotes\Mail\SendOptions;
use Snt\Quotes\Payment\PaymentLinkService;
use Snt\Quotes\Presenter\QuotePresenter;
use Snt\Quotes\Service\Permission\QuotePermissions;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Conversion en commande (modale de confirmation) et liens de paiement, depuis la fiche devis.
 */
class ConversionController extends AbstractQuoteController
{
    public function __construct(
        TranslatorInterface $translator,
        QuotePermissions $permissions,
        private readonly QuoteToOrderConverter $converter,
        private readonly PaymentLinkService $paymentLinks,
        private readonly QuoteMailer $mailer,
        private readonly QuotePresenter $presenter,
        private readonly ModuleConfig $config,
    ) {
        parent::__construct($translator, $permissions);
    }

    /**
     * Contenu de la modale : résumé de l'impact, modes possibles, statuts, avertissements de stock.
     */
    public function previewAction(Request $request, int $quoteId): JsonResponse
    {
        return $this->jsonAction($request, 'convert', function () use ($quoteId): array {
            $quote = $this->loadQuote($quoteId);
            $this->converter->assertConvertible($quote);
            $view = $this->presenter->present($quote, true);
            $customer = $view['customer']['company'] ?: $view['customer']['name'];

            $methods = $quote->getAllowedPaymentMethods();
            $modes = [];
            foreach ($this->converter->availableModes($quote) as $mode) {
                $modes[] = [
                    'value' => $mode->value,
                    'label' => $this->t($mode->label()),
                    'help' => $this->modeHelp($mode),
                    'allowed' => in_array($mode->quoteMethod(), $methods, true),
                    'default_state' => $this->defaultState($mode, (int) $quote->id_shop),
                ];
            }
            $states = [];
            foreach (\OrderState::getOrderStates($this->langId()) as $state) {
                if (!$state['deleted']) {
                    $states[] = ['id' => (int) $state['id_order_state'], 'name' => (string) $state['name']];
                }
            }

            return [
                'summary' => $this->t('An order of %amount% incl. tax (%amount_te% excl. tax) will be created for %customer%, delivered by %carrier%.', [
                    '%amount%' => $view['total_ti_display'],
                    '%amount_te%' => $view['total_te_display'],
                    '%customer%' => $customer,
                    '%carrier%' => $view['carrier_label'] ?: '—',
                ]),
                'needs_acceptance' => $quote->getStatus()->isOpen(),
                'modes' => $modes,
                'states' => $states,
                'stock' => array_map(fn (array $w): string => $this->t('Line %n% « %label% »: %qty% ordered, %stock% in stock.', [
                    '%n%' => $w['line'], '%label%' => $w['label'], '%qty%' => $w['qty'], '%stock%' => $w['stock'],
                ]), $this->converter->stockWarnings($quote)),
            ];
        }, false);
    }

    public function convertAction(Request $request, int $quoteId): JsonResponse
    {
        return $this->jsonAction($request, 'convert', function () use ($request, $quoteId): array {
            $quote = $this->loadQuote($quoteId);
            $data = $this->jsonBody($request);
            $mode = PaymentMode::tryFrom((string) ($data['mode'] ?? ''));
            if (!$mode || !in_array($mode, $this->converter->availableModes($quote), true)) {
                throw new QuoteException('Choose how the order will be paid.');
            }
            if ($quote->getStatus()->isOpen() && empty($data['customer_agreed'])) {
                throw new QuoteException('Confirm that the customer agreed to the quote.');
            }
            $result = $this->converter->convert(
                $quote,
                $mode,
                trim((string) ($data['comment'] ?? '')) ?: null,
                (int) ($data['id_order_state'] ?? 0) ?: null
            );

            return [
                'message' => $this->t('Order %ref% created from the quote.', ['%ref%' => $result->orderReference])
                    . ($mode === PaymentMode::PayPlugLink ? ' ' . $this->t('PayPlug is sending the payment link to the customer.') : ''),
                'warnings' => $result->warnings,
                'redirect' => \Context::getContext()->link->getAdminLink('AdminOrders', true, ['route' => 'admin_orders_view', 'orderId' => $result->idOrder]),
            ];
        });
    }

    /**
     * Génère un lien de paiement (le précédent est révoqué), et l'envoie si demandé.
     */
    public function paymentLinkAction(Request $request, int $quoteId): JsonResponse
    {
        return $this->jsonAction($request, 'edit', function () use ($request, $quoteId): array {
            $quote = $this->loadQuote($quoteId);
            $data = $this->jsonBody($request);
            $link = $this->paymentLinks->create($quote);
            $sent = false;
            if (!empty($data['send'])) {
                $to = SendOptions::parseEmails($data['to'] ?? (new \Customer((int) $quote->id_customer))->email);
                if ($to === []) {
                    throw new QuoteException('Enter at least one valid recipient e-mail address.');
                }
                $this->mailer->sendPaymentLink($quote, $link['url'], $link['expires_at'], $to, trim((string) ($data['message'] ?? '')) ?: null);
                $sent = true;
            }

            return [
                'url' => $link['url'],
                'expires_at' => \Tools::displayDate($link['expires_at'], true),
                'message' => $sent
                    ? $this->t('Payment link sent. It is valid until %date%.', ['%date%' => \Tools::displayDate($link['expires_at'], true)])
                    : $this->t('Payment link created. Copy it now: for security, it cannot be displayed again.'),
            ];
        });
    }

    public function revokeLinkAction(Request $request, int $quoteId): JsonResponse
    {
        return $this->jsonAction($request, 'edit', function () use ($quoteId): array {
            $this->paymentLinks->revokeAll($this->loadQuote($quoteId));

            return ['message' => $this->t('The payment link has been deactivated.')];
        });
    }

    private function modeHelp(PaymentMode $mode): string
    {
        return match ($mode) {
            PaymentMode::Transfer => $this->t('The order waits for the bank transfer.'),
            PaymentMode::Deferred => $this->t('Order validated, payment on invoice (B2B customers).'),
            PaymentMode::Pending => $this->t('The order is created and will be paid later.'),
            PaymentMode::Phone => $this->t('The order is created on behalf of HiPay; enter the card from the HiPay block of the order page.'),
            PaymentMode::PayPlugLink => $this->t('The order is created on behalf of PayPlug, which e-mails its payment link to the customer.'),
            PaymentMode::CardOnline => '',
        };
    }

    private function defaultState(PaymentMode $mode, int $idShop): int
    {
        return match ($mode) {
            PaymentMode::Transfer => $this->config->getInt(ConfigKeys::OS_TRANSFER, $idShop) ?: (int) \Configuration::get('PS_OS_BANKWIRE'),
            PaymentMode::Deferred => $this->config->getInt(ConfigKeys::OS_DEFERRED, $idShop),
            PaymentMode::Pending, PaymentMode::Phone => $this->config->getInt(ConfigKeys::OS_PENDING, $idShop),
            default => 0,
        };
    }
}
