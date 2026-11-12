<?php

declare(strict_types=1);

use Snt\Quotes\Config\ConfigKeys;
use Snt\Quotes\Conversion\PaymentMode;
use Snt\Quotes\Conversion\TechnicalCartBuilder;
use Snt\Quotes\Domain\Exception\QuoteException;
use Snt\Quotes\Domain\QuoteStatus;
use Snt\Quotes\Presenter\StatusPresenter;
use Snt\Quotes\ServiceFactory;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Page de paiement d'un devis par lien signé, SANS connexion (FO-05).
 *
 * Le lien donne accès à son seul devis. Le client accepte les CGV puis paie :
 * - carte HiPay Payments : page hébergée sur le panier technique, commande créée à la notification HiPay ;
 * - carte PayPlug : commande au nom de PayPlug, qui envoie son lien de paiement sécurisé ;
 * - virement / paiement différé : commande créée tout de suite.
 */
class Snt_QuotesPayModuleFrontController extends ModuleFrontController
{
    /** @var bool */
    public $ssl = true;

    /** @var bool */
    public $auth = false;

    private ?SntQuotePaymentLink $link = null;

    private ?SntQuote $quote = null;

    private string $token = '';

    private string $state = 'invalid';

    /** @var array<string, mixed> */
    private array $done = [];

    public function init(): void
    {
        parent::init();
        $this->token = (string) Tools::getValue('t');
        $this->link = ServiceFactory::paymentLinks()->resolve($this->token);
        if ($this->link) {
            $quote = new SntQuote((int) $this->link->id_snt_quote);
            if (Validate::isLoadedObject($quote) && !$quote->deleted && (int) $quote->id_shop === (int) $this->context->shop->id) {
                $this->quote = $quote;
            }
        }
        $this->state = $this->computeState();
    }

    public function setMedia(): void
    {
        parent::setMedia();
        $this->registerStylesheet('snt-quotes-front', 'modules/' . $this->module->name . '/views/css/front.css', ['media' => 'all', 'priority' => 200]);
    }

    public function postProcess(): void
    {
        if (!Tools::isSubmit('snt_quote_pay') || $this->state !== 'pay' || !$this->quote) {
            return;
        }
        if (!hash_equals($this->formKey(), (string) Tools::getValue('k'))) {
            $this->errors[] = $this->trans('Your session has expired. Reload the page and try again.', [], 'Modules.Sntquotes.Shop');

            return;
        }
        if (!Tools::getValue('cgv')) {
            $this->errors[] = $this->trans('Please accept the terms and conditions of sale to continue.', [], 'Modules.Sntquotes.Shop');

            return;
        }
        $method = (string) Tools::getValue('method');
        if (!in_array($method, array_column($this->methods(), 'value'), true)) {
            $this->errors[] = $this->trans('Choose a payment method.', [], 'Modules.Sntquotes.Shop');

            return;
        }

        try {
            $this->acceptIfNeeded($method);
            switch ($method) {
                case 'card_hipay':
                    $this->redirectToHiPay();
                    break;
                case 'card_payplug':
                    $result = ServiceFactory::converter()->convert($this->quote, PaymentMode::PayPlugLink, null, null, 'customer');
                    $this->done = ['type' => 'payplug', 'reference' => $result->orderReference];
                    break;
                case 'transfer':
                    $result = ServiceFactory::converter()->convert($this->quote, PaymentMode::Transfer, null, null, 'customer');
                    $this->done = ['type' => 'transfer', 'reference' => $result->orderReference];
                    break;
                case 'deferred':
                    $result = ServiceFactory::converter()->convert($this->quote, PaymentMode::Deferred, null, null, 'customer');
                    $this->done = ['type' => 'deferred', 'reference' => $result->orderReference];
                    break;
            }
            $this->quote = new SntQuote((int) $this->quote->id);
            $this->state = 'done';
        } catch (QuoteException $e) {
            PrestaShopLogger::addLog('[snt_quotes] pay page ' . $this->quote->getFullReference() . ': ' . $e->getMessage(), 2, null, 'SntQuote', (int) $this->quote->id);
            $this->errors[] = $this->trans('Your payment could not be started. Please try again or contact us.', [], 'Modules.Sntquotes.Shop');
        } catch (Throwable $e) {
            PrestaShopLogger::addLog('[snt_quotes] pay page ' . get_class($e) . ': ' . $e->getMessage(), 3, null, 'SntQuote', (int) $this->quote->id);
            $this->errors[] = $this->trans('Your payment could not be started. Please try again or contact us.', [], 'Modules.Sntquotes.Shop');
        }
    }

