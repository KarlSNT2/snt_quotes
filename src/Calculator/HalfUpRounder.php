<?php

declare(strict_types=1);

namespace Snt\Quotes\Calculator;

/**
 * Arrondi « au plus proche, demi vers le haut » (PS_ROUND_HALF_UP, défaut PrestaShop).
 * Implémentation pure, utilisée par les tests et hors contexte PrestaShop.
 */
final class HalfUpRounder implements Rounder
{
    public function round(float $value, int $precision): float
    {
        return round($value, $precision, PHP_ROUND_HALF_UP);
    }
}
