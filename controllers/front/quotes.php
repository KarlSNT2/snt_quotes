<?php

declare(strict_types=1);

use Snt\Quotes\Domain\QuoteStatus;
use Snt\Quotes\Presenter\StatusPresenter;
use Snt\Quotes\ServiceFactory;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Espace client « Mes devis » (FO-02) : liste des devis du client connecté.
 * Les devis qui attendent une réponse du client sont en tête, avec « Consulter et valider ».
 */
class Snt_QuotesQuotesModuleFrontController extends ModuleFrontController
{
    /** @var bool */
    public $auth = true;

    /** @var bool */
    public $ssl = true;

    /** @var string */
    public $authRedirection = 'my-account';

    public function setMedia()
    {
        $media = parent::setMedia();
        $this->registerStylesheet('snt-quotes-front', 'modules/' . $this->module->name . '/views/css/front.css', ['media' => 'all', 'priority' => 200]);

        return $media;
    }

    /** @return void */
    public function initContent()
    {
        parent::initContent();
        $idCustomer = (int) $this->context->customer->id;
        $idShop = (int) $this->context->shop->id;
        $teFirst = ServiceFactory::priceDisplay()->taxExcludedFirst($idShop, $idCustomer);
        $statusPresenter = new StatusPresenter();
        $locale = $this->context->getCurrentLocale();

        $quotes = [];
        foreach (ServiceFactory::customerQuotes()->listForCustomer($idCustomer, $idShop) as $row) {
            $status = QuoteStatus::tryFrom((string) $row['status']) ?? QuoteStatus::Sent;
            if ($status === QuoteStatus::Draft) {
                // Demande envoyée depuis le panier, pas encore traitée par le commercial : ni montant ni lien.
                $quotes[] = [
                    'id' => (int) $row['id_snt_quote'],
                    'reference' => (string) $row['reference'],
                    'project_name' => (string) $row['project_name'],
                    'date' => Tools::displayDate((string) $row['date_add'], false),
                    'valid_until' => '',
                    'expires_soon' => false,
                    'amount' => '—',
                    'status' => ['label' => $this->trans('Request sent', [], 'Modules.Sntquotes.Shop'), 'color' => '#6c757d', 'strike' => false],
                    'awaiting' => false,
                    'url' => '',
                ];
                continue;
            }
            $iso = (string) Currency::getIsoCodeById((int) $row['id_currency']);
            $quotes[] = [
                'id' => (int) $row['id_snt_quote'],
                'reference' => $row['reference'] . ((int) $row['version'] > 1 ? ' v' . (int) $row['version'] : ''),
                'project_name' => (string) $row['project_name'],
                'date' => Tools::displayDate((string) ($row['sent_at'] ?: $row['date_add']), false),
                'valid_until' => $row['valid_until'] ? Tools::displayDate((string) $row['valid_until'], false) : '',
                'expires_soon' => $status->isOpen() && $row['valid_until'] && strtotime((string) $row['valid_until']) < strtotime('+7 days'),
                'amount' => $locale ? $locale->formatPrice((float) ($teFirst ? $row['total_te'] : $row['total_ti']), $iso) : (string) $row['total_ti'],
                'status' => $statusPresenter->present($status, StatusPresenter::DOMAIN_SHOP),
                'awaiting' => $status->isOpen(),
                'url' => $this->context->link->getModuleLink('snt_quotes', 'quote', ['id_quote' => (int) $row['id_snt_quote']], true),
            ];
        }

        $this->context->smarty->assign([
            'snt_quotes' => $quotes,
            'snt_awaiting' => array_values(array_filter($quotes, static fn (array $q): bool => $q['awaiting'])),
            'snt_te_first' => $teFirst,
        ]);
        $this->setTemplate('module:snt_quotes/views/templates/front/quotes.tpl');
    }

    /**
     * @return array<string, mixed>
     */
    public function getBreadcrumbLinks()
    {
        $breadcrumb = parent::getBreadcrumbLinks();
        $breadcrumb['links'][] = $this->addMyAccountToBreadcrumb();
        $breadcrumb['links'][] = [
            'title' => $this->trans('My quotes', [], 'Modules.Sntquotes.Shop'),
            'url' => $this->context->link->getModuleLink('snt_quotes', 'quotes', [], true),
        ];

        return $breadcrumb;
    }

    /**
     * @return array<string, mixed>
     */
    public function getTemplateVarPage()
    {
        $page = parent::getTemplateVarPage();
        $page['meta']['robots'] = 'noindex';
        $page['meta']['title'] = $this->trans('My quotes', [], 'Modules.Sntquotes.Shop');

        return $page;
    }
}
