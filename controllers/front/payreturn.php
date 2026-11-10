<?php

declare(strict_types=1);

use Snt\Quotes\ServiceFactory;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Retour de la page de paiement HiPay. La commande est créée par la notification HiPay (serveur à serveur),
 * qui peut arriver quelques secondes après le retour du client : la page l'explique.
 */
class Snt_QuotesPayreturnModuleFrontController extends ModuleFrontController
{
    /** @var bool */
    public $ssl = true;

    /** @var bool */
    public $auth = false;

    public function setMedia(): void
    {
        parent::setMedia();
        $this->registerStylesheet('snt-quotes-front', 'modules/' . $this->module->name . '/views/css/front.css', ['media' => 'all', 'priority' => 200]);
    }

    public function initContent(): void
    {
        parent::initContent();
        $token = (string) Tools::getValue('t');
        $result = (string) Tools::getValue('r');
        $link = ServiceFactory::paymentLinks()->resolve($token);
        $quote = $link ? new SntQuote((int) $link->id_snt_quote) : null;
        if ($quote && (!Validate::isLoadedObject($quote) || (int) $quote->id_shop !== (int) $this->context->shop->id)) {
            $quote = null;
        }

        $state = match (true) {
            $quote === null => 'invalid',
            in_array($result, ['accept', 'pending'], true) => 'success',
            $result === 'cancel' => 'cancel',
            default => 'failure',
        };
        $orderReference = '';
        if ($quote && $quote->id_order) {
            $orderReference = (string) (new Order((int) $quote->id_order))->reference;
        }

        $this->context->smarty->assign([
            'snt_return_state' => $state,
            'snt_order_reference' => $orderReference,
            'snt_quote_reference' => $quote ? $quote->getFullReference() : '',
            'snt_pay_url' => $this->context->link->getModuleLink('snt_quotes', 'pay', ['t' => $token], true),
            'snt_contact_email' => (string) Configuration::get('PS_SHOP_EMAIL'),
        ]);
        $this->setTemplate('module:snt_quotes/views/templates/front/pay-return.tpl');
    }

    /**
     * @return array<string, mixed>
     */
    public function getTemplateVarPage(): array
    {
        $page = parent::getTemplateVarPage();
        $page['meta']['robots'] = 'noindex';

        return $page;
    }
}
