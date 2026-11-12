<?php

declare(strict_types=1);

use Snt\Quotes\Config\ConfigKeys;
use Snt\Quotes\Conversion\QuoteCartContext;
use Snt\Quotes\Domain\Exception\QuoteException;
use Snt\Quotes\Service\QuoteMessages;
use Snt\Quotes\ServiceFactory;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Demande de devis depuis le panier (FO-01).
 *
 * Crée un devis BROUILLON (source « fo_cart ») avec les articles du panier au prix du client.
 * Le panier n'est pas modifié. Le commercial reçoit un e-mail ; la demande apparaît dans « À traiter ».
 * Le message et la pièce jointe du client ouvrent le fil d'échanges du devis.
 */
class Snt_QuotesRequestModuleFrontController extends ModuleFrontController
{
    /** Nombre maximal de demandes par client sur 24 h (anti-abus). */
    private const DAILY_LIMIT = 10;

    /** @var bool */
    public $auth = true;

    /** @var bool */
    public $ssl = true;

    /** @var array<string, string> */
    private array $form = ['project_name' => '', 'message' => '', 'id_address_delivery' => '', 'id_address_invoice' => ''];

    private ?SntQuote $existing = null;

    public function __construct()
    {
        parent::__construct();
        // Après connexion, retour sur cette page (le panier du visiteur est rattaché au client par PrestaShop).
        $this->authRedirection = urlencode($this->context->link->getModuleLink('snt_quotes', 'request', [], true));
    }

    /** @return void */
    public function init()
    {
        parent::init();
        if (!$this->context->customer->isLogged()) {
            return;
        }
        if (!(int) ServiceFactory::config()->get(ConfigKeys::FO_REQUEST_ENABLED, (int) $this->context->shop->id)) {
            Tools::redirect($this->context->link->getPageLink('cart', true, null, ['action' => 'show']));
        }
        if (Tools::getValue('done')) {
            return; // page de confirmation
        }
        $cart = $this->context->cart;
        if (!$cart instanceof Cart || !$cart->id || !$cart->nbProducts() || QuoteCartContext::isQuoteCart((int) $cart->id)) {
            Tools::redirect($this->context->link->getPageLink('cart', true, null, ['action' => 'show']));
        }
        $this->existing = $this->existingRequest($cart);
    }

    public function setMedia()
    {
        $media = parent::setMedia();
        $this->registerStylesheet('snt-quotes-front', 'modules/' . $this->module->name . '/views/css/front.css', ['media' => 'all', 'priority' => 200]);

        return $media;
    }

    /** @return void */
    public function postProcess()
    {
        if (!Tools::isSubmit('snt_request') || Tools::getValue('done') || !$this->context->customer->isLogged()) {
            return;
        }
        $this->form = [
            'project_name' => trim((string) Tools::getValue('project_name')),
            'message' => trim((string) Tools::getValue('message')),
            'id_address_delivery' => (string) (int) Tools::getValue('id_address_delivery'),
            'id_address_invoice' => (string) (int) Tools::getValue('id_address_invoice'),
        ];
        if (Tools::getValue('token') !== Tools::getToken(false)) {
            $this->errors[] = $this->trans('Your session has expired. Reload the page and try again.', [], 'Modules.Sntquotes.Shop');

            return;
        }
        if ($this->existing) {
            return; // déjà demandé pour ce panier : la page l'indique
        }
        if ($this->requestsToday() >= self::DAILY_LIMIT) {
            $this->errors[] = $this->trans('You have sent many requests today. Please contact us directly.', [], 'Modules.Sntquotes.Shop');

            return;
        }

        $customer = $this->context->customer;
        $cart = $this->context->cart;
        $attachment = null;
        try {
            $messages = ServiceFactory::messages();
            $attachment = $messages->storeUpload($_FILES['attachment'] ?? null);
            $factory = ServiceFactory::factory();
            $lines = $factory->linesFromCart((int) $cart->id, (int) $customer->id);
            $created = $factory->create([
                'id_customer' => (int) $customer->id,
                'id_cart_source' => (int) $cart->id,
                'id_address_delivery' => (int) $this->form['id_address_delivery'],
                'id_address_invoice' => (int) $this->form['id_address_invoice'],
                'project_name' => $this->form['project_name'],
                'lines' => $lines,
            ], 'fo_cart');
            $quote = $created->quote;

            $text = $this->form['message'] !== ''
                ? $this->form['message']
                : $this->trans('Quote request sent from my cart.', [], 'Modules.Sntquotes.Shop');
            $message = $messages->add($quote, 'customer', (int) $customer->id, $text, $attachment);
            $attachment = null; // désormais rattachée au message

            try {
                ServiceFactory::mailer()->notifyEmployeeCustomerAction($quote, 'request', (string) $message->message);
            } catch (Throwable $e) {
                PrestaShopLogger::addLog('[snt_quotes] request e-mail ' . $quote->getFullReference() . ': ' . $e->getMessage(), 2, null, 'SntQuote', (int) $quote->id);
            }

            Tools::redirect($this->context->link->getModuleLink('snt_quotes', 'request', ['done' => (int) $quote->id], true));
        } catch (QuoteException $e) {
            $this->errors[] = $this->trans($e->getMessage(), $e->getParameters(), 'Modules.Sntquotes.Shop');
        } catch (Throwable $e) {
            PrestaShopLogger::addLog('[snt_quotes] request from cart ' . (int) $cart->id . ': ' . get_class($e) . ': ' . $e->getMessage(), 3, null, 'Cart', (int) $cart->id);
            $this->errors[] = $this->trans('Your request could not be sent. Please try again or contact us.', [], 'Modules.Sntquotes.Shop');
        }
        if ($attachment) {
            @unlink(QuoteMessages::attachmentDir() . $attachment[0]);
        }
    }

