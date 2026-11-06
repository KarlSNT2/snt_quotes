<?php

declare(strict_types=1);

namespace Snt\Quotes\Calculator;

final class CalcInput
{
    /**
     * @param CalcLine[]     $lines
     * @param CalcDiscount[] $discounts remise globale + bons, dans l'ordre d'application
     */
    public function __construct(
        public readonly array $lines,
        public readonly array $discounts = [],
        public readonly float $shippingTe = 0.0,
        public readonly float $shippingTaxRate = 0.0,
        public readonly float $shippingDiscountTe = 0.0,
        public readonly bool $freeShipping = false,
        public readonly bool $taxExempt = false,
    ) {
    }
}