    public function initContent(): void
    {
        parent::initContent();

        if ($this->state === 'pay' && $this->quote && $this->quote->getStatus() === QuoteStatus::Sent) {
            ServiceFactory::stateMachine()->apply($this->quote, QuoteStatus::Viewed, null, ['via' => 'payment_link'], SntQuoteHistory::ACTOR_CUSTOMER, (int) $this->quote->id_customer);
        }

        $view = $this->quote ? ServiceFactory::presenter()->present($this->quote, true) : null;
        if ($view) {
            $view['status'] = (new StatusPresenter())->present($this->quote->getStatus(), StatusPresenter::DOMAIN_SHOP);
        }
        $employee = $view['employee'] ?? null;

        $this->context->smarty->assign([
            'snt_state' => $this->state,
            'snt_quote' => $view,
            'snt_te_first' => $this->quote ? ServiceFactory::priceDisplay()->taxExcludedFirst((int) $this->quote->id_shop, (int) $this->quote->id_customer) : false,
            'snt_methods' => $this->state === 'pay' ? $this->methods() : [],
            'snt_form_action' => $this->context->link->getModuleLink('snt_quotes', 'pay', ['t' => $this->token], true),
            'snt_form_key' => $this->state === 'pay' ? $this->formKey() : '',
            'snt_done' => $this->done,
            'snt_contact_email' => $employee['email'] ?? (string) Configuration::get('PS_SHOP_EMAIL'),
            'snt_cgv_url' => $this->cgvUrl(),
            'snt_iban' => ServiceFactory::config()->get(ConfigKeys::IBAN),
            'snt_bic' => ServiceFactory::config()->get(ConfigKeys::BIC),
            'snt_link_expires' => $this->link ? Tools::displayDate((string) $this->link->expires_at, true) : '',
            'snt_errors' => $this->errors,
        ]);

        $this->setTemplate('module:snt_quotes/views/templates/front/pay.tpl');
    }

    public function getBreadcrumbLinks(): array
    {
        $breadcrumb = parent::getBreadcrumbLinks();
        $breadcrumb['links'][] = [
            'title' => $this->trans('Quote payment', [], 'Modules.Sntquotes.Shop'),
            'url' => '',
        ];

        return $breadcrumb;
    }

    /**
     * @return array<string, mixed>
     */
    public function getTemplateVarPage(): array
    {
        $page = parent::getTemplateVarPage();
        $page['meta']['robots'] = 'noindex';
        $page['meta']['title'] = $this->trans('Quote payment', [], 'Modules.Sntquotes.Shop');

        return $page;
    }

    private function computeState(): string
    {
        if (!$this->link || !$this->quote) {
            return 'invalid';
        }
        $status = $this->quote->getStatus();
        if ($this->quote->id_order || in_array($status, [QuoteStatus::Ordered, QuoteStatus::Paid], true)) {
            return 'paid';
        }
        if ($status === QuoteStatus::Cancelled) {
            return 'replaced';
        }
        if ($this->link->revoked_at) {
            return 'revoked';
        }
        if ($this->link->used_at) {
            return 'paid';
        }
        if ($this->link->isExpired() || $status === QuoteStatus::Expired || ($status->isOpen() && $this->quote->isExpiredByDate())) {
            return 'expired';
        }
        if (!in_array($status, [QuoteStatus::Sent, QuoteStatus::Viewed, QuoteStatus::Accepted], true)) {
            return 'unavailable';
        }

        return 'pay';
    }

