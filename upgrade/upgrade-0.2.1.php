<?php

declare(strict_types=1);

use Snt\Quotes\Install\TechnicalSetup;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * 0.2.1 : conversion devis → commande fiabilisée.
 * - transporteur « Livraison selon devis » : tranche de poids à 0 € sur toutes les zones, tous groupes et boutiques
 *   (sans tranches, le cœur ne le propose jamais) ;
 * - hook actionCartGetPackageShippingCost, placé après orderfees_shipping : le port du devis prime sur ses règles.
 */
function upgrade_module_0_2_1(Snt_Quotes $module): bool
{
    $setup = new TechnicalSetup($module->name);
    if (!$module->registerHook(Snt_Quotes::HOOKS) || $setup->ensureCarrier() <= 0) {
        return false;
    }
    $setup->ensureShippingHookLast();

    return true;
}
