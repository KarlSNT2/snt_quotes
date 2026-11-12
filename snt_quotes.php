<?php

/**
 * SNT Quotes — devis figés, convertis en commande sans passer par le tunnel.
 *
 * Remplace Opart Devis sur les boutiques SNT2. Compatible PrestaShop 8.2 et 9.x, PHP 8.1+.
 * Voir docs/PLAN.md pour l'architecture.
 */
declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

// Chargeur de classes du module. Aucune dépendance Composer à l'exécution : vendor/ ne contient que les outils
// de développement (PHPUnit, PHPStan, php-cs-fixer) et n'est JAMAIS chargé par la boutique, même s'il est présent
// (ses bibliothèques Symfony entreraient en conflit avec celles de PrestaShop). Zip de livraison : tools/build-zip.ps1.
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Snt\\Quotes\\')) {
        $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen('Snt\\Quotes\\'))) . '.php';
    } elseif (str_starts_with($class, 'SntQuote') || $class === 'HTMLTemplateSntQuote') {
        $file = $class === 'HTMLTemplateSntQuote'
            ? __DIR__ . '/classes/pdf/HTMLTemplateSntQuote.php'
            : __DIR__ . '/classes/' . $class . '.php';
    } else {
        return;
    }
    if (is_file($file)) {
        require_once $file;
    }
});

use Snt\Quotes\Config\ConfigKeys;
use Snt\Quotes\Conversion\QuoteCartContext;
use Snt\Quotes\Domain\QuoteStatus;
use Snt\Quotes\Install\Installer;
use Snt\Quotes\Presenter\StatusPresenter;
use Snt\Quotes\Repository\QuoteRepository;
use Snt\Quotes\ServiceFactory;

class Snt_Quotes extends PaymentModule
{
    /** Hooks enregistrés. Toute nouvelle entrée est aussi ajoutée par un script upgrade/. */
    public const HOOKS = [
        // Jalon 1
        'displayAdminCustomers',
        'displayAdminOrderMain',
        'actionAdminControllerSetMedia',
        // Jalon 2 — conversion sans tunnel
        'paymentOptions',
        'actionFilterDeliveryOptionList',
        'actionValidateOrder',
        'actionOrderStatusPostUpdate',
        'actionEmailSendBefore',
        'actionDispatcherBefore',
        // 0.2.1 — le port du devis prime sur les règles de orderfees_shipping (Advanced Shipping Cost)
        'actionCartGetPackageShippingCost',
        // 0.3.0 — espace client « Mes devis »
        'displayCustomerAccount',
        'moduleRoutes',
        // 0.3.1 — bouton « Demander un devis » sous « Commander » dans le panier
        'displayExpressCheckout',
    ];

    /** @var int|null renseigné par le cœur avant getOrderShippingCost*() (transporteur technique) */
    public $id_carrier;

    public function __construct()
    {
        $this->name = 'snt_quotes';
        $this->tab = 'billing_invoicing';
        $this->version = '0.3.1';
        $this->author = 'SNT2';
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->currencies = true;
        $this->currencies_mode = 'checkbox';
        $this->ps_versions_compliancy = ['min' => '8.2.0', 'max' => '9.99.99'];

        parent::__construct();

        $this->displayName = $this->trans('SNT Quotes', [], 'Modules.Sntquotes.Admin');
        $this->description = $this->trans('Fixed quotes converted into orders without the checkout funnel.', [], 'Modules.Sntquotes.Admin');
        $this->confirmUninstall = $this->trans('All quotes, their history and their links will be deleted. Continue?', [], 'Modules.Sntquotes.Admin');
    }

    public function isUsingNewTranslationSystem(): bool
    {
        return true;
    }

    public function install(): bool
    {
        return parent::install()
            && (new Installer($this))->install()
            && $this->registerHook(self::HOOKS);
    }

    public function uninstall(): bool
    {
        return (new Installer($this))->uninstall() && parent::uninstall();
    }

    /**
     * Le bouton « Configurer » du gestionnaire de modules ouvre la page Symfony.
     */
    public function getContent(): void
    {
        Tools::redirectAdmin(
            $this->context->link->getAdminLink(Installer::TAB_CLASS, true, ['route' => 'admin_snt_quotes_configuration'])
        );
    }

    /* ------------------------------------------------------------------ */
    /* Hooks BO                                                            */
    /* ------------------------------------------------------------------ */

