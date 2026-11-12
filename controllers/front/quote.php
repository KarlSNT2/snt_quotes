<?php

declare(strict_types=1);

use Snt\Quotes\Domain\Exception\QuoteException;
use Snt\Quotes\Domain\QuoteStatus;
use Snt\Quotes\Presenter\StatusPresenter;
use Snt\Quotes\ServiceFactory;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Détail d'un devis dans l'espace client (FO-03, FO-04, FO-06) : récapitulatif identique au PDF,
 * PDF, fil d'échanges, et actions : accepter et payer, demander une modification, refuser.
 * Un client n'accède qu'à ses devis de la boutique courante.
 */
class Snt_QuotesQuoteModuleFrontController extends ModuleFrontController
{
    /** @var bool */
    public $auth = true;

    /** @var bool */
    public $ssl = true;

    /** @var string */
    public $authRedirection = 'my-account';

    private ?SntQuote $quote = null;

    /** @var string[] */
    private array $success = [];

    public function init(): void
    {
        parent::init();
        if (!$this->context->customer->isLogged()) {
            return; // redirection vers la connexion gérée par le parent ($auth)
        }
        $this->quote = ServiceFactory::customerQuotes()->findForCustomer(
            (int) Tools::getValue('id_quote'),
            (int) $this->context->customer->id,
            (int) $this->context->shop->id
        );
        if (!$this->quote) {
            Tools::redirect($this->context->link->getModuleLink('snt_quotes', 'quotes', [], true));
        }
        // Version remplacée : on montre la version en cours.
        if ($this->quote->getStatus() === QuoteStatus::Cancelled) {
            $latest = ServiceFactory::customerQuotes()->latestVersionId($this->quote);
            if ($latest && $latest !== (int) $this->quote->id) {
                Tools::redirect($this->context->link->getModuleLink('snt_quotes', 'quote', ['id_quote' => $latest], true));
            }
        }
    }

    public function setMedia(): void
    {
        parent::setMedia();
        $this->registerStylesheet('snt-quotes-front', 'modules/' . $this->module->name . '/views/css/front.css', ['media' => 'all', 'priority' => 200]);
    }

    public function postProcess(): void
    {
        if (!$this->quote) {
            return;
        }
        if (Tools::getValue('pdf')) {
            $this->downloadPdf();
        }
        $action = (string) Tools::getValue('snt_action');
        if ($action === '') {
            return;
        }
        if (!Tools::isSubmit('snt_action') || Tools::getValue('token') !== Tools::getToken(false)) {
            $this->errors[] = $this->trans('Your session has expired. Reload the page and try again.', [], 'Modules.Sntquotes.Shop');

            return;
        }
        $customerId = (int) $this->context->customer->id;
        $text = trim((string) Tools::getValue('message'));
        $machine = ServiceFactory::stateMachine();
        $quote = $this->quote;

        try {
            switch ($action) {
                case 'pay':
                    if (!$this->canPay()) {
                        throw new QuoteException('This quote can no longer be accepted online.');
                    }
                    $link = ServiceFactory::paymentLinks()->create($quote);
                    Tools::redirect($link['url']);
                    break;

                case 'revision':
                    if (!$quote->getStatus()->canTransitionTo(QuoteStatus::RevisionRequested)) {
                        throw new QuoteException('A change can no longer be requested for this quote.');
                    }
                    if ($text === '') {
                        throw new QuoteException('Tell us what you would like to change.');
                    }
                    ServiceFactory::messages()->add($quote, 'customer', $customerId, $text);
                    $machine->apply($quote, QuoteStatus::RevisionRequested, mb_substr($text, 0, 500), [], SntQuoteHistory::ACTOR_CUSTOMER, $customerId);
                    ServiceFactory::mailer()->notifyEmployeeCustomerAction($quote, 'revision', $text);
                    $this->success[] = $this->trans('Your request has been sent. Your sales representative will send you an updated quote.', [], 'Modules.Sntquotes.Shop');
                    break;

                case 'decline':
                    if (!$quote->getStatus()->canTransitionTo(QuoteStatus::Declined)) {
                        throw new QuoteException('This quote can no longer be declined.');
                    }
                    if ($text !== '') {
                        ServiceFactory::messages()->add($quote, 'customer', $customerId, $text);
                    }
                    $machine->apply($quote, QuoteStatus::Declined, $text !== '' ? mb_substr($text, 0, 500) : null, [], SntQuoteHistory::ACTOR_CUSTOMER, $customerId);
                    ServiceFactory::paymentLinks()->revokeAll($quote);
                    ServiceFactory::mailer()->notifyEmployeeCustomerAction($quote, 'declined', $text);
                    $this->success[] = $this->trans('Thank you for your answer. The quote is marked as declined.', [], 'Modules.Sntquotes.Shop');
                    break;

                case 'message':
                    ServiceFactory::messages()->add($quote, 'customer', $customerId, $text);
                    ServiceFactory::mailer()->notifyEmployeeCustomerAction($quote, 'message', $text);
                    $this->success[] = $this->trans('Your message has been sent.', [], 'Modules.Sntquotes.Shop');
                    break;
            }
            $this->quote = new SntQuote((int) $quote->id);
        } catch (QuoteException $e) {
            $this->errors[] = $this->trans($e->getMessage(), $e->getParameters(), 'Modules.Sntquotes.Shop');
        } catch (Throwable $e) {
            PrestaShopLogger::addLog('[snt_quotes] customer quote action ' . $action . ': ' . $e->getMessage(), 3, null, 'SntQuote', (int) $quote->id);
            $this->errors[] = $this->trans('Your request could not be processed. Please try again or contact us.', [], 'Modules.Sntquotes.Shop');
        }
    }

