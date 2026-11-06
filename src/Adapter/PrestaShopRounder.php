<?php

declare(strict_types=1);

namespace Snt\Quotes\Adapter;

use Snt\Quotes\Calculator\Rounder;
use Snt\Quotes\Calculator\RoundingPolicy;

/**
 * Arrondi natif PrestaShop (respecte PS_PRICE_ROUND_MODE).
 */
final class PrestaShopRounder implements Rounder
{
    public function round(float $value, int $precision): float
    {
        return (float) \Tools::ps_round($value, $precision);
    }

    /**
     * Politique d'arrondi de la boutique courante (PS_ROUND_TYPE + précision de la devise du contexte).
     */
    public static function policy(?\Context $context = null): RoundingPolicy
    {
        $context ??= \Context::getContext();

        return new RoundingPolicy(
            (int) \Configuration::get('PS_ROUND_TYPE') ?: RoundingPolicy::ROUND_ITEM,
            (int) $context->getComputingPrecision(),
            new self()
        );
    }
}