    /**
     * Moyens proposés : cochés sur le devis ET disponibles sur la boutique.
     *
     * @return array<int, array{value: string, label: string, help: string}>
     */
    private function methods(): array
    {
        if (!$this->quote) {
            return [];
        }
        $allowed = $this->quote->getAllowedPaymentMethods();
        $gateway = ServiceFactory::config()->get(ConfigKeys::PAYMENT_GATEWAY, (int) $this->quote->id_shop);
        $out = [];
        if (in_array('card', $allowed, true)) {
            if ($gateway === 'hipaypayments' && ServiceFactory::hipay()->isAvailable()) {
                $out[] = ['value' => 'card_hipay', 'label' => $this->trans('Pay by card now', [], 'Modules.Sntquotes.Shop'), 'help' => $this->trans('Secure payment page of our bank (3-D Secure).', [], 'Modules.Sntquotes.Shop')];
            } elseif ($gateway === 'payplug' && ServiceFactory::payplug()->isAvailable()) {
                $out[] = ['value' => 'card_payplug', 'label' => $this->trans('Pay by card', [], 'Modules.Sntquotes.Shop'), 'help' => $this->trans('You will receive a secure payment link by e-mail within a few minutes.', [], 'Modules.Sntquotes.Shop')];
            }
        }
        if (in_array('transfer', $allowed, true)) {
            $out[] = ['value' => 'transfer', 'label' => $this->trans('Accept and pay by bank transfer', [], 'Modules.Sntquotes.Shop'), 'help' => $this->trans('Your order is recorded; it is shipped once the transfer is received.', [], 'Modules.Sntquotes.Shop')];
        }
        if (in_array('deferred', $allowed, true)) {
            $out[] = ['value' => 'deferred', 'label' => $this->trans('Accept — payment on invoice', [], 'Modules.Sntquotes.Shop'), 'help' => $this->trans('Payment according to the terms agreed with your sales representative.', [], 'Modules.Sntquotes.Shop')];
        }

        return $out;
    }

    private function acceptIfNeeded(string $method): void
    {
        if (!$this->quote || !$this->quote->getStatus()->isOpen()) {
            return;
        }
        $this->quote->accepted_cgv_version = substr(date('Y-m-d'), 0, 32);
        ServiceFactory::stateMachine()->apply(
            $this->quote,
            QuoteStatus::Accepted,
            'Accepted online with the terms and conditions of sale',
            ['via' => 'payment_link', 'method' => $method],
            SntQuoteHistory::ACTOR_CUSTOMER,
            (int) $this->quote->id_customer
        );
    }

    private function redirectToHiPay(): void
    {
        $quote = $this->quote;
        $converter = ServiceFactory::converter();
        $cart = $converter->prepareCart($quote, 'hipay');
        $totals = TechnicalCartBuilder::quoteTotals($quote);
        $return = function (string $type): string {
            return $this->context->link->getModuleLink('snt_quotes', 'payreturn', ['t' => $this->token, 'r' => $type], true);
        };
        $url = ServiceFactory::hipay()->hostedPageUrl($quote, $cart, $totals['total_ti'], $totals['shipping_ti'], [
            'accept' => $return('accept'),
            'decline' => $return('decline'),
            'pending' => $return('pending'),
            'exception' => $return('exception'),
            'cancel' => $return('cancel'),
        ]);
        ServiceFactory::history()->log($quote, SntQuoteHistory::EVENT_PAYMENT_LINK, 'Redirected to the HiPay payment page', ['id_cart' => (int) $cart->id], null, null, SntQuoteHistory::ACTOR_CUSTOMER, (int) $quote->id_customer);
        Tools::redirect($url);
    }

    /**
     * Jeton de formulaire lié au lien (pas de session client nécessaire).
     */
    private function formKey(): string
    {
        return hash_hmac('sha256', 'snt_quote_pay|' . $this->token, _COOKIE_KEY_);
    }

    private function cgvUrl(): string
    {
        $idCms = (int) Configuration::get('PS_CONDITIONS_CMS_ID');

        return $idCms ? $this->context->link->getCMSLink($idCms) : '';
    }
}
