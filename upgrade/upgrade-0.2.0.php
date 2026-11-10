<?php

declare(strict_types=1);

use Snt\Quotes\Install\Installer;
use Snt\Quotes\Install\SchemaInstaller;
use Snt\Quotes\Install\TechnicalSetup;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Jalon 2 : conversion devis → commande.
 * - table snt_quote_cart (paniers techniques) ;
 * - transporteur « Livraison selon devis », statuts de commande ;
 * - nouveaux hooks (transporteur technique, post-traitement, e-mail de confirmation, notification HiPay).
 */
function upgrade_module_0_2_0(Snt_Quotes $module): bool
{
    return (new SchemaInstaller())->install()
        && (new Installer($module))->installConfiguration()
        && (new TechnicalSetup($module->name))->installAll()
        && $module->registerHook(Snt_Quotes::HOOKS);
}