    public function hookActionAdminControllerSetMedia(): void
    {
        $controller = Tools::getValue('controller');
        if (in_array($controller, ['AdminCustomers', 'AdminOrders'], true)) {
            $this->context->controller->addCSS($this->_path . 'views/css/admin.css');
        }
    }

    /**
     * Fiche client : 5 derniers devis + « Nouveau devis pour ce client ».
     *
     * @param array<string, mixed> $params
     */
    public function hookDisplayAdminCustomers(array $params): string
    {
        $idCustomer = (int) ($params['id_customer'] ?? 0);
        if (!$idCustomer) {
            return '';
        }
        $quotes = (new QuoteRepository())->findLatestForCustomer($idCustomer, 5);
        $link = $this->context->link;
        $statusPresenter = new StatusPresenter();
        $locale = $this->context->getCurrentLocale();
        foreach ($quotes as &$quote) {
            $quote['view_url'] = $link->getAdminLink(Installer::TAB_CLASS, true, ['route' => 'admin_snt_quotes_view', 'quoteId' => (int) $quote['id_snt_quote']]);
            $quote['status_html'] = $statusPresenter->badgeHtml(QuoteStatus::tryFrom((string) $quote['status']) ?? QuoteStatus::Draft);
            $iso = (string) (new Currency((int) $quote['id_currency']))->iso_code;
            $quote['total_display'] = $locale ? $locale->formatPrice((float) $quote['total_ti'], $iso) : (string) $quote['total_ti'];
            $quote['date_display'] = Tools::displayDate((string) $quote['date_add'], false);
        }
        unset($quote);

        $this->context->smarty->assign([
            'snt_quotes' => $quotes,
            'snt_quotes_count' => count($quotes),
            'snt_quotes_new_url' => $link->getAdminLink(Installer::TAB_CLASS, true, ['route' => 'admin_snt_quotes_create', 'id_customer' => $idCustomer]),
            'snt_quotes_list_url' => $link->getAdminLink(Installer::TAB_CLASS, true, ['route' => 'admin_snt_quotes_index']),
            'snt_quotes_import_cart_url' => $link->getAdminLink(Installer::TAB_CLASS, true, ['route' => 'admin_snt_quotes_create', 'id_customer' => $idCustomer, 'import' => 'cart']),
        ]);

        return $this->fetch('module:snt_quotes/views/templates/hook/admin_customer_quotes.tpl');
    }

    /**
     * Fiche commande : encart « Issue du devis DEV-… ».
     *
     * @param array<string, mixed> $params
     */
    public function hookDisplayAdminOrderMain(array $params): string
    {
        $idOrder = (int) ($params['id_order'] ?? 0);
        $quote = $idOrder ? (new QuoteRepository())->findByOrderId($idOrder) : null;
        if (!$quote) {
            return '';
        }
        $this->context->smarty->assign([
            'snt_quote' => $quote,
            'snt_quote_url' => $this->context->link->getAdminLink(Installer::TAB_CLASS, true, ['route' => 'admin_snt_quotes_view', 'quoteId' => (int) $quote['id_snt_quote']]),
        ]);

        return $this->fetch('module:snt_quotes/views/templates/hook/admin_order_quote.tpl');
    }

    /* ------------------------------------------------------------------ */
    /* Paiement intégré et transporteur technique (jalon 2)                */
    /* ------------------------------------------------------------------ */

    /**
     * Les moyens « devis » ne sont jamais proposés dans le tunnel : seules les conversions les utilisent.
     *
     * @param array<string, mixed> $params
     *
     * @return array<int, mixed>
     */
    public function hookPaymentOptions(array $params): array
    {
        return [];
    }

    /**
     * Port HT du devis pour son panier technique (transporteur « Livraison selon devis »).
     *
     * @param Cart $cart
     *
     * @return float|false
     */
    public function getOrderShippingCostExternal($cart)
    {
        $idQuote = QuoteCartContext::quoteIdForCart((int) $cart->id);
        if (!$idQuote) {
            return false; // transporteur indisponible pour un panier normal
        }

        return (float) (new SntQuote($idQuote))->total_shipping_te;
    }

