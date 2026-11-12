<?php

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * 0.3.1 : demande de devis depuis le panier.
 * - hook displayExpressCheckout (bouton « Demander un devis » sous « Commander ») ;
 * - URL /demande-de-devis (hook moduleRoutes, déjà greffé) ;
 * - pièces jointes des messages : dossier upload/snt_quotes/ protégé.
 */
function upgrade_module_0_3_1(Snt_Quotes $module): bool
{
    Snt\Quotes\Service\QuoteMessages::attachmentDir();

    return $module->registerHook(Snt_Quotes::HOOKS);
}