    public function initContent(): void
    {
        parent::initContent();
        $quote = $this->quote;
        if (!$quote) {
            return;
        }
        if ($quote->getStatus() === QuoteStatus::Sent) {
            ServiceFactory::stateMachine()->apply($quote, QuoteStatus::Viewed, null, ['via' => 'customer_account'], SntQuoteHistory::ACTOR_CUSTOMER, (int) $quote->id_customer);
        }
        ServiceFactory::messages()->markRead($quote, 'customer');

        $view = ServiceFactory::presenter()->present($quote, true);
        $view['status'] = (new StatusPresenter())->present($quote->getStatus(), StatusPresenter::DOMAIN_SHOP);
        $status = $quote->getStatus();
        $orderUrl = '';
        if ($quote->id_order) {
            $orderUrl = $this->context->link->getPageLink('order-detail', true, null, ['id_order' => (int) $quote->id_order]);
        }

        $this->context->smarty->assign([
            'snt_quote' => $view,
            'snt_te_first' => ServiceFactory::priceDisplay()->taxExcludedFirst((int) $quote->id_shop, (int) $quote->id_customer),
            'snt_can_pay' => $this->canPay(),
            'snt_can_revise' => $status->canTransitionTo(QuoteStatus::RevisionRequested),
            'snt_can_decline' => $status->canTransitionTo(QuoteStatus::Declined),
            'snt_is_accepted' => $status === QuoteStatus::Accepted,
            'snt_is_expired' => $status === QuoteStatus::Expired || ($status->isOpen() && $quote->isExpiredByDate()),
            'snt_order_url' => $orderUrl,
            'snt_messages' => ServiceFactory::messages()->thread($quote),
            'snt_action_url' => $this->context->link->getModuleLink('snt_quotes', 'quote', ['id_quote' => (int) $quote->id], true),
            'snt_pdf_url' => $this->context->link->getModuleLink('snt_quotes', 'quote', ['id_quote' => (int) $quote->id, 'pdf' => 1], true),
            'snt_list_url' => $this->context->link->getModuleLink('snt_quotes', 'quotes', [], true),
            'snt_token' => Tools::getToken(false),
            'snt_success' => $this->success,
            'snt_errors' => $this->errors,
        ]);
        $this->setTemplate('module:snt_quotes/views/templates/front/quote.tpl');
    }

    public function getBreadcrumbLinks(): array
    {
        $breadcrumb = parent::getBreadcrumbLinks();
        $breadcrumb['links'][] = $this->addMyAccountToBreadcrumb();
        $breadcrumb['links'][] = [
            'title' => $this->trans('My quotes', [], 'Modules.Sntquotes.Shop'),
            'url' => $this->context->link->getModuleLink('snt_quotes', 'quotes', [], true),
        ];
        if ($this->quote) {
            $breadcrumb['links'][] = ['title' => $this->quote->getFullReference(), 'url' => ''];
        }

        return $breadcrumb;
    }

    /**
     * @return array<string, mixed>
     */
    public function getTemplateVarPage(): array
    {
        $page = parent::getTemplateVarPage();
        $page['meta']['robots'] = 'noindex';
        if ($this->quote) {
            $page['meta']['title'] = $this->trans('Quote %ref%', ['%ref%' => $this->quote->getFullReference()], 'Modules.Sntquotes.Shop');
        }

        return $page;
    }

    /**
     * Accepter et payer : devis envoyé, vu ou accepté, non commandé, encore valable.
     */
    private function canPay(): bool
    {
        $quote = $this->quote;
        if (!$quote || $quote->id_order) {
            return false;
        }
        $status = $quote->getStatus();

        return $status === QuoteStatus::Accepted || ($status->isOpen() && !$quote->isExpiredByDate());
    }

    /**
     * PDF envoyé au client (dernière archive de cette version), sinon généré à la volée.
     */
    private function downloadPdf(): void
    {
        $quote = $this->quote;
        $filename = HTMLTemplateSntQuote::filenameFor($quote);
        try {
            $idArchive = (int) Db::getInstance()->getValue(
                'SELECT `id_snt_quote_pdf` FROM `' . _DB_PREFIX_ . 'snt_quote_pdf`
                 WHERE `id_snt_quote` = ' . (int) $quote->id . ' AND `version` = ' . (int) $quote->version . '
                 ORDER BY `id_snt_quote_pdf` DESC'
            );
            $pdf = ServiceFactory::pdf();
            $content = $idArchive
                ? $pdf->archivedContent(new SntQuotePdf($idArchive), (int) $quote->id_shop)
                : $pdf->render($quote, ServiceFactory::presenter());
        } catch (Throwable $e) {
            PrestaShopLogger::addLog('[snt_quotes] customer PDF ' . $quote->getFullReference() . ': ' . $e->getMessage(), 3, null, 'SntQuote', (int) $quote->id);
            $this->errors[] = $this->trans('The PDF is temporarily unavailable. Please try again later.', [], 'Modules.Sntquotes.Shop');

            return;
        }
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: private, no-store');
        header('Content-Length: ' . strlen($content));
        echo $content;
        exit;
    }
}