    /**
     * Hook de la surcharge Cart de orderfees_shipping (Advanced Shipping Cost). Ses règles peuvent désactiver un
     * transporteur ou y ajouter des frais ; pour un panier de devis, le port est celui du devis, sans exception :
     * on impose le résultat. Ce hook doit s'exécuter APRÈS celui de orderfees_shipping
     * (voir TechnicalSetup::ensureShippingHookLast()). Les valeurs du tableau sont des références.
     *
     * @param array<string, mixed> $params
     */
    public function hookActionCartGetPackageShippingCost(array $params): void
    {
        $cart = $params['object'] ?? null;
        if (!$cart instanceof Cart || !$cart->id) {
            return;
        }
        $idQuote = QuoteCartContext::quoteIdForCart((int) $cart->id);
        if (!$idQuote) {
            return;
        }
        $idCarrier = (int) ($params['id_carrier'] ?? 0) ?: (int) $cart->id_carrier;
        if (!$idCarrier || $idCarrier !== ServiceFactory::technicalSetup()->technicalCarrierId()) {
            return;
        }
        $quote = new SntQuote($idQuote);
        $cost = (float) $quote->total_shipping_te;
        if (!empty($params['use_tax']) && !Tax::excludeTaxeOption()) {
            $cost *= 1 + (float) $quote->shipping_tax_rate / 100;
        }
        $params['total'] = (float) Tools::ps_round($cost, (int) $this->context->getComputingPrecision());
        $params['return'] = true;
    }

    /**
     * @param Cart  $cart
     * @param float $shippingCost
     *
     * @return float|false
     */
    public function getOrderShippingCost($cart, $shippingCost)
    {
        return $this->getOrderShippingCostExternal($cart);
    }

    /**
     * Le transporteur technique n'apparaît que pour les paniers de devis, et seul.
     *
     * @param array<string, mixed> $params
     */
    public function hookActionFilterDeliveryOptionList(array $params): void
    {
        if (!isset($params['delivery_option_list']) || !is_array($params['delivery_option_list'])) {
            return;
        }
        $technical = ServiceFactory::technicalSetup()->technicalCarrierId();
        if (!$technical) {
            return;
        }
        $cart = $params['cart'] ?? $this->context->cart;
        $isQuoteCart = $cart instanceof Cart && QuoteCartContext::isQuoteCart((int) $cart->id);
        foreach ($params['delivery_option_list'] as $idAddress => $options) {
            foreach ($options as $key => $option) {
                $carriers = array_map('intval', array_keys($option['carrier_list'] ?? []));
                $hasTechnical = in_array($technical, $carriers, true);
                if ($isQuoteCart !== $hasTechnical) {
                    unset($params['delivery_option_list'][$idAddress][$key]);
                }
            }
        }
    }

    /**
     * Notification HiPay pour un panier de devis : neutralise les seuils de port gratuit pour la requête.
     *
     * @param array<string, mixed> $params
     */
    public function hookActionDispatcherBefore(array $params): void
    {
        if (Tools::getValue('module') !== 'hipaypayments' || Tools::getValue('controller') !== 'notify') {
            return;
        }
        $order = Tools::getValue('order');
        $reference = is_array($order) ? (string) ($order['id'] ?? '') : '';
        $idCart = (int) strtok($reference, '-');
        if ($idCart && QuoteCartContext::isQuoteCart($idCart)) {
            $cart = new Cart($idCart);
            QuoteCartContext::activateOverrides((int) $cart->id_shop, (int) $cart->id_shop_group);
        }
    }

    /**
     * Post-traitement de toute commande issue d'un panier de devis (avant l'écriture du premier statut).
     *
     * @param array<string, mixed> $params
     */
    public function hookActionValidateOrder(array $params): void
    {
        $order = $params['order'] ?? null;
        $cart = $params['cart'] ?? null;
        if (!$order instanceof Order || !$cart instanceof Cart || !QuoteCartContext::isQuoteCart((int) $cart->id)) {
            return;
        }
        try {
            ServiceFactory::postProcessor()->process($order, $cart);
            $quote = new SntQuote(QuoteCartContext::quoteIdForCart((int) $cart->id));
            if ((int) $quote->id_order === (int) $order->id) {
                ServiceFactory::mailer()->notifyEmployeeOrdered($quote, $order);
            }
        } catch (Throwable $e) {
            PrestaShopLogger::addLog('[snt_quotes] post-processing order ' . (int) $order->id . ': ' . $e->getMessage(), 3, null, 'Order', (int) $order->id);
        }
    }

