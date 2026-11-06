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

if (is_file(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
} else {
    // Repli si le module est déployé sans « composer install » : PSR-4 minimal + classmap.
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
}

use Snt\Quotes\Domain\QuoteStatus;
use Snt\Quotes\Install\Installer;
use Snt\Quotes\Presenter\StatusPresenter;
use Snt\Quotes\Repository\QuoteRepository;

class Snt_Quotes extends PaymentModule
{
    /** Hooks enregistrés (jalon 1). Les jalons suivants les complètent via upgrade/. */
    public const HOOKS = [
        'displayAdminCustomers',
        'displayAdminOrderMain',
        'actionAdminControllerSetMedia',
    ];

    public function __construct()
    {
        $this->name = 'snt_quotes';
        $this->tab = 'billing_invoicing';
        $this->version = '0.1.0';
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
}