    /** @return void */
    public function initContent()
    {
        parent::initContent();
        $idShop = (int) $this->context->shop->id;
        $delay = (int) ServiceFactory::config()->get(ConfigKeys::RESPONSE_DELAY_DAYS, $idShop);
        $vars = [
            'snt_response_days' => max(1, $delay),
            'snt_quotes_url' => $this->context->link->getModuleLink('snt_quotes', 'quotes', [], true),
            'snt_cart_url' => $this->context->link->getPageLink('cart', true, null, ['action' => 'show']),
            'snt_errors' => $this->errors,
            'snt_done' => null,
            'snt_existing' => $this->existing ? $this->existing->getFullReference() : '',
        ];

        $idDone = (int) Tools::getValue('done');
        if ($idDone) {
            $quote = new SntQuote($idDone);
            if (!Validate::isLoadedObject($quote) || (int) $quote->id_customer !== (int) $this->context->customer->id || (int) $quote->id_shop !== $idShop) {
                Tools::redirect($vars['snt_quotes_url']);
            }
            $vars['snt_done'] = ['reference' => $quote->getFullReference()];
        } else {
            $cart = $this->context->cart;
            $products = [];
            foreach ($cart->getProducts() as $product) {
                $products[] = [
                    'name' => (string) $product['name'],
                    'attributes' => (string) ($product['attributes'] ?? ''),
                    'reference' => (string) ($product['reference'] ?? ''),
                    'qty' => (int) $product['cart_quantity'],
                ];
            }
            $addresses = [];
            foreach ($this->context->customer->getAddresses((int) $this->context->language->id) as $row) {
                $address = new Address((int) $row['id_address']);
                $addresses[] = [
                    'id' => (int) $row['id_address'],
                    'label' => $row['alias'] . ' — ' . AddressFormat::generateAddress($address, [], ', '),
                ];
            }
            $vars += [
                'snt_products' => $products,
                'snt_addresses' => $addresses,
                'snt_form' => $this->form + [
                    'delivery' => (int) ($this->form['id_address_delivery'] ?: $cart->id_address_delivery),
                    'invoice' => (int) ($this->form['id_address_invoice'] ?: $cart->id_address_invoice),
                ],
                'snt_form_action' => $this->context->link->getModuleLink('snt_quotes', 'request', [], true),
                'snt_token' => Tools::getToken(false),
                'snt_max_mb' => (int) (QuoteMessages::MAX_ATTACHMENT_SIZE / 1048576),
                'snt_accept' => '.' . implode(',.', QuoteMessages::ATTACHMENT_EXTENSIONS),
                'snt_address_url' => $this->context->link->getPageLink('address', true, null, ['back' => $this->context->link->getModuleLink('snt_quotes', 'request', [], true)]),
            ];
        }

        $this->context->smarty->assign($vars);
        $this->setTemplate('module:snt_quotes/views/templates/front/request.tpl');
    }

    /**
     * @return array<string, mixed>
     */
    public function getBreadcrumbLinks()
    {
        $breadcrumb = parent::getBreadcrumbLinks();
        $breadcrumb['links'][] = [
            'title' => $this->trans('Request a quote', [], 'Modules.Sntquotes.Shop'),
            'url' => $this->context->link->getModuleLink('snt_quotes', 'request', [], true),
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
        $page['meta']['title'] = $this->trans('Request a quote', [], 'Modules.Sntquotes.Shop');

        return $page;
    }

    /**
     * Demande déjà envoyée pour ce panier, sans modification du panier depuis.
     */
    private function existingRequest(Cart $cart): ?SntQuote
    {
        $id = (int) Db::getInstance()->getValue(
            'SELECT `id_snt_quote` FROM `' . _DB_PREFIX_ . 'snt_quote`
             WHERE `id_cart_source` = ' . (int) $cart->id . ' AND `source` = \'fo_cart\' AND `deleted` = 0
               AND `id_customer` = ' . (int) $this->context->customer->id . '
               AND `date_add` >= \'' . pSQL((string) $cart->date_upd) . '\'
             ORDER BY `id_snt_quote` DESC'
        );

        return $id ? new SntQuote($id) : null;
    }

    private function requestsToday(): int
    {
        return (int) Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'snt_quote`
             WHERE `id_customer` = ' . (int) $this->context->customer->id . ' AND `source` = \'fo_cart\'
               AND `date_add` >= \'' . pSQL(date('Y-m-d H:i:s', time() - 86400)) . '\''
        );
    }
}
