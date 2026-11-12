<?php

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * 0.3.4 : corrections de la recette V1 (aucun changement de schéma).
 * Les hooks du module sont ré-accrochés (un hook décroché à la main dans Apparence > Positions est rétabli).
 */
function upgrade_module_0_3_4(Snt_Quotes $module): bool
{
    return $module->registerHook(Snt_Quotes::HOOKS);
}
