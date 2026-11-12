<?php

declare(strict_types=1);

use Snt\Quotes\Config\ConfigKeys;
use Snt\Quotes\Install\Installer;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * 0.3.0 : espace client « Mes devis ».
 * - hooks displayCustomerAccount (lien « Mes devis ») et moduleRoutes (/mes-devis, /mes-devis/{id}) ;
 * - réglage « Affichage des prix » (TTC d'abord par défaut, comme en 0.2.1) ;
 * - webhooks n8n abandonnés : clés de configuration et table de journal supprimées (jamais utilisées).
 */
function upgrade_module_0_3_0(Snt_Quotes $module): bool
{
    foreach (ConfigKeys::OBSOLETE as $key) {
        Configuration::deleteByName($key);
    }
    Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'snt_quote_webhook_log`');

    return (new Installer($module))->installConfiguration()
        && $module->registerHook(Snt_Quotes::HOOKS);
}
