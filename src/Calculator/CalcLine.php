<?php

declare(strict_types=1);

namespace Snt\Quotes\Calculator;

use Snt\Quotes\Domain\LineType;
use Snt\Quotes\Domain\ReductionType;

/**
 * Ligne en entrée du calcul (valeurs déjà validées).
 */
final class CalcLine
{
    public function __construct(
        public readonly LineType $type,
        public readonly int $qty,
        public readonly float $unitPriceTe,
        public readonly float $taxRate,
        public readonly ?ReductionType $reductionType = null,
        public readonly float $reductionValue = 0.0,
        public readonly ?float $wholesalePrice = null,
        public readonly ?float $catalogPriceTe = null,
    ) {
    }

    public static function product(int $qty, float $unitPriceTe, float $taxRate, ?ReductionType $reductionType = null, float $reductionValue = 0.0): self
    {
        return new self(LineType::Product, $qty, $unitPriceTe, $taxRate, $reductionType, $reductionValue);
    }

    public static function section(): self
    {
        return new self(LineType::Section, 0, 0.0, 0.0);
    }
}
