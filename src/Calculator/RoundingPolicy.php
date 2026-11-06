<?php

declare(strict_types=1);

namespace Snt\Quotes\Calculator;

/**
 * Politique d'arrondi alignée sur le cœur PrestaShop.
 *
 * roundType = PS_ROUND_TYPE : 1 = par article (Order::ROUND_ITEM), 2 = par ligne (ROUND_LINE), 3 = sur le total (ROUND_TOTAL).
 * precision = précision de calcul de la devise (Context::getComputingPrecision()).
 */
final class RoundingPolicy
{
    public const ROUND_ITEM = 1;
    public const ROUND_LINE = 2;
    public const ROUND_TOTAL = 3;

    public function __construct(
        public readonly int $roundType,
        public readonly int $precision,
        public readonly Rounder $rounder,
    ) {
        if (!in_array($roundType, [self::ROUND_ITEM, self::ROUND_LINE, self::ROUND_TOTAL], true)) {
            throw new \InvalidArgumentException('Unknown round type ' . $roundType);
        }
    }

    public function round(float $value): float
    {
        return $this->rounder->round($value, $this->precision);
    }
}
