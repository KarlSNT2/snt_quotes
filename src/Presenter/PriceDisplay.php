<?php

declare(strict_types=1);

namespace Snt\Quotes\Presenter;

use Snt\Quotes\Config\ConfigKeys;
use Snt\Quotes\Config\ModuleConfig;

/**
 * Prix montrés au client en premier : TTC (défaut), HT, ou selon le groupe du client
 * (Clients > Groupes > « Affichage des prix », habituel pour les groupes B2B).
 */
final class PriceDisplay
{
    public const TAX_INCLUDED = 'ti';
    public const TAX_EXCLUDED = 'te';
    public const BY_GROUP = 'group';

    public function __construct(private readonly ModuleConfig $config)
    {
    }

    public function taxExcludedFirst(int $idShop, int $idCustomer): bool
    {
        return match ($this->config->get(ConfigKeys::PRICE_DISPLAY, $idShop)) {
            self::TAX_EXCLUDED => true,
            self::BY_GROUP => $idCustomer > 0
                && (int) \Group::getPriceDisplayMethod((int) \Customer::getDefaultGroupId($idCustomer)) === (int) PS_TAX_EXC,
            default => false,
        };
    }
}