    /**
     * @param array<string, mixed> $params
     */
    public function hookActionOrderStatusPostUpdate(array $params): void
    {
        $idOrder = (int) ($params['id_order'] ?? 0);
        $state = $params['newOrderStatus'] ?? null;
        if (!$idOrder || !$state instanceof OrderState) {
            return;
        }
        try {
            $processor = ServiceFactory::postProcessor();
            $processor->onOrderStatusChanged($idOrder, (int) $state->id);
            if ($state->paid) {
                $processor->onOrderPaid($idOrder);
            }
        } catch (Throwable $e) {
            PrestaShopLogger::addLog('[snt_quotes] order status ' . $idOrder . ': ' . $e->getMessage(), 3, null, 'Order', $idOrder);
        }
    }

    /**
     * Confirmation de commande : nom du transporteur réel au lieu de « Livraison selon devis ».
     *
     * @param array<string, mixed> $params
     */
    public function hookActionEmailSendBefore(array $params): bool
    {
        if (($params['template'] ?? '') !== 'order_conf' || !isset($params['templateVars']['{order_name}'])) {
            return true;
        }
        $reference = (string) $params['templateVars']['{order_name}'];
        $label = (string) Db::getInstance()->getValue(
            'SELECT q.`carrier_label` FROM `' . _DB_PREFIX_ . 'snt_quote` q
             INNER JOIN `' . _DB_PREFIX_ . 'orders` o ON o.`id_order` = q.`id_order`
             WHERE o.`reference` = \'' . pSQL($reference) . '\''
        );
        if ($label !== '') {
            $params['templateVars']['{carrier}'] = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
        }

        return true;
    }


    /* ------------------------------------------------------------------ */
    /* Espace client « Mes devis » (jalon 3)                                */
    /* ------------------------------------------------------------------ */

    /**
     * Lien « Mes devis » dans « Mon compte », avec le nombre de devis qui attendent une réponse.
     *
     * @param array<string, mixed> $params
     */
    public function hookDisplayCustomerAccount(array $params): string
    {
        if (!$this->context->customer->isLogged()) {
            return '';
        }
        $this->context->smarty->assign([
            'snt_quotes_url' => $this->context->link->getModuleLink($this->name, 'quotes', [], true),
            'snt_quotes_awaiting' => ServiceFactory::customerQuotes()->countAwaitingAction((int) $this->context->customer->id, (int) $this->context->shop->id),
        ]);

        return $this->fetch('module:snt_quotes/views/templates/hook/customer_account_link.tpl');
    }

    /**
     * Bouton « Demander un devis » sous « Commander » (panier). Le panier n'est pas modifié :
     * la demande crée un devis brouillon que le commercial complète puis envoie.
     */
    public function hookDisplayExpressCheckout(array $params): string
    {
        $cart = $this->context->cart;
        if (!$cart instanceof Cart || !$cart->id || !$cart->nbProducts()
            || !(int) ServiceFactory::config()->get(ConfigKeys::FO_REQUEST_ENABLED, (int) $this->context->shop->id)
            || QuoteCartContext::isQuoteCart((int) $cart->id)
        ) {
            return '';
        }
        $this->context->smarty->assign([
            'snt_request_url' => $this->context->link->getModuleLink($this->name, 'request', [], true),
        ]);

        return $this->fetch('module:snt_quotes/views/templates/hook/cart_request_button.tpl');
    }

    /**
     * URL lisibles : /mes-devis et /mes-devis/{id}.
     *
     * @return array<string, array<string, mixed>>
     */
    public function hookModuleRoutes(): array
    {
        return [
            'module-snt_quotes-quotes' => [
                'controller' => 'quotes',
                'rule' => 'mes-devis',
                'keywords' => [],
                'params' => ['fc' => 'module', 'module' => $this->name],
            ],
            'module-snt_quotes-request' => [
                'controller' => 'request',
                'rule' => 'demande-de-devis',
                'keywords' => [],
                'params' => ['fc' => 'module', 'module' => $this->name],
            ],
            'module-snt_quotes-quote' => [
                'controller' => 'quote',
                'rule' => 'mes-devis/{id_quote}',
                'keywords' => [
                    'id_quote' => ['regexp' => '[0-9]+', 'param' => 'id_quote'],
                ],
                'params' => ['fc' => 'module', 'module' => $this->name],
            ],
        ];
    }
}
