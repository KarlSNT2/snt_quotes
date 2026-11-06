<?php

declare(strict_types=1);

namespace Snt\Quotes\Install;

use Snt\Quotes\Config\ConfigKeys;
use Snt\Quotes\Service\QuoteStateMachine;

/**
 * Installation / désinstallation : tables, onglet BO, configuration par défaut.
 * Les hooks sont enregistrés par la classe du module (liste Snt_Quotes::HOOKS).
 */
final class Installer
{
    public const TAB_CLASS = 'AdminSntQuotes';
    public const TAB_PARENT = 'AdminParentOrders';
    public const TAB_ROUTE = 'admin_snt_quotes_index';

    public function __construct(private readonly \Module $module)
    {
    }

    public function install(): bool
    {
        return (new SchemaInstaller())->install()
            && $this->installTab()
            && $this->installConfiguration()
            && $this->installCustomHooks();
    }

    /**
     * Hook publié par le module (changements de statut), pour que d'autres modules puissent s'y abonner.
     */
    public function installCustomHooks(): bool
    {
        $hooks = [
            QuoteStateMachine::HOOK_STATUS_UPDATE => 'SNT Quotes: quote status changed',
        ];
        foreach ($hooks as $name => $title) {
            if (\Hook::getIdByName($name)) {
                continue;
            }
            $hook = new \Hook();
            $hook->name = $name;
            $hook->title = $title;
            $hook->description = 'Params: quote (SntQuote), status_from, status_to, payload';
            $hook->position = false;
            if (!$hook->add()) {
                return false;
            }
        }

        return true;
    }

    public function uninstall(): bool
    {
        $this->uninstallTab();
        foreach (ConfigKeys::all() as $key) {
            \Configuration::deleteByName($key);
        }

        return (new SchemaInstaller())->uninstall();
    }

    public function installTab(): bool
    {
        $idTab = (int) \Tab::getIdFromClassName(self::TAB_CLASS);
        $tab = new \Tab($idTab ?: null);
        $tab->class_name = self::TAB_CLASS;
        $tab->route_name = self::TAB_ROUTE;
        $tab->module = $this->module->name;
        $tab->id_parent = (int) \Tab::getIdFromClassName(self::TAB_PARENT);
        $tab->active = true;
        $tab->icon = 'request_quote';
        $tab->wording = 'Quotes';
        $tab->wording_domain = 'Modules.Sntquotes.Admin';
        $tab->name = [];
        foreach (\Language::getLanguages(false) as $lang) {
            $tab->name[(int) $lang['id_lang']] = $lang['iso_code'] === 'fr' ? 'Devis' : 'Quotes';
        }

        return (bool) $tab->save();
    }

    public function uninstallTab(): void
    {
        $idTab = (int) \Tab::getIdFromClassName(self::TAB_CLASS);
        if ($idTab) {
            (new \Tab($idTab))->delete();
        }
    }

    /**
     * Pose les valeurs par défaut sans écraser une valeur existante, pour chaque boutique.
     */
    public function installConfiguration(): bool
    {
        foreach (\Shop::getShops(false) as $shop) {
            $idShop = (int) $shop['id_shop'];
            $idShopGroup = (int) $shop['id_shop_group'];
            foreach (ConfigKeys::defaults() as $key => $value) {
                if (\Configuration::hasKey($key, null, $idShopGroup, $idShop)) {
                    continue;
                }
                \Configuration::updateValue($key, $value, false, $idShopGroup, $idShop);
            }
            if (!\Configuration::get(ConfigKeys::CRON_TOKEN, null, $idShopGroup, $idShop)) {
                \Configuration::updateValue(ConfigKeys::CRON_TOKEN, bin2hex(random_bytes(24)), false, $idShopGroup, $idShop);
            }
            if (!\Configuration::get(ConfigKeys::SHOP_CODE, null, $idShopGroup, $idShop)) {
                \Configuration::updateValue(ConfigKeys::SHOP_CODE, self::guessShopCode((string) $shop['name']), false, $idShopGroup, $idShop);
            }
        }

        return true;
    }

    /**
     * Code site par défaut : 3 à 6 premières lettres du nom de boutique, en majuscules.
     */
    public static function guessShopCode(string $shopName): string
    {
        $letters = preg_replace('/[^A-Z]/', '', strtoupper(\Tools::replaceAccentedChars($shopName))) ?? '';

        return substr($letters !== '' ? $letters : 'SHOP', 0, 6);
    }
}
